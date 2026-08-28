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
 * Semeia as "bases sazonais" — quatro bases principais novas, cada uma
 * com 3 estações avançadas de verdade ao redor dela, mais um posto
 * avançado isolado — pedidas pra dar à rede locais desafiadores, com
 * paisagens de tirar o fôlego, em continentes que PAFA (Alasca) e SCCI
 * (Patagônia) sozinhos não cobrem. Mesmo padrão de
 * `app:importar-aeroportos-legado` (dado de seed hardcoded num comando,
 * não amarrado ao histórico de migrations) e mesmo espírito idempotente
 * de `app:importar-frota-legada` — dedup por `icao`, roda de novo sem
 * duplicar.
 *
 * **"Sazonal" aqui é só tema/identidade — decisão tomada em conversa.**
 * O pedido original citava bases "sazonais" no sentido de terem uma
 * janela de calendário (ex.: só operar em certos meses), mas isso NÃO
 * foi implementado: não existe nenhum campo de data/temporada em
 * `Aeroporto`, nenhuma trava de agendamento por mês, nada que impeça um
 * voo de/pra qualquer uma destas entradas em qualquer época do ano.
 * "Sazonal" descreve só a ambientação (locais extremos, remotos,
 * visualmente radicais) — o mesmo sentido que PAFA/SCCI já carregam
 * como "as duas bases geladas/de fim de mundo" sem nenhuma trava por
 * trás. Se uma janela de calendário de verdade for pedida no futuro, é
 * trabalho novo, não uma extensão trivial deste comando.
 *
 * **Correção: Lukla não é hub, é destino — decisão tomada em conversa.**
 * A primeira versão deste comando usava VNLK (Tenzing-Hillary, Lukla)
 * como a base nova do Nepal. Errado: Lukla é uma pista curta em rampa
 * (~527 m, ~12% de inclinação), de mão única, sem approach
 * instrumentado e sem infraestrutura pra basear frota — na aviação real
 * ninguém "opera de Lukla", os voos SEMPRE partem de Catmandu e pousam
 * em Lukla, nunca o contrário. A base de verdade do Nepal virou
 * **VNKT** (Tribhuvan Intl., Catmandu) — aeroporto internacional cheio,
 * com toda a infraestrutura que uma base precisa — e Lukla entrou como
 * o que ela sempre foi na prática: um posto avançado desafiador de
 * VNKT, ao lado de outras duas pistas de altitude do Nepal (ver tabela
 * abaixo). PAFA/SCCI/SLLP/WAJW/VQPR já eram (ou continuam sendo,
 * depois de revisados) hubs de verdade — aeroporto com pista e
 * infraestrutura suficiente pra basear frota, não só um destino de mão
 * única — então não precisaram dessa mesma correção:
 *
 * - **SLLP** (El Alto Intl., La Paz) já é o maior aeroporto internacional
 *   da Bolívia — hub de verdade, mesmo em ~4 061 m de altitude.
 * - **WAJW** (Wamena) já é o hub aéreo de fato de todo o planalto central
 *   de Papua — pista pavimentada, capaz de jato, de onde dezenas de
 *   pistas menores da região são abastecidas.
 * - **VQPR** (Paro) é a ÚNICA porta de entrada aérea do Butão — todo
 *   tráfego internacional (e a maior parte do doméstico) passa por lá,
 *   com infraestrutura pra operar a frota inteira da Drukair.
 *
 * **As quatro bases principais** (mesma tier de PAFA/SCCI — entram em
 * `AeroportoRepository::BASES`/`NovaAeronaveController::BASES_VALIDAS`/
 * `AeroportoController::BASES_VALIDAS`/`AdesaoController::VALID_BASE_PREF`,
 * ver docblock de cada uma), cada uma agora com 3 estações avançadas
 * reais da própria região (`postoAvancadoDe` já preenchido, aparecem
 * direto na aba Bases do Portal via `PortalController::postosAvancados()`
 * — mesmo mecanismo que PAFA/SCCI já usavam pros 9 postos legados):
 *
 * - **SLLP** — El Alto Intl., La Paz, Bolívia. Andes, ~4 061 m de
 *   altitude (um dos aeroportos internacionais mais altos do mundo) —
 *   ar rarefeito penaliza performance de decolagem/pouso de verdade.
 *   Postos: **SLUY** (Uyuni — Salar de Uyuni, ~3 394 m), **SLRQ**
 *   (Rurrenabaque — portal da Amazônia/Madidi, ~206 m, contraste total
 *   de altitude) e **SLAP** (Apolo — transição Andes/Amazônia).
 * - **VNKT** — Tribhuvan Intl., Catmandu, Nepal. Hub de verdade do
 *   Himalaia nepalês. Postos: **VNLK** (Lukla — portal do Everest,
 *   pista em rampa sem chance de arremetida, ver correção acima),
 *   **VNJS** (Jomsom — ~2 736 m, vento de vale forte e previsível) e
 *   **VNPR** (Pokhara — aeroporto internacional novo, base pro circuito
 *   do Annapurna).
 * - **WAJW** — Wamena, Papua (Nova Guiné/Indonésia). Vale do Baliem, a
 *   ~1 655 m, cercado por picos de mais de 4 000 m — aproximação visual
 *   obrigatória, tempo convectivo que fecha rápido. Postos: **WAJO**
 *   (Oksibil — ~1 315 m, cercado de montanhas perto da fronteira com
 *   Papua-Nova Guiné), **WAYE** (Enarotali — à beira do Lago Paniai,
 *   ~1 769 m) e **WAJB** (Bokondini — ~1 400 m, planalto).
 * - **VQPR** — Paro, Butão. Vale cercado por picos do Himalaia de até
 *   ~5 500 m — uma das aproximações mais tecnicamente exigentes do
 *   mundo, só visual, historicamente restrita a um punhado de pilotos
 *   certificados. Postos: **VQBT** (Bathpalathang/Jakar — vale central
 *   de Bumthang, ~2 586 m), **VQGP** (Gelephu — planície do sul, na
 *   fronteira com a Índia, ~299 m, contraste de altitude) e **VQTY**
 *   (Yongphulla/Trashigang — leste remoto, ~2 743 m).
 *
 * **BGSF** (Kangerlussuaq, Groenlândia) continua diferente das quatro
 * acima — entra com `postoAvancadoDe = 'PAFA'` (posto avançado, não
 * base nova), exatamente como pedido ("sub base do Alasca"). Fica de
 * fora de `BASES_VALIDAS` em todo lugar de propósito — mesma regra que
 * já vale pros outros postos avançados (PABT/PFYU/etc.): não é
 * selecionável como base de aeronave nem preferência de piloto, só
 * aparece com a nota nos popups do mapa e como "estação avançada" de
 * PAFA na aba Bases do Portal.
 *
 * Coordenadas de referência pública (aeródromo, não pista específica,
 * checadas contra Wikipédia/OurAirports/SkyVector) — mesmo nível de
 * precisão dos 11 aeroportos hand-cadastrados legados, não survey-grade.
 *
 * **Backfill genérico, não só pra Lukla.** `execute()` corrige
 * `postoAvancadoDe` em QUALQUER uma das 17 linhas que já exista no
 * banco sem esse campo preenchido — não só VNLK. Bug encontrado em
 * conversa: a primeira versão desta correção só tratava o caso
 * específico da Lukla; na prática, um posto (ex.: SLUY) pode acabar no
 * banco sem `postoAvancadoDe` por outros caminhos também — um cadastro
 * manual em `/aeroportos` antes deste comando ter rodado a versão com
 * os postos, por exemplo — e ficava com o campo vazio pra sempre,
 * mesmo rodando o comando de novo, porque o `if` só reconhecia 'VNLK'.
 * Rodar `app:importar-bases-sazonais` de novo agora conserta qualquer
 * uma dessas linhas, sem precisar mexer no banco direto - só nunca
 * sobrescreve um `postoAvancadoDe` que já esteja preenchido (pode ser
 * intencional, de um admin).
 */
