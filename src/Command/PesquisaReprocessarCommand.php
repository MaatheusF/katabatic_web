<?php

namespace App\Command;

use App\Entity\ConfiguracaoPesquisa;
use App\Entity\PesquisaAmostra;
use App\Entity\PesquisaCaptura;
use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\ConfiguracaoPesquisaRepository;
use App\Repository\VooRepository;
use App\Service\OpenMeteoClient;
use App\Service\PesquisaMapaCaptador;
use App\Service\PesquisaRelatorioGerador;
use App\Service\PesquisaVooAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gera, a partir de um voo real já encerrado, uma DUPLICATA sintética
 * marcada como Pesquisa — pra validar a camada de pesquisa
 * meteorológica sem tocar em nenhum voo real. Pedido em conversa:
 * "conseguimos reprocessar um voo qualquer que temos hoje como
 * pesquisa para validar o recurso?" / "não tenho nenhum voo de teste
 * atualmente, apenas voos que realmente aconteceram, seria legal o
 * comando duplicar os dados para um novo registro".
 *
 * **Nunca escreve no voo original.** Só lê a rota (`track`) e o perfil
 * de altitude (`prof`) dele. Cria um `Voo` novo — `codigo` prefixado
 * `TESTE_`, `callsign` prefixado `T-`, `status = Voo::STATUS_TESTE`
 * (mesma família de `STATUS_ACIDENTADO`: fica na tabela, mas
 * `VooRepository::countsByPilot()`/`countForPilot()`/`findRecentes
 * ComTelemetria()` já filtram só `STATUS_VALIDO`, então a duplicata
 * nunca infla horas/contagem do piloto nem aparece na home pública).
 * Aparece no Logbook do piloto (`/voo`) igual a qualquer outro voo com
 * telemetria — é assim que dá pra abrir a área científica dela.
 *
 * **O que isto NÃO é — leia antes de usar.** Não recupera o clima
 * histórico do voo original. Como documentado em `App\Service\
 * OpenMeteoClient` (e decidido na conversa que desenhou esta camada), a
 * Open-Meteo não guarda arquivo histórico por nível de pressão, e o
 * RainViewer não guarda radar histórico algum — não existe API pra
 * "que vento tinha ali naquele dia". Este comando pega a ROTA real do
 * voo original (do `track`/`prof` já gravados) e consulta o clima **ao
 * vivo, agora**, ponto a ponto, exatamente pelos mesmos serviços que o
 * heartbeat ACARS chamaria — é clima de HOJE sobre uma rota antiga, não
 * uma reconstrução do que aconteceu de verdade. A duplicata nasce com
 * `startedAt` de agora (não do voo original) — o resultado é marcado
 * `pesquisa.sintetico = true`, e a área científica mostra um aviso
 * quando essa chave está presente.
 *
 * **Cada execução cria uma duplicata NOVA.** Não há limpeza/
 * idempotência de propósito — rodar de novo pro mesmo voo original gera
 * outro registro de teste, não sobrescreve o anterior (pedido
 * explícito em conversa). As linhas de `PesquisaAmostra`/
 * `PesquisaCaptura` de cada execução ficam ancoradas no horário real de
 * quando o comando rodou, então duas execuções nunca colidem na mesma
 * janela.
 */
