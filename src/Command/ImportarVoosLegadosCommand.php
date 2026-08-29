<?php

namespace App\Command;

use App\Entity\TipoAeronave;
use App\Entity\Voo;
use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importa pra tabela `voo` os registros que existiam como mock antes
 * desta fatia de backend: os 11 voos hardcoded em
 * `PortalController::logbook()` (git log tem a versão anterior do
 * arquivo) e a telemetria real de `public/assets/data/flights.json`
 * pros 3 que já tinham `flightId`.
 *
 * Não virou uma migration de `INSERT` porque a telemetria dos 3 voos
 * reais soma ~70 KB de JSON — transcrever isso à mão dentro de uma
 * migration seria arriscado (um erro de cópia quebraria o parse do
 * gráfico sem dar nenhum erro de SQL). Este comando lê
 * `flights.json` direto do disco, então o dado nunca é redigitado.
 *
 * Idempotente: roda de novo sem duplicar (dedup por `codigo` nos 3
 * com telemetria, por `callsign`+`startedAt` nos outros).
 *
 * IMPORTANTE — preço documentado de misturar mock com dado real (ver
 * git log / README): os 3 voos com telemetria de verdade foram
 * gravados como voos de teste locais perto de Yakutsk (UEEE→UEEE, ~10
 * minutos, ~9-13 nm) — nada a ver com a rota/duração/distância
 * narrativas que o Logbook sempre mostrou pra eles (ex.: "Fairbanks →
 * Bettles", 52 minutos, 168 nm). Este import PRESERVA essa
 * inconsistência de propósito (é a mesma que já existia antes, só
 * que agora num banco de verdade em vez de dois arquivos PHP/JSON
 * separados) — resolve sozinha quando a ingestão real do ACARS
 * substituir estes dados de desenvolvimento por voos onde telemetria
 * e plano de voo são a mesma coisa.
 */
#[AsCommand(
    name: 'app:importar-voos-legados',
    description: 'Importa os voos mock do Logbook + a telemetria de flights.json pra tabela voo.',
)]
class ImportarVoosLegadosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PilotRepository $pilots,
        private readonly VooRepository $voos,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Todo o histórico mock sempre foi voado pelo piloto semeado pela
        // migration de Login (CID 1234567) - não existe outro piloto de
        // desenvolvimento pra atribuir isso corretamente, e não é o papel
        // deste import inventar um.
        $pilot = $this->pilots->findOneByCid('1234567');
        if (null === $pilot) {
            $io->error('Piloto de desenvolvimento (CID 1234567) não encontrado - rode as migrations antes.');

            return Command::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->voosComTelemetria() as $row) {
            if (null !== $this->voos->findOneByCodigoForPilot($row['codigo'], $pilot)) {
                ++$skipped;
                continue;
            }

            // tempoMin/dif usam os valores narrativos do Logbook (não
            // `telemetria.dur`/`telemetria.score`) de propósito - ver o
            // aviso "IMPORTANTE" no topo da classe. A telemetria em si
            // (com a duração/score REAIS) continua intacta dentro de
            // `dados['telemetria']` e é isso que a tela `/voo` mostra;
            // só o Logbook (nesta tabela, coluna de verdade) mantém a
            // narrativa que sempre teve, pra não virar um "0:06" numa
            // suposta perna de carga Fairbanks→Bettles de 168 nm.
            $voo = new Voo(
                $pilot,
                $row['callsign'],
                $row['tipo'],
                $row['origem'],
                $row['destino'],
                $row['aeronave'],
                new \DateTimeImmutable($row['telemetria']['start']),
                $row['tempoMin'],
                $row['dif'],
                // Seed histórico anterior ao conceito de categoria de
                // aeronave - toda a frota mock era avião (ver docblock
                // de Voo::$categoriaAeronave).
                TipoAeronave::CATEGORIA_AVIAO,
            );
            $voo->setCodigo($row['codigo']);
            $voo->setDados([
                'rota' => $row['rota'],
                'modelo' => $row['modelo'],
                'cond' => $row['cond'],
                'condTag' => $row['condTag'],
                'ocorrencias' => $row['ocorrencias'],
                'dist' => $row['dist'],
                'combustivelKg' => $row['combustivelKg'],
                'carga' => $row['carga'],
                'tempoSoloMin' => $row['tempoSoloMin'],
                'tempoArMin' => $row['tempoArMin'],
                'metar' => $row['metar'],
                'pilotReport' => null,
                'telemetria' => $row['telemetria'],
            ]);
            $this->em->persist($voo);
            ++$created;
        }

        foreach ($this->voosNarrativos() as $row) {
            $startedAt = new \DateTimeImmutable($row['data'].' '.substr($row['hora'], 0, 5).':00');
            if ($this->voos->existsForPilotCallsignAndStart($pilot, $row['callsign'], $startedAt)) {
                ++$skipped;
                continue;
            }

            $voo = new Voo(
                $pilot,
                $row['callsign'],
                $row['tipo'],
                $row['origem'],
                $row['destino'],
                $row['aeronave'],
                $startedAt,
                $row['tempoMin'],
                $row['dif'],
                // Seed histórico anterior ao conceito de categoria de
                // aeronave - toda a frota mock era avião (ver docblock
                // de Voo::$categoriaAeronave).
                TipoAeronave::CATEGORIA_AVIAO,
            );
            $voo->setDados([
                'rota' => $row['rota'],
                'modelo' => $row['modelo'],
                'cond' => $row['cond'],
                'condTag' => $row['condTag'],
                'ocorrencias' => $row['ocorrencias'],
                'dist' => $row['dist'],
                'combustivelKg' => $row['combustivelKg'],
                'carga' => $row['carga'],
                'tempoSoloMin' => $row['tempoSoloMin'],
                'tempoArMin' => $row['tempoArMin'],
                'metar' => $row['metar'],
                'pilotReport' => null,
            ]);
            $this->em->persist($voo);
            ++$created;
        }

        $this->em->flush();

        $io->success(sprintf('%d voo(s) importado(s), %d já existia(m) (pulado).', $created, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Os 3 voos que já tinham `flightId` no mock antigo — narrativa
     * (callsign/rota/aeronave/etc., idêntica ao que
     * `PortalController::logbook()` tinha) + a telemetria de verdade
     * lida direto de `flights.json` (casada pelo `codigo`/`flightId`).
     *
     * @return list<array<string, mixed>>
     */
    private function voosComTelemetria(): array
    {
        $path = $this->projectDir.'/public/assets/data/flights.json';
        $telemetrias = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $porCodigo = [];
        foreach ($telemetrias as $t) {
            $porCodigo[$t['id']] = $t;
        }

        $narrativa = [
            '20260819_033457_KBT118' => ['callsign' => 'KBT118', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PABT', 'rota' => 'Fairbanks → Bettles', 'aeronave' => 'N208KB', 'modelo' => 'C208', 'tempoMin' => 52, 'cond' => 'Neve · -20 °C', 'condTag' => 'bad', 'ocorrencias' => [['label' => 'Overspeed', 'tag' => 'warn'], ['label' => 'Quique', 'tag' => 'warn']], 'dif' => 81, 'dist' => 168, 'combustivelKg' => 130, 'carga' => '310 kg de carga geral', 'tempoSoloMin' => 6, 'tempoArMin' => 46, 'metar' => 'PAFA 190334Z 03014KT 3/4SM SN BKN008 M20/M22 A2978'],
            '20260819_032837_KBT118' => ['callsign' => 'KBT412', 'tipo' => 'Pesquisa', 'origem' => 'SCCI', 'destino' => 'SCNT', 'rota' => 'Punta Arenas → Puerto Natales', 'aeronave' => 'CC-KBA', 'modelo' => 'DHC6', 'tempoMin' => 64, 'cond' => 'Chuva · em nuvem', 'condTag' => 'warn', 'ocorrencias' => [], 'dif' => 74, 'dist' => 130, 'combustivelKg' => 245, 'carga' => 'Equipe de pesquisa (2 pax) + amostras', 'tempoSoloMin' => 7, 'tempoArMin' => 57, 'metar' => 'SCCI 190328Z 24018G28KT 4SM -RA BKN015 OVC025 06/04 A2985'],
            '20260819_032200_KBT118' => ['callsign' => 'KBT207', 'tipo' => 'Pessoal', 'origem' => 'PAFA', 'destino' => 'PASC', 'rota' => 'Fairbanks → Deadhorse', 'aeronave' => 'N67KB', 'modelo' => 'BE20', 'tempoMin' => 108, 'cond' => 'Claro · seco', 'condTag' => 'ok', 'ocorrencias' => [['label' => 'Quique', 'tag' => 'warn']], 'dif' => 29, 'dist' => 373, 'combustivelKg' => 504, 'carga' => '3 pax', 'tempoSoloMin' => 9, 'tempoArMin' => 99, 'metar' => 'PAFA 190322Z 32006KT 10SM SKC M06/M12 A3005'],
        ];

        $rows = [];
        foreach ($narrativa as $codigo => $row) {
            if (!isset($porCodigo[$codigo])) {
                continue;
            }
            $row['codigo'] = $codigo;
            $row['telemetria'] = $porCodigo[$codigo];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Os outros 8 voos do mock antigo, sem telemetria gravada (`flightId`
     * era `null`) - só entram como linha de histórico no Logbook, sem
     * relatório de voo clicável (mesma regra de antes, ver
     * `portal.js`/`VooRepository::findComTelemetriaForPilot()`).
     *
     * @return list<array<string, mixed>>
     */
    private function voosNarrativos(): array
    {
        return [
            //['data' => '2026-08-17', 'hora' => '21:10Z', 'callsign' => 'KBT903', 'tipo' => 'Reposicionamento', 'origem' => 'SCCI', 'destino' => 'SCBA', 'rota' => 'Punta Arenas → Balmaceda', 'aeronave' => 'CC-KBD', 'modelo' => 'PC6', 'tempoMin' => 157, 'cond' => 'Vento 41G56', 'condTag' => 'warn', 'ocorrencias' => [], 'dif' => 66, 'dist' => 432, 'combustivelKg' => 366, 'carga' => 'Sem carga — voo de posicionamento', 'tempoSoloMin' => 8, 'tempoArMin' => 149, 'metar' => 'SCCI 172110Z 28041G56KT 9999 FEW030 03/M01 A2971'],
           // ['data' => '2026-08-15', 'hora' => '14:02Z', 'callsign' => 'KBT118', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PFYU', 'rota' => 'Fairbanks → Fort Yukon', 'aeronave' => 'N412KB', 'modelo' => 'DHC2', 'tempoMin' => 71, 'cond' => 'Gelo leve', 'condTag' => 'warn', 'ocorrencias' => [['label' => 'Pouso duro', 'tag' => 'bad']], 'dif' => 58, 'dist' => 122, 'combustivelKg' => 95, 'carga' => '260 kg de correio e suprimentos', 'tempoSoloMin' => 5, 'tempoArMin' => 66, 'metar' => 'PAFA 151402Z 01009KT 2SM -FZDZ BR OVC006 M03/M05 A2996'],
           // ['data' => '2026-08-14', 'hora' => '09:47Z', 'callsign' => 'KBT412', 'tipo' => 'Pesquisa', 'origem' => 'SCCI', 'destino' => 'SCGZ', 'rota' => 'Punta Arenas → Puerto Williams', 'aeronave' => 'CC-KBA', 'modelo' => 'DHC6', 'tempoMin' => 82, 'cond' => 'Turbulência severa', 'condTag' => 'bad', 'ocorrencias' => [], 'dif' => 88, 'dist' => 150, 'combustivelKg' => 314, 'carga' => 'Equipe de pesquisa (4 pax) + equipamento de campo', 'tempoSoloMin' => 6, 'tempoArMin' => 76, 'metar' => 'SCCI 140947Z VRB25G38KT 6SM TSRA BKN020CB 08/05 A2979'],
           // ['data' => '2026-08-09', 'hora' => '18:15Z', 'callsign' => 'KBT205', 'tipo' => 'Pessoal', 'origem' => 'PAFA', 'destino' => 'PAOT', 'rota' => 'Fairbanks → Kotzebue', 'aeronave' => 'N67KB', 'modelo' => 'BE20', 'tempoMin' => 125, 'cond' => 'Vento moderado', 'condTag' => 'warn', 'ocorrencias' => [], 'dif' => 41, 'dist' => 380, 'combustivelKg' => 583, 'carga' => '2 pax', 'tempoSoloMin' => 8, 'tempoArMin' => 117, 'metar' => 'PAFA 091815Z 25022G31KT 9999 SCT045 M02/M07 A2999'],
           // ['data' => '2026-08-06', 'hora' => '12:40Z', 'callsign' => 'KBT144', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PABT', 'rota' => 'Fairbanks → Bettles', 'aeronave' => 'N208KB', 'modelo' => 'C208', 'tempoMin' => 48, 'cond' => 'Claro · frio', 'condTag' => 'ok', 'ocorrencias' => [], 'dif' => 24, 'dist' => 168, 'combustivelKg' => 120, 'carga' => '180 kg de carga geral', 'tempoSoloMin' => 5, 'tempoArMin' => 43, 'metar' => 'PABT 061240Z 03005KT 10SM SKC M09/M15 A3011'],
           // ['data' => '2026-08-02', 'hora' => '20:05Z', 'callsign' => 'KBT955', 'tipo' => 'Reposicionamento', 'origem' => 'SCCI', 'destino' => 'SCFM', 'rota' => 'Punta Arenas → Porvenir', 'aeronave' => 'CC-KBC', 'modelo' => 'C208', 'tempoMin' => 31, 'cond' => 'Vento cruzado forte', 'condTag' => 'warn', 'ocorrencias' => [['label' => 'Pouso duro', 'tag' => 'bad']], 'dif' => 63, 'dist' => 22, 'combustivelKg' => 78, 'carga' => 'Sem carga — voo de posicionamento', 'tempoSoloMin' => 4, 'tempoArMin' => 27, 'metar' => 'SCCI 022005Z 27035G47KT 9999 FEW040 05/02 A2988'],
           // ['data' => '2026-07-27', 'hora' => '10:12Z', 'callsign' => 'KBT441', 'tipo' => 'Pesquisa', 'origem' => 'SCBA', 'destino' => 'SCGZ', 'rota' => 'Balmaceda → Puerto Williams', 'aeronave' => 'CC-KBD', 'modelo' => 'PC6', 'tempoMin' => 178, 'cond' => 'Turbulência moderada', 'condTag' => 'warn', 'ocorrencias' => [], 'dif' => 70, 'dist' => 340, 'combustivelKg' => 415, 'carga' => 'Equipe de pesquisa (3 pax) + amostras geológicas', 'tempoSoloMin' => 7, 'tempoArMin' => 171, 'metar' => 'SCBA 271012Z 30028G40KT 8SM BKN018 01/M02 A2976'],
          //  ['data' => '2026-07-19', 'hora' => '15:30Z', 'callsign' => 'KBT128', 'tipo' => 'Carga', 'origem' => 'PFYU', 'destino' => 'PAKP', 'rota' => 'Fort Yukon → Anaktuvuk Pass', 'aeronave' => 'N412KB', 'modelo' => 'DHC2', 'tempoMin' => 95, 'cond' => 'Gelo leve · vento 22G34', 'condTag' => 'bad', 'ocorrencias' => [['label' => 'Overspeed', 'tag' => 'warn']], 'dif' => 77, 'dist' => 140, 'combustivelKg' => 127, 'carga' => '340 kg de carga perecível', 'tempoSoloMin' => 6, 'tempoArMin' => 89, 'metar' => 'PFYU 191530Z 02022G34KT 1SM -SN BR BKN007 M14/M18 A2969'],
        ];
    }
}
