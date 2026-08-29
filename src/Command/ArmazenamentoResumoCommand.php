<?php

namespace App\Command;

use App\Entity\Voo;
use App\Repository\VooRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Relatório de uso de armazenamento por voo, pra responder "quanto
 * gasto pra guardar cada voo hoje" com números reais em vez de
 * estimativa — pedido em conversa.
 *
 * Duas fontes somadas por voo:
 * - **Banco**: `strlen(json_encode($voo->getDados()))` — tamanho
 *   lógico do JSON gravado na coluna `voo.dados` (telemetria sempre;
 *   mais `pesquisa` congelado, quando é voo de Pesquisa). É o tamanho
 *   do JSON em si, não necessariamente o byte físico no disco do
 *   Postgres — `dados` é `jsonb`, que pode comprimir via TOAST quando
 *   a linha passa de ~2KB; então o número real ocupado pode ser um
 *   pouco menor que este, mas é a métrica que importa pra comparar
 *   voo simples x voo de pesquisa (o que cresce é o JSON em si).
 * - **Disco**: soma de `filesize()` de todo arquivo de imagem
 *   referenciado em `dados['pesquisa']['capturas'][*]['mapa']` (os
 *   tiles base+radar baixados e as duas imagens compostas por
 *   captura — ver `App\Service\PesquisaMapaCaptador`) — `0` pra quem
 *   não é Pesquisa. Não soma nada das tabelas `pesquisa_amostra`/
 *   `pesquisa_captura` em si (JSON pequeno, já contado dentro do
 *   "Banco" acima via o congelamento em `dados`) — só os ARQUIVOS em
 *   `public/uploads/pesquisa-captura/...`, que nunca são limpos (ver
 *   docblock de `PesquisaMapaCaptador`, bullet 3).
 */
#[AsCommand(
    name: 'app:armazenamento:resumo',
    description: 'Relatório de uso de armazenamento por voo (JSON no banco + imagens de pesquisa em disco), somado por voo e por tipoOperacao.',
)]
class ArmazenamentoResumoCommand extends Command
{
    public function __construct(
        private readonly VooRepository $voos,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $todos = $this->voos->findAll();

        if ([] === $todos) {
            $io->warning('Nenhum voo cadastrado.');

            return Command::SUCCESS;
        }

        $linhas = [];
        /** @var array<string, array{db: int, disco: int, n: int}> $porTipo */
        $porTipo = [];

        foreach ($todos as $voo) {
            $dbBytes = \strlen((string) json_encode($voo->getDados()));
            $discoBytes = $this->discoDaPesquisa($voo);
            $tipo = $voo->getTipoOperacao();

            $linhas[] = [
                $voo->getCodigo() ?? sprintf('#%d', $voo->getId()),
                $tipo,
                $voo->getTempoMin().' min',
                $this->fmt($dbBytes),
                $this->fmt($discoBytes),
                $this->fmt($dbBytes + $discoBytes),
            ];

            $porTipo[$tipo] ??= ['db' => 0, 'disco' => 0, 'n' => 0];
            $porTipo[$tipo]['db'] += $dbBytes;
            $porTipo[$tipo]['disco'] += $discoBytes;
            ++$porTipo[$tipo]['n'];
        }

        $io->title(sprintf('Armazenamento por voo (%d voos)', count($todos)));
        $io->table(['Código', 'Tipo', 'Duração', 'Banco (dados)', 'Disco (imagens pesquisa)', 'Total'], $linhas);

        $io->section('Resumo por tipo de operação');
        $resumo = [];
        $totalGeral = 0;
        foreach ($porTipo as $tipo => $s) {
            $totalTipo = $s['db'] + $s['disco'];
            $totalGeral += $totalTipo;
            $resumo[] = [
                $tipo,
                $s['n'],
                $this->fmt($s['db']),
                $this->fmt($s['disco']),
                $this->fmt((int) round($totalTipo / max(1, $s['n']))),
                $this->fmt($totalTipo),
            ];
        }
        $io->table(['Tipo', 'Voos', 'Banco total', 'Disco total', 'Média/voo', 'Total'], $resumo);
        $io->success(sprintf('Total geral: %s em %d voo(s).', $this->fmt($totalGeral), count($todos)));

        return Command::SUCCESS;
    }

    private function discoDaPesquisa(Voo $voo): int
    {
        $pesquisa = $voo->getPesquisa();
        if (null === $pesquisa || !\is_array($pesquisa['capturas'] ?? null)) {
            return 0;
        }

        $total = 0;
        foreach ($pesquisa['capturas'] as $captura) {
            $mapa = \is_array($captura) ? ($captura['mapa'] ?? null) : null;
            if (!\is_array($mapa)) {
                continue;
            }
            foreach (['imagemComposta', 'imagemMapa'] as $chave) {
                $rel = $mapa[$chave] ?? null;
                if (\is_string($rel) && '' !== $rel) {
                    $total += $this->tamanhoArquivo($rel);
                }
            }
            foreach (['tilesBase', 'tilesRadar'] as $chave) {
                $lista = \is_array($mapa[$chave] ?? null) ? $mapa[$chave] : [];
                foreach ($lista as $tile) {
                    $rel = \is_array($tile) ? ($tile['caminho'] ?? null) : null;
                    if (\is_string($rel) && '' !== $rel) {
                        $total += $this->tamanhoArquivo($rel);
                    }
                }
            }
        }

        return $total;
    }

    private function tamanhoArquivo(string $caminhoRelativo): int
    {
        $abs = $this->projectDir.'/public'.$caminhoRelativo;
        $tamanho = @filesize($abs);

        return false !== $tamanho ? $tamanho : 0;
    }

    private function fmt(int|float $bytes): string
    {
        if ($bytes < 1_048_576) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / 1_048_576, 2, ',', '.').' MB';
    }
}
