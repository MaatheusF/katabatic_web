<?php

namespace App\Command;

use App\Repository\AeronaveRepository;
use App\Repository\VooRepository;
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
 * Corrige, NO PRÓPRIO REGISTRO, um voo real que foi ingerido com
 * `tipoOperacao` errado (ex.: piloto selecionou "Carga" no cliente
 * ACARS por engano, quando o voo era de Pesquisa) — caso concreto:
 * voo `20260827_041303_N208KB`, confirmado pelo piloto como Pesquisa,
 * mas gravado como Carga (`AcarsIngestaoController::ingerir()` só
 * roda a agregação de pesquisa quando `tipoOperacao === 'Pesquisa'`
 * no momento do fechamento — ver docblock de lá).
 *
 * **Diferente de `PesquisaReprocessarCommand`: não cria voo novo nem
 * consulta clima "ao vivo agora".** `PesquisaAmbienteCaptador`/
 * `PesquisaCapturaOrquestrador` gravam amostras/capturas a cada
 * heartbeat de posição pra QUALQUER aeronave em voo, independente de
 * `tipoOperacao` (que só é conhecido no fechamento) — ver
 * `AeronaveController` (heartbeat de posição). Ou seja, se o voo
 * realmente mandou heartbeats, os dados de pesquisa REAIS daquela
 * janela de tempo já existem no banco (`PesquisaAmostra`/
 * `PesquisaCaptura`), só nunca foram agregados/congelados em
 * `Voo::$dados['pesquisa']` porque o tipo estava errado na hora do
 * fechamento. Este comando só agrega o que já está gravado, corrige
 * `tipoOperacao` e congela o relatório — sem `pesquisa.sintetico`,
 * porque é dado real da época do voo, não um reprocessamento.
 *
 * Idempotente por conferência: recusa rodar se o voo já é Pesquisa
 * (nada a corrigir) ou se não encontra nenhuma amostra na janela
 * (nesse caso não há o que agregar — o heartbeat pode não ter
 * chegado a rodar a captura de pesquisa nesse voo).
 */
#[AsCommand(
    name: 'app:pesquisa:corrigir-tipo',
    description: 'Corrige um voo real ingerido com tipoOperacao errado para "Pesquisa" e agrega os dados de pesquisa já capturados ao vivo durante o próprio voo, sem criar duplicata.',
)]
class PesquisaCorrigirTipoCommand extends Command
{
    public function __construct(
        private readonly VooRepository $voos,
        private readonly AeronaveRepository $aeronaves,
        private readonly EntityManagerInterface $em,
        private readonly PesquisaVooAggregator $aggregator,
        private readonly PesquisaRelatorioGerador $relatorioGerador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('codigo', InputArgument::REQUIRED, 'Código do voo real a corrigir para tipoOperacao = Pesquisa');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codigo = (string) $input->getArgument('codigo');

        $voo = $this->voos->findOneByCodigo($codigo);
        if (null === $voo) {
            $io->error(sprintf('Nenhum voo com código "%s".', $codigo));

            return Command::FAILURE;
        }

        if ('Pesquisa' === $voo->getTipoOperacao()) {
            $io->warning(sprintf('Voo "%s" já está marcado como Pesquisa — nada a corrigir.', $codigo));

            return Command::SUCCESS;
        }

        $reg = $voo->getAeronaveReg();
        $startedAt = $voo->getStartedAt();
        $encerradoEm = $startedAt->modify('+'.$voo->getTempoMin().' minutes');

        $io->title(sprintf(
            'Corrigindo %s (%s): tipoOperacao "%s" → "Pesquisa"',
            $voo->getCallsign(),
            $codigo,
            $voo->getTipoOperacao()
        ));

        $pesquisa = $this->aggregator->agregar($reg, $startedAt, $encerradoEm);
        if (null === $pesquisa) {
            $io->error(sprintf(
                'Nenhuma amostra ambiente encontrada entre %s e %s pra "%s" — este voo pode não ter mandado heartbeats de posição durante o voo (ver PesquisaAmbienteCaptador), então não há dado real de pesquisa pra agregar. Use "app:pesquisa:reprocessar %s" se quiser um voo de teste com clima consultado agora.',
                $startedAt->format(\DateTimeInterface::ATOM),
                $encerradoEm->format(\DateTimeInterface::ATOM),
                $codigo,
                $codigo
            ));

            return Command::FAILURE;
        }

        $aeronave = $this->aeronaves->findOneByReg($reg);
        $telemetria = $voo->getTelemetria();
        $tipoAeronave = null !== $aeronave ? $aeronave->getTipo() : (string) ($telemetria['modelo'] ?? $reg);

        $pesquisa['relatorio'] = $this->relatorioGerador->gerar(
            $voo->getCallsign(),
            $reg,
            $tipoAeronave,
            $voo->getOrigem(),
            $voo->getDestino(),
            $startedAt,
            $voo->getTempoMin(),
            $pesquisa,
            \is_array($telemetria) ? $telemetria : null
        );

        $dados = $voo->getDados();
        $dados['pesquisa'] = $pesquisa;
        $voo->setDados($dados);
        $voo->setTipoOperacao('Pesquisa');

        $this->em->flush();

        $io->success(sprintf(
            "Voo \"%s\" corrigido para Pesquisa com %d amostra(s) e %d captura(s) reais da época do voo.\nAbra em /voo/%s/pesquisa — e o atalho já deve aparecer em /voo?id=%s.",
            $codigo,
            \count($pesquisa['amostras']),
            \count($pesquisa['capturas']),
            $codigo,
            $codigo
        ));

        return Command::SUCCESS;
    }
}