#[AsCommand(
    name: 'app:pesquisa:reprocessar',
    description: 'Duplica um voo encerrado como um novo voo de teste marcado Pesquisa (clima ao vivo AGORA sobre a rota real), sem tocar no voo original.',
)]
class PesquisaReprocessarCommand extends Command
{
    public function __construct(
        private readonly VooRepository $voos,
        private readonly AeronaveRepository $aeronaves,
        private readonly EntityManagerInterface $em,
        private readonly ConfiguracaoPesquisaRepository $configs,
        private readonly OpenMeteoClient $openMeteo,
        private readonly PesquisaMapaCaptador $mapaCaptador,
        private readonly PesquisaVooAggregator $aggregator,
        private readonly PesquisaRelatorioGerador $relatorioGerador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('codigo', InputArgument::REQUIRED, 'Código do voo real (nome da pasta de gravação ACARS) a duplicar como teste de Pesquisa');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codigoOriginal = (string) $input->getArgument('codigo');

        $original = $this->voos->findOneByCodigo($codigoOriginal);
        if (null === $original) {
            $io->error(sprintf('Nenhum voo com código "%s".', $codigoOriginal));

            return Command::FAILURE;
        }

        $telemetria = $original->getTelemetria();
        $track = \is_array($telemetria) && \is_array($telemetria['track'] ?? null) ? $telemetria['track'] : [];
        if ([] === $track) {
            $io->error('Este voo não tem telemetria gravada (track) — não dá pra duplicar. Escolha um voo com telemetria (os clicáveis no Logbook).');

            return Command::FAILURE;
        }
        $prof = \is_array($telemetria['prof'] ?? null) ? $telemetria['prof'] : [];

        $reg = $original->getAeronaveReg();
        $aeronave = $this->aeronaves->findOneByReg($reg);
        $tipoAeronave = null !== $aeronave ? $aeronave->getTipo() : ($telemetria['modelo'] ?? $reg);
        $tempoMin = $original->getTempoMin();

        // Âncora do voo de teste: AGORA, não o horário histórico do voo
        // original — mantém a janela de captura desta execução isolada
        // de qualquer execução anterior (nunca colide) e é coerente com
        // "sintético" (clima capturado hoje, não naquele dia). O voo
        // original nunca é tocado.
        $baseSintetico = new \DateTimeImmutable();
        $encerradoEm = $baseSintetico->modify('+'.$tempoMin.' minutes');

        $io->title(sprintf('Duplicando %s (%s, %s → %s) como voo de teste de Pesquisa', $original->getCallsign(), $reg, $original->getOrigem(), $original->getDestino()));
        $io->warning('Clima e imagens são consultados AGORA, ao vivo — não são o clima histórico real do voo original (não existe API com esse dado). O voo original não é alterado; isto cria um voo novo.');

        $config = $this->configs->obterOuCriar($this->em);

        $io->section('Amostras ambiente');
        $totalAmostras = $this->reprocessarAmostras($reg, $track, $baseSintetico, $tempoMin, $config, $io);

        if (0 === $totalAmostras) {
            $io->error('Nenhuma amostra ambiente foi gerada — a Open-Meteo pode estar fora do ar, ou nenhum ponto da rota tem coordenadas válidas.');

            return Command::FAILURE;
        }

        $io->section('Capturas de mapa/vento');
        $totalCapturas = $this->reprocessarCapturas($reg, $track, $prof, $baseSintetico, $tempoMin, $config, $io);

        $io->section('Agregando e gerando relatório');
        $pesquisa = $this->aggregator->agregar($reg, $baseSintetico, $encerradoEm);
        if (null === $pesquisa) {
            $io->error('Falha inesperada ao agregar — as amostras foram gravadas mas a busca não encontrou nada na janela.');

            return Command::FAILURE;
        }
        $pesquisa['sintetico'] = true;
        $pesquisa['reprocessadoDeCodigo'] = $codigoOriginal;
        $pesquisa['relatorio'] = $this->relatorioGerador->gerar(
            'T-'.$original->getCallsign(),
            $reg,
            $tipoAeronave,
            $original->getOrigem(),
            $original->getDestino(),
            $baseSintetico,
            $tempoMin,
            $pesquisa,
            $telemetria
        );

        $dados = $original->getDados();
        $dados['pesquisa'] = $pesquisa;
        // Não atribui o relato pessoal do piloto sobre o voo REAL a uma
        // duplicata sintética.
        unset($dados['pilotReport']);
        // Cabeçalho do relatório /voo lê `telemetria.start`, não a
        // coluna `Voo::$startedAt` diretamente (ver VooController::
        // telemetria()) - ajusta pra bater com a data "hoje" que a
        // área científica já mostra, em vez de exibir a data do voo
        // original num lugar e "hoje" no outro.
        if (isset($dados['telemetria']) && \is_array($dados['telemetria'])) {
            $dados['telemetria']['start'] = $baseSintetico->format(\DateTimeInterface::ATOM);
        }

        $codigoTeste = substr('TESTE_'.$baseSintetico->format('Ymd_His').'_'.$codigoOriginal, 0, 64);
        $callsignTeste = substr('T-'.$original->getCallsign(), 0, 16);

        // `voo.js` casa `?id=` com o voo certo pelo `id` de dentro do
        // blob (ver `TelemetryDeriver::derive()`, `'id' => $payload
        // ['codigo']`), não pela coluna `codigo` diretamente — copiado
        // do original acima, `telemetria.id` continuaria apontando pro
        // voo real (colisão: dois voos com o mesmo `id` na resposta de
        // `/voo/telemetria`, e o de teste nunca é encontrado). Precisa
        // apontar pro código NOVO da duplicata.
        if (isset($dados['telemetria']) && \is_array($dados['telemetria'])) {
            $dados['telemetria']['id'] = $codigoTeste;
        }

        $teste = new Voo(
            $original->getPilot(),
            $callsignTeste,
            'Pesquisa',
            $original->getOrigem(),
            $original->getDestino(),
            $reg,
            $baseSintetico,
            $tempoMin,
            $original->getDificuldade(),
            // Duplicata do MESMO voo/aeronave original - copia a
            // categoria em vez de recalcular (ver docblock de
            // Voo::$categoriaAeronave).
            $original->getCategoriaAeronave(),
        );
        $teste->setCodigo($codigoTeste);
        $teste->setDados($dados);
        $teste->marcarComoTeste();

        $this->em->persist($teste);
        $this->em->flush();

        $io->success(sprintf(
            "Voo de teste criado: %s (código %s)\n%d amostra(s) e %d captura(s) gravadas. Abra em /voo/%s/pesquisa (aparece no Logbook do piloto, marcado como teste — não conta nas horas/estatísticas).",
            $callsignTeste,
            $codigoTeste,
            $totalAmostras,
            $totalCapturas,
            $codigoTeste
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float}> $track
     */
    private function reprocessarAmostras(string $reg, array $track, \DateTimeImmutable $base, int $tempoMin, ConfiguracaoPesquisa $config, SymfonyStyle $io): int
    {
        $cadenciaSeg = max(60, $config->getAmostraCadenciaMin() * 60);
        $totalSeg = $tempoMin * 60;
        $io->progressStart((int) floor($totalSeg / $cadenciaSeg) + 1);

        $gravadas = 0;
        for ($offset = 0; $offset <= $totalSeg; $offset += $cadenciaSeg) {
            $ponto = $this->pontoMaisProximo($track, $offset);
            $io->progressAdvance();
            if (null === $ponto) {
                continue;
            }
            [$lat, $lon] = $ponto;

            $condicao = $this->openMeteo->condicaoAtual($lat, $lon);
            if (null === $condicao) {
                continue;
            }

            $severidade = $config->classificarSeveridade($condicao['ventoKt'], $condicao['rajadaKt'], $condicao['precipMmH']);
            $amostra = new PesquisaAmostra($reg, $base->modify('+'.$offset.' seconds'), $lat, $lon, $severidade);
            $amostra->setVentoKt($condicao['ventoKt']);
            $amostra->setVentoDir($condicao['ventoDir']);
            $amostra->setRajadaKt($condicao['rajadaKt']);
            $amostra->setTempC($condicao['tempC']);
            $amostra->setPressaoHpa($condicao['pressaoHpa']);
            $amostra->setPrecipMmH($condicao['precipMmH']);
            $amostra->setWeatherCode($condicao['weatherCode']);
            $this->em->persist($amostra);
            $this->em->flush();
            ++$gravadas;

            usleep(150_000); // cortesia com a API gratuita - ver OpenMeteoClient
        }
        $io->progressFinish();

        return $gravadas;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float}> $track
     * @param list<array{0: int, 1: ?float}>            $prof
     */
    private function reprocessarCapturas(string $reg, array $track, array $prof, \DateTimeImmutable $base, int $tempoMin, ConfiguracaoPesquisa $config, SymfonyStyle $io): int
    {
        $cadenciaSeg = max(300, $config->getCapturaCadenciaMin() * 60);
        $totalSeg = $tempoMin * 60;
        $io->progressStart((int) floor($totalSeg / $cadenciaSeg) + 1);

        $gravadas = 0;
        for ($offset = 0; $offset <= $totalSeg; $offset += $cadenciaSeg) {
            $ponto = $this->pontoMaisProximo($track, $offset);
            $io->progressAdvance();
            if (null === $ponto) {
                continue;
            }
            [$lat, $lon] = $ponto;
            $altFt = $this->altMaisProxima($prof, $offset);
            $momento = $base->modify('+'.$offset.' seconds');

            $manifesto = $this->mapaCaptador->capturar($reg, $lat, $lon, $momento, $config->getMapaGridRaio());

            // Área de vento acompanhando a MESMA configuração de raio da
            // imagem — ver `PesquisaMapaCaptador::grauPorRaioTiles()`.
            $raioGrausVento = $this->mapaCaptador->grauPorRaioTiles($config->getMapaGridRaio());
            $pontosPorEixoVento = min(2 * max(1, $config->getMapaGridRaio()) + 1, 15);
            $grade = $this->openMeteo->gradeVento($lat, $lon, $config->getNiveisPressaoHpa(), $raioGrausVento, $pontosPorEixoVento);
            if (null === $manifesto && null === $grade) {
                continue;
            }

            $captura = new PesquisaCaptura($reg, $momento, $lat, $lon);
            $captura->setAltFt($altFt);
            $captura->setManifestoMapa($manifesto);
            $captura->setGradeVento($grade);
            $this->em->persist($captura);
            $this->em->flush();
            ++$gravadas;
        }
        $io->progressFinish();

        return $gravadas;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float}> $track
     *
     * @return array{0: float, 1: float}|null
     */
    private function pontoMaisProximo(array $track, int $offsetSeg): ?array
    {
        $melhor = null;
        $melhorDist = \PHP_INT_MAX;
        foreach ($track as $p) {
            if (!\is_array($p) || \count($p) < 3) {
                continue;
            }
            $dist = abs(((int) $p[0]) - $offsetSeg);
            if ($dist < $melhorDist && is_numeric($p[1]) && is_numeric($p[2])) {
                $melhorDist = $dist;
                $melhor = [(float) $p[1], (float) $p[2]];
            }
        }

        return $melhor;
    }

    /**
     * @param list<array{0: int, 1: ?float}> $prof
     */
    private function altMaisProxima(array $prof, int $offsetSeg): ?int
    {
        $melhor = null;
        $melhorDist = \PHP_INT_MAX;
        foreach ($prof as $p) {
            if (!\is_array($p) || \count($p) < 2 || !is_numeric($p[1] ?? null)) {
                continue;
            }
            $dist = abs(((int) $p[0]) - $offsetSeg);
            if ($dist < $melhorDist) {
                $melhorDist = $dist;
                $melhor = (int) round((float) $p[1]);
            }
        }

        return $melhor;
    }
}
