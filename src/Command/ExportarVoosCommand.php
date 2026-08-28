<?php

namespace App\Command;

use App\Repository\VooRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exporta todos os voos (todas as colunas de verdade + `Voo::$dados`
 * inteiro — telemetria incluída, quando existe) num único arquivo JSON,
 * pra análise fora do banco — pedido em conversa: sem conexão de rede
 * daqui pro Postgres local (roda em Docker na máquina do piloto, sem
 * rota de rede até este ambiente, e expor um banco local pra internet
 * só pra isso seria um risco desnecessário), então a via é rodar este
 * comando localmente e compartilhar o arquivo gerado.
 *
 * **Só leitura — nunca escreve nada no banco.** Um `findBy()` e um
 * `file_put_contents()`, nada mais.
 *
 * Formato de cada linha é achatado (sem aninhar `dados` dentro de si
 * mesmo pra tudo): as colunas de verdade (`codigo`/`callsign`/rota/
 * aeronave/tempo/dificuldade/status) ficam no nível raiz, e o resto de
 * `Voo::$dados` (telemetria completa quando existe, mais METAR/carga/
 * modelo/ocorrências — ver `TelemetriaVooBuilder`) fica dentro de
 * `dados`. Não filtra por piloto — esta rede tem um piloto só até
 * agora; se um dia tiver mais de um, `--piloto=CID` é fácil de somar
 * aqui.
 */
#[AsCommand(
    name: 'app:exportar-voos',
    description: 'Exporta todos os voos (colunas + dados/telemetria completos) em JSON, pra análise fora do banco.',
)]
class ExportarVoosCommand extends Command
{
    public function __construct(private readonly VooRepository $voos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Caminho do arquivo JSON de saída', 'var/export-voos.json')
            ->addOption('so-com-telemetria', null, InputOption::VALUE_NONE, 'Exporta só voos com telemetria de verdade (ACARS ou upload manual) — pula o histórico narrativo sem gravação');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $soComTelemetria = (bool) $input->getOption('so-com-telemetria');
        $caminho = (string) $input->getOption('saida');

        $linhas = [];
        foreach ($this->voos->findBy([], ['startedAt' => 'DESC']) as $v) {
            if ($soComTelemetria && !$v->hasTelemetria()) {
                continue;
            }

            $linhas[] = [
                'codigo' => $v->getCodigo(),
                'callsign' => $v->getCallsign(),
                'tipoOperacao' => $v->getTipoOperacao(),
                'origem' => $v->getOrigem(),
                'destino' => $v->getDestino(),
                'destinoReal' => $v->getDestinoReal(),
                'aeronaveReg' => $v->getAeronaveReg(),
                'startedAt' => $v->getStartedAt()->format(\DATE_ATOM),
                'tempoMin' => $v->getTempoMin(),
                'dificuldade' => $v->getDificuldade(),
                'status' => $v->getStatus(),
                'temTelemetria' => $v->hasTelemetria(),
                // 'dados' já inclui a chave 'telemetria' inteira quando
                // existe (track/prof/env/eventos/fases/parcelas/toque) -
                // ver docblock de `App\Entity\Voo` - então isto sozinho
                // já é o voo inteiro, sem precisar buscar mais nada.
                'dados' => $v->getDados(),
            ];
        }

        $json = json_encode($linhas, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            $io->error('Falha ao converter os voos pra JSON: '.json_last_error_msg());

            return Command::FAILURE;
        }

        $dir = \dirname($caminho);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $io->error(sprintf('Não consegui criar a pasta "%s".', $dir));

            return Command::FAILURE;
        }

        file_put_contents($caminho, $json);

        $comTelemetria = count(array_filter($linhas, static fn (array $l) => $l['temTelemetria']));
        $io->success(sprintf(
            '%d voo(s) exportado(s) para %s (%d com telemetria de verdade).',
            count($linhas),
            $caminho,
            $comTelemetria
        ));

        return Command::SUCCESS;
    }
}