#[AsCommand(
    name: 'app:importar-bases-sazonais',
    description: 'Semeia as bases sazonais (SLLP/VNKT/WAJW/VQPR como bases novas, cada uma com 3 postos avançados, mais BGSF como posto avançado de PAFA) e corrige postoAvancadoDe faltando em qualquer linha já existente.',
)]
class ImportarBasesSazonaisCommand extends Command
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
        $corrected = 0;
        $skipped = 0;

        foreach ($this->basesSazonais() as $icao => $row) {
            $existente = $this->aeroportos->findOneByIcao($icao);

            if (null !== $existente) {
                // Backfill pra qualquer entrada que já exista no banco
                // sem postoAvancadoDe ainda preenchido - não só VNLK
                // (Lukla). Cobre tanto quem rodou a versão antiga deste
                // comando (só tinha VNLK como base, sem os outros 11
                // postos) quanto qualquer posto que tenha entrado no
                // catálogo por outro caminho (ex.: cadastro manual em
                // /aeroportos antes deste comando rodar, ou uma corrida
                // anterior deste comando que não tenha persistido o
                // campo por algum motivo) - mesmo espírito de
                // `ImportarAeroportosLegadoCommand::execute()`. **Nunca
                // sobrescreve** um valor que já esteja preenchido -
                // pode ser um admin tendo mudado isso de propósito em
                // /aeroportos, essa decisão não é nossa de reverter.
                $esperado = $row['postoAvancadoDe'] ?? null;
                if (null !== $esperado && null === $existente->getPostoAvancadoDe()) {
                    $existente->setPostoAvancadoDe($esperado);
                    ++$corrected;
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
            '%d base(s)/posto(s) sazonal(is) importado(s), %d corrigido(s) (postoAvancadoDe preenchido em linha que já existia), %d já estava(m) em dia (pulado).',
            $created,
            $corrected,
            $skipped
        ));

        return Command::SUCCESS;
    }

    /**
     * Ver docblock da classe pro porquê de cada entrada e pra fonte das
     * coordenadas (dado público de referência, não survey-grade).
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string}>
     */
    private function basesSazonais(): array
    {
        return [
            // --- Bolívia: hub + 3 postos avançados ---
            'SLLP' => ['name' => 'El Alto Intl.', 'city' => 'La Paz, Bolívia', 'lat' => -16.5133, 'lon' => -68.1925, 'postoAvancadoDe' => null],
            'SLUY' => ['name' => 'Joya Andina', 'city' => 'Uyuni, Bolívia', 'lat' => -20.4413, 'lon' => -66.8576, 'postoAvancadoDe' => 'SLLP'],
            'SLRQ' => ['name' => 'Rurrenabaque', 'city' => 'Rurrenabaque, Bolívia', 'lat' => -14.4250, 'lon' => -67.5014, 'postoAvancadoDe' => 'SLLP'],
            'SLAP' => ['name' => 'Apolo', 'city' => 'Apolo, Bolívia', 'lat' => -14.7382, 'lon' => -68.4107, 'postoAvancadoDe' => 'SLLP'],

            // --- Nepal: hub + 3 postos avançados (VNLK/Lukla incluso, ver correção no docblock) ---
            'VNKT' => ['name' => 'Tribhuvan Intl.', 'city' => 'Catmandu, Nepal', 'lat' => 27.6966, 'lon' => 85.3591, 'postoAvancadoDe' => null],
            'VNLK' => ['name' => 'Tenzing-Hillary', 'city' => 'Lukla, Nepal', 'lat' => 27.6869, 'lon' => 86.7297, 'postoAvancadoDe' => 'VNKT'],
            'VNJS' => ['name' => 'Jomsom', 'city' => 'Jomsom, Nepal', 'lat' => 28.7822, 'lon' => 83.7225, 'postoAvancadoDe' => 'VNKT'],
            'VNPR' => ['name' => 'Pokhara Intl.', 'city' => 'Pokhara, Nepal', 'lat' => 28.1897, 'lon' => 84.0149, 'postoAvancadoDe' => 'VNKT'],

            // --- Papua (Nova Guiné/Indonésia): hub + 3 postos avançados ---
            'WAJW' => ['name' => 'Wamena', 'city' => 'Wamena, Nova Guiné', 'lat' => -4.1025, 'lon' => 138.9578, 'postoAvancadoDe' => null],
            'WAJO' => ['name' => 'Oksibil', 'city' => 'Oksibil, Nova Guiné', 'lat' => -4.9071, 'lon' => 140.6277, 'postoAvancadoDe' => 'WAJW'],
            'WAYE' => ['name' => 'Enarotali', 'city' => 'Enarotali, Nova Guiné', 'lat' => -3.9262, 'lon' => 136.3803, 'postoAvancadoDe' => 'WAJW'],
            'WAJB' => ['name' => 'Bokondini', 'city' => 'Bokondini, Nova Guiné', 'lat' => -3.6847, 'lon' => 138.6800, 'postoAvancadoDe' => 'WAJW'],

            // --- Butão: hub + 3 postos avançados ---
            'VQPR' => ['name' => 'Paro Intl.', 'city' => 'Paro, Butão', 'lat' => 27.4032, 'lon' => 89.4245, 'postoAvancadoDe' => null],
            'VQBT' => ['name' => 'Bathpalathang', 'city' => 'Jakar, Butão', 'lat' => 27.5622, 'lon' => 90.7471, 'postoAvancadoDe' => 'VQPR'],
            'VQGP' => ['name' => 'Gelephu', 'city' => 'Gelephu, Butão', 'lat' => 26.8835, 'lon' => 90.4660, 'postoAvancadoDe' => 'VQPR'],
            'VQTY' => ['name' => 'Yongphulla', 'city' => 'Trashigang, Butão', 'lat' => 27.2563, 'lon' => 91.5146, 'postoAvancadoDe' => 'VQPR'],

            // --- Groenlândia: posto avançado isolado de PAFA, não é base ---
            'BGSF' => ['name' => 'Kangerlussuaq', 'city' => 'Kangerlussuaq, Groenlândia', 'lat' => 67.0122, 'lon' => -50.7116, 'postoAvancadoDe' => 'PAFA'],
        ];
    }
}
