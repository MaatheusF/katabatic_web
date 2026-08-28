<?php

namespace App\Command;

use App\Entity\Aeroporto;
use App\Repository\AeroportoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa pra tabela `aeroporto` as 11 entradas que existiam no
 * catálogo fixo `public/assets/data/airports.json` — mesmo padrão do
 * `app:importar-frota-legada`: dado de seed (que agora pode crescer via
 * `AeroportoController`, admin-only) fica num comando, não amarrado ao
 * histórico de migrations.
 *
 * **Atualizado: religa a aba Bases do Portal.** As outras 9 entradas
 * (fora PAFA/SCCI, que são as próprias bases) agora entram com
 * `postoAvancadoDe` já preenchido — não é dado novo/inventado, é
 * exatamente a classificação "estação avançada de PAFA/SCCI" que
 * `PortalController::bases()` sempre teve hardcoded (5 em PABT/PFYU/
 * PAKP/PASC/PAOT pra PAFA, 4 em SCNT/SCGZ/SCFM/SCBA pra SCCI) antes
 * dessa fatia existir — só estava solta no controller, não no catálogo.
 * Agora que a tela lê do catálogo (`AeroportoRepository::findPostosAvancadosDe()`),
 * precisava estar aqui.
 *
 * Idempotente por padrão (dedup por `icao`, que também tem UNIQUE INDEX
 * na migration) — **mas com um backfill**: quem já rodou este comando
 * antes desta atualização (aeroporto existe, `postoAvancadoDe` ainda
 * `null`) tem o campo preenchido na re-execução, sem duplicar a linha.
 * Isso nunca sobrescreve um valor que já foi setado (seja por uma
 * execução anterior deste comando, seja por um admin cadastrando um
 * ICAO novo em `/aeroportos` — hoje não dá pra editar um aeroporto já
 * existente pela tela, então esse backfill é o único jeito de corrigir
 * uma linha legada sem mexer direto no banco).
 */
#[AsCommand(
    name: 'app:importar-aeroportos-legado',
    description: 'Importa as 11 entradas do antigo airports.json pra tabela aeroporto.',
)]
class ImportarAeroportosLegadoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AeroportoRepository $aeroportos,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->catalogoLegado() as $icao => $row) {
            $existente = $this->aeroportos->findOneByIcao($icao);

            if (null !== $existente) {
                // Backfill: se essa linha já existia de uma execução
                // anterior (antes do postoAvancadoDe entrar no seed) e
                // ainda está null, preenche agora - ver docblock da
                // classe. Nunca sobrescreve um valor já setado.
                if (null === $existente->getPostoAvancadoDe() && null !== ($row['postoAvancadoDe'] ?? null)) {
                    $existente->setPostoAvancadoDe($row['postoAvancadoDe']);
                    ++$updated;
                } else {
                    ++$skipped;
                }
                continue;
            }

            $aeroporto = new Aeroporto($icao, $row['name'], $row['city'], $row['lat'], $row['lon']);
            $aeroporto->setPostoAvancadoDe($row['postoAvancadoDe'] ?? null);
            $this->em->persist($aeroporto);
            ++$created;
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d aeroporto(s) importado(s), %d atualizado(s) (posto avançado preenchido), %d já estava(m) em dia (pulado).',
            $created,
            $updated,
            $skipped
        ));

        return Command::SUCCESS;
    }

    /**
     * Mesmos 11 registros e valores exatos que `airports.json` sempre
     * teve, mais `postoAvancadoDe` — ver docblock da classe.
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string}>
     */
    private function catalogoLegado(): array
    {
        return [
            'PAFA' => ['name' => 'Fairbanks Intl.', 'city' => 'Fairbanks, Alasca', 'lat' => 64.8151, 'lon' => -147.8560, 'postoAvancadoDe' => null],
            'PABT' => ['name' => 'Bettles', 'city' => 'Bettles, Alasca', 'lat' => 66.9139, 'lon' => -151.5292, 'postoAvancadoDe' => 'PAFA'],
            'PFYU' => ['name' => 'Fort Yukon', 'city' => 'Fort Yukon, Alasca', 'lat' => 66.5722, 'lon' => -145.2500, 'postoAvancadoDe' => 'PAFA'],
            'PAKP' => ['name' => 'Anaktuvuk Pass', 'city' => 'Anaktuvuk Pass, Alasca', 'lat' => 68.1339, 'lon' => -151.7431, 'postoAvancadoDe' => 'PAFA'],
            'PASC' => ['name' => 'Deadhorse', 'city' => 'Deadhorse, Alasca', 'lat' => 70.1946, 'lon' => -148.4653, 'postoAvancadoDe' => 'PAFA'],
            'PAOT' => ['name' => 'Ralph Wien Memorial', 'city' => 'Kotzebue, Alasca', 'lat' => 66.8847, 'lon' => -162.6389, 'postoAvancadoDe' => 'PAFA'],
            'SCCI' => ['name' => 'Pdte. Carlos Ibáñez del Campo', 'city' => 'Punta Arenas, Chile', 'lat' => -53.0026, 'lon' => -70.8546, 'postoAvancadoDe' => null],
            'SCNT' => ['name' => 'Teniente Julio Gallardo', 'city' => 'Puerto Natales, Chile', 'lat' => -51.6706, 'lon' => -72.5286, 'postoAvancadoDe' => 'SCCI'],
            'SCGZ' => ['name' => 'Guardiamarina Zañartu', 'city' => 'Puerto Williams, Chile', 'lat' => -54.9317, 'lon' => -67.6262, 'postoAvancadoDe' => 'SCCI'],
            'SCFM' => ['name' => 'Porvenir', 'city' => 'Porvenir, Chile', 'lat' => -53.2569, 'lon' => -70.3925, 'postoAvancadoDe' => 'SCCI'],
            'SCBA' => ['name' => 'Teniente Vidal', 'city' => 'Balmaceda, Chile', 'lat' => -45.9159, 'lon' => -71.6897, 'postoAvancadoDe' => 'SCCI'],
        ];
    }
}
