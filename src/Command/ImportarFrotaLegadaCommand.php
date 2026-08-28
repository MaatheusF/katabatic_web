<?php

namespace App\Command;

use App\Entity\Aeronave;
use App\Repository\AeronaveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa pra tabela `aeronave` as 6 aeronaves que existiam como mock
 * antes desta fatia de backend — hardcoded em quatro lugares diferentes
 * (`PortalController::fleet()`, `NovoVooController::aircraftFleet()`,
 * `AeronaveController::fleet()` e `MapaAoVivoController`, ver git log
 * pras versões anteriores desses arquivos).
 *
 * Não virou uma migration de `INSERT` pra manter o mesmo padrão do
 * `app:importar-voos-legados`: dado de seed (que pode mudar de nome ou
 * crescer) fica num comando, não amarrado ao histórico de migrations.
 *
 * `pais` não existia como campo solto no mock antigo — é derivado aqui
 * do prefixo da matrícula (`CC-` → `CL`, senão → `US`), já que é
 * exatamente essa lógica que `NovaAeronaveController::PREFIXOS` usa no
 * cadastro de aeronaves novas.
 *
 * Idempotente: roda de novo sem duplicar (dedup por `reg`, que também
 * tem UNIQUE INDEX na migration).
 */
#[AsCommand(
    name: 'app:importar-frota-legada',
    description: 'Importa as 6 aeronaves mock (PortalController/NovoVooController/AeronaveController/MapaAoVivoController) pra tabela aeronave.',
)]
class ImportarFrotaLegadaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AeronaveRepository $aeronaves,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = 0;
        $skipped = 0;

        foreach ($this->frotaLegada() as $row) {
            if ($this->aeronaves->existsByReg($row['reg'])) {
                ++$skipped;
                continue;
            }

            $pais = str_starts_with($row['reg'], 'CC-') ? 'CL' : 'US';

            $aeronave = new Aeronave($row['reg'], $pais, $row['tipo'], $row['base']);
            $aeronave->setPosIcao($row['pos']);
            $aeronave->setStatus($row['status']);
            $aeronave->setHoras($row['horas']);

            $this->em->persist($aeronave);
            ++$created;
        }

        $this->em->flush();

        $io->success(sprintf('%d aeronave(s) importada(s), %d já existia(m) (pulada).', $created, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Mesmos 6 registros e valores exatos que os quatro mocks antigos
     * compartilhavam (reg/tipo/base/pos/status/horas) — ver docblock da
     * classe.
     *
     * @return list<array{reg: string, tipo: string, base: string, pos: string, status: string, horas: int}>
     */
    private function frotaLegada(): array
    {
        return [
            ['reg' => 'CC-KBA', 'tipo' => 'DHC-6 Twin Otter 300', 'base' => 'SCCI', 'pos' => 'SCNT', 'status' => 'Em voo', 'horas' => 318],
            ['reg' => 'CC-KBC', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'pos' => 'SCCI', 'status' => 'Disponível', 'horas' => 204],
            ['reg' => 'CC-KBD', 'tipo' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'pos' => 'SCBA', 'status' => 'Fora de base', 'horas' => 96],
            ['reg' => 'N208KB', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'PAFA', 'pos' => 'PABT', 'status' => 'Em voo', 'horas' => 412],
            ['reg' => 'N412KB', 'tipo' => 'DHC-2 Beaver', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'horas' => 147],
            ['reg' => 'N67KB', 'tipo' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'horas' => 89],
        ];
    }
}
