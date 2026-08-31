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
 * Importa pra tabela `aeroporto` o catálogo global do OurAirports
 * (https://ourairports.com/data/, domínio público) — decisão tomada em
 * conversa (ver README, "Backend: importação global de aeroportos"):
 * em vez de manter só os aeroportos que a rede visita hoje
 * (`app:importar-aeroportos-legado`, ainda existe e continua sendo o
 * jeito certo de semear/backfillar as duas bases + os 9 postos
 * avançados originais), este comando traz o mundo inteiro de uma vez —
 * qualquer voo que desviar ou pousar num aeroporto real "desconhecido"
 * já encontra ele no catálogo, sem um admin precisar cadastrar cada um
 * na mão em `/aeroportos`.
 *
 * **Baixa o CSV em tempo de execução** (`self::CSV_URL`, o espelho da
 * OurAirports no GitHub Pages, ~13 MB), em vez de embutir um array PHP
 * gigante como `ImportarAeroportosLegadoCommand` faz pros 11 legados —
 * inviável de revisar/manter à mão nesse tamanho, e o CSV de origem é
 * atualizado com frequência (aeroportos abrem/fecham) então baixar de
 * novo a cada execução é o comportamento certo, não um efeito colateral.
 * Precisa de rede na máquina que roda o comando (normal pra quem tem o
 * repositório clonado; **não** rodou neste sandbox de desenvolvimento —
 * ver README, seção "Estado atual").
 *
 * **Download via `ext-curl` direto, não `symfony/http-client`.** A
 * primeira versão usava `HttpClientInterface`, e mesmo depois de separar
 * download e parse em duas fases (baixar tudo pra um arquivo temporário
 * antes de começar a importar — não os dois ao mesmo tempo), o download
 * continuava morrendo com `Idle timeout reached` em ambiente real
 * (Windows, com Xdebug carregado) mesmo sem nenhum processamento pesado
 * acontecendo durante o download em si — sinal de que o problema não
 * era o consumo lento de quem lê os chunks, e sim como o transporte que
 * o `symfony/http-client` escolheu nesse ambiente decide "isso travou".
 * Trocado por `curl_exec()` direto (a mesma extensão que o transporte
 * padrão do HttpClient usa por baixo, só que sem a camada de abstração
 * no meio) com `CURLOPT_LOW_SPEED_LIMIT`/`CURLOPT_LOW_SPEED_TIME`: só
 * desiste se a velocidade cair abaixo de ~1 KB/s por 30 segundos
 * seguidos — é o critério padrão de "conexão realmente travada" que
 * `curl`/navegadores usam, bem mais tolerante a uma rede lenta (ou a
 * uma pausa momentânea) do que qualquer timeout de inatividade fixo.
 * `CURLOPT_FILE` grava direto no arquivo temporário à medida que os
 * bytes chegam, sem seleção de resposta do servidor de operação; segue
 * sem carregar o CSV inteiro na memória de uma vez. Como consequência,
 * `symfony/http-client` deixou de ser necessário — não precisa mais
 * rodar `composer require symfony/http-client` pra este comando (ver
 * "Como ligar isso na sua máquina" abaixo). Se a extensão `curl` do PHP
 * não estiver habilitada, o comando avisa e para (é praticamente sempre
 * habilitada por padrão, mas alguns builds mínimos de PHP a desligam).
 *
 * **Gotcha conhecido no Windows: "SSL certificate problem".** PHP
 * standalone/XAMPP no Windows costuma não vir com um pacote de
 * certificados raiz configurado (`curl.cainfo`/`openssl.cafile` em
 * branco no php.ini) — diferente da maioria das distros Linux, que já
 * apontam pro bundle do sistema. `curl_exec()` falha com "unable to get
 * local issuer certificate" nesse caso; o comando detecta esse erro
 * especificamente e imprime o passo a passo de correção (baixar o
 * `cacert.pem` da Mozilla e apontar `curl.cainfo`/`openssl.cafile` pra
 * ele) em vez de só devolver o erro cru do curl. De propósito **não**
 * desligamos a verificação de certificado pra "resolver" isso — isso
 * abriria brecha pra um man-in-the-middle silencioso em qualquer
 * request HTTPS futuro que reusar esse mesmo PHP, não só neste comando.
 *
 * **Filtro, em duas camadas.** Em qualquer país: só linhas com
 * `icao_code` preenchido e no formato que o resto do app já valida
 * (`/^[A-Z0-9]{3,8}$/`, mesmo regex de `AeroportoController::criar()`)
 * e `type` diferente de `closed` — decisão tomada em conversa: cobertura
 * global por ICAO real, não identificador local. **Exceção, nas
 * `self::PAISES_MISSAO` + Alasca** (ver constante, e README "Pistas sem
 * ICAO nas regiões de missão"): uma pista sem `icao_code` ainda entra se
 * tiver `gps_code`/`local_code`/`ident` preenchido (nessa ordem de
 * preferência) batendo um regex mais frouxo (`/^[A-Z0-9]{2,8}$/` — sem
 * ICAO real pra exigir 3 caracteres mínimo) — decisão tomada em
 * conversa: essas regiões são onde a rede efetivamente opera bush
 * flying, então a pista sem ICAO ainda é operacionalmente relevante.
 * Toda linha assim entra com `Aeroporto::$icaoOficial = false`.
 *
 * **Atualizado: Bolívia/Nepal/Indonésia/Butão/Índia entraram na
 * lista.** Bug relatado em conversa: "alguns aeroportos do oriente
 * não estão importados, como VEPU por exemplo" - VEPU (Purnea,
 * Bihar, Índia) não tem `icao_code` na base da OurAirports, só
 * `gps_code`, e a Índia nunca esteve em `self::PAISES_MISSAO`, então
 * a linha caía direto em "sem identificador utilizável"
 * (`pulasSemIdentificador`), mesmo já existindo pista real ali. A
 * lista original cobria só o tema "geladas/fim de mundo" (PAFA/SCCI)
 * - ficou desatualizada quando as quatro bases sazonais novas
 * (SLLP/VNKT/WAJW/VQPR, ver docblock de `ImportarBasesSazonaisCommand`)
 * entraram na rede, cada uma no meio de bush flying de verdade nesses
 * países: Bolívia (SLLP), Nepal (VNKT — cujos postos avançados de
 * altitude ficam perto da fronteira indiana, e o próprio posto
 * **VQGP** de VQPR já é "na fronteira com a Índia", ver
 * `ImportarBasesSazonaisCommand`), Indonésia/Papua (WAJW) e Butão
 * (VQPR). `self::PAISES_MISSAO` agora inclui `BO`/`NP`/`ID`/`BT`/`IN`
 * pelo mesmo motivo que CL/AR/CA/RU já estavam lá — essas pistas sem
 * ICAO oficial continuam operacionalmente relevantes nessas regiões.
 * Rodar o comando de novo é seguro (idempotente, ver docblock abaixo)
 * e vai trazer essas pistas que antes eram puladas, sem duplicar nada
 * que já estava no catálogo.
 *
 * **Nunca sobrescreve nada, e nunca duplica um código.** Um código que
 * já existe (seja dos 11 legados, seja cadastrado à mão em
 * `/aeroportos`, seja de outra linha do próprio CSV) é pulado inteiro —
 * este comando só ADICIONA aeroportos novos, nunca mexe em
 * `postoAvancadoDe` de uma linha existente (diferente de
 * `ImportarAeroportosLegadoCommand`, que faz backfill nos 9 postos
 * avançados originais porque SABE qual base cada um serve). Todo
 * aeroporto importado por aqui entra com `postoAvancadoDe = null` — a
 * classificação "isso é posto avançado de PAFA/SCCI" continua uma
 * decisão de admin, feita depois, um de cada vez, em `/aeroportos` (ver
 * `AeroportoController::marcarPosto()`). **Importante pras pistas sem
 * ICAO oficial:** diferente de um ICAO real (globalmente único por
 * definição), um código local/gps não tem essa garantia — duas pistas
 * de países diferentes podem coincidir no mesmo código. Uma colisão
 * dessas é tratada como "pulado" (contado à parte no resumo final,
 * `pulasColisaoLocal`), nunca sobrescreve o que já está no catálogo —
 * a primeira a chegar (ordem do CSV) fica, a segunda é descartada.
 *
 * **Idempotente e retomável**: rodar de novo só adiciona o que ainda não
 * existe (dedup por `icao`, checado em memória via `AeroportoRepository::
 * findTodosIcaosComoSet()` — uma query só, não uma por linha do CSV,
 * que com dezenas de milhares de linhas seria a parte mais lenta do
 * import de longe) — útil tanto pra rodar de novo depois de uma falha de
 * rede no meio do arquivo quanto pra pegar aeroportos novos que a
 * OurAirports adicionou desde a última execução. Se o download falhar
 * (rede caiu, timeout), nada foi importado ainda (a falha acontece antes
 * do parse começar) — só rodar o comando de novo.
 */
#[AsCommand(
    name: 'app:importar-aeroportos-ourairports',
    description: 'Importa o catálogo global de aeroportos (OurAirports, domínio público) pra tabela aeroporto.',
)]
class ImportarAeroportosOurairportsCommand extends Command
{
    private const CSV_URL = 'https://davidmegginson.github.io/ourairports-data/airports.csv';

    /**
     * Países (código ISO de 2 letras, coluna `iso_country`) onde uma
     * pista sem ICAO ainda entra no catálogo via `local_code`/`gps_code`/
     * `ident` — decisão tomada em conversa (ver README, "Pistas sem ICAO
     * nas regiões de missão"): Chile, Argentina, Antártida + ilhas
     * subantárticas próximas (Malvinas/Falkland, Geórgia do Sul), Canadá
     * e Rússia inteiros, Groenlândia e Svalbard (completando "polo
     * norte"). Alasca (parte dos EUA, não um país) é tratado à parte via
     * `iso_region` — ver `dentroDeRegiaoDeMissao()`.
     *
     * **Bolívia/Nepal/Indonésia/Butão/Índia** entraram depois, junto com
     * as quatro bases sazonais novas (SLLP/VNKT/WAJW/VQPR) — ver docblock
     * da classe ("Atualizado: Bolívia/Nepal/Indonésia/Butão/Índia
     * entraram na lista") pro bug relatado (VEPU, Índia) que expôs a
     * lista desatualizada.
     */
    private const PAISES_MISSAO = ['CL', 'AR', 'AQ', 'FK', 'GS', 'CA', 'RU', 'GL', 'SJ', 'BO', 'NP', 'ID', 'BT', 'IN'];

    /** Prefixo de `iso_region` que identifica o Alasca dentro dos EUA (ex.: 'US-AK'). */
    private const PREFIXO_REGIAO_ALASCA = 'US-AK';

    /** Flush + clear a cada N linhas novas — evita acumular todas as entidades em memória num import de dezenas de milhares de linhas. */
    private const TAMANHO_LOTE = 500;

    /** Tempo máx. (s) só pra estabelecer a conexão (não conta o download em si). */
    private const CONNECT_TIMEOUT_S = 30;

    /** Considera a conexão travada se ficar abaixo desta velocidade (bytes/s)... */
    private const LOW_SPEED_LIMIT_BPS = 1000;

    /** ...por este tanto de segundos seguidos. Uma pausa momentânea não conta - só travamento de verdade. */
    private const LOW_SPEED_TIME_S = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AeroportoRepository $aeroportos,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!\function_exists('curl_init')) {
            $io->error('A extensão curl do PHP não está habilitada — necessária pra baixar o CSV. Habilite "extension=curl" no php.ini e tente de novo.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Baixando %s…', self::CSV_URL));

        $tmpPath = tempnam(sys_get_temp_dir(), 'katabatic_ourairports_');
        if (false === $tmpPath) {
            $io->error('Não consegui criar um arquivo temporário pra baixar o CSV.');

            return Command::FAILURE;
        }

        try {
            if (!$this->baixarParaArquivo($tmpPath, $io)) {
                return Command::FAILURE;
            }

            return $this->importarDoArquivo($tmpPath, $io);
        } finally {
            // Sempre limpa o temporário, tanto em caso de sucesso quanto de falha -
            // é só uma cópia de trabalho, nunca faz sentido deixar pra trás.
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Baixa o CSV inteiro pro arquivo temporário via curl direto (ver
     * docblock da classe pro porquê, em vez de symfony/http-client) antes
     * de qualquer parse começar. `CURLOPT_FILE` grava cada chunk assim
     * que chega, então nunca segura os ~13 MB inteiros como uma string só
     * na memória.
     */
    private function baixarParaArquivo(string $tmpPath, SymfonyStyle $io): bool
    {
        $handle = fopen($tmpPath, 'wb');
        if (false === $handle) {
            $io->error(sprintf('Não consegui abrir "%s" pra escrita.', $tmpPath));

            return false;
        }

        $ch = curl_init(self::CSV_URL);
        curl_setopt_array($ch, [
            \CURLOPT_FILE => $handle,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 5,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            \CURLOPT_TIMEOUT => 0, // sem limite de tempo total - quem decide "travou" é o low-speed abort abaixo
            \CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BPS,
            \CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_S,
            \CURLOPT_USERAGENT => 'Katabatic/1.0 (app:importar-aeroportos-ourairports)',
            \CURLOPT_FAILONERROR => true, // trata HTTP 4xx/5xx como falha do curl_exec, não como "sucesso" com corpo de erro salvo
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $erro = curl_error($ch);
        $status = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $bytes = curl_getinfo($ch, \CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($handle);

        if (false === $ok) {
            // CURLE_SSL_CACERT (60) / CURLE_SSL_CACERT_BADFILE (77) - "unable to get
            // local issuer certificate": comum em PHP standalone/XAMPP no Windows,
            // que não vem com um pacote de certificados raiz configurado por padrão
            // (diferente da maioria das distros Linux). Não é um bug deste comando
            // nem do host remoto - é o PHP local sem saber em quem confiar pra TLS.
            // Preferimos guiar o fix certo (apontar pro cacert.pem da Mozilla) a
            // desligar a verificação de certificado, que resolveria mas abriria a
            // porta pra um man-in-the-middle silencioso em qualquer request HTTPS
            // futuro que reusar esse mesmo PHP.
            if (\in_array($errno, [\CURLE_SSL_CACERT, \CURLE_SSL_CACERT_BADFILE], true) || false !== stripos($erro, 'certificate')) {
                $io->error('Falha ao baixar o CSV da OurAirports: SSL certificate problem (unable to get local issuer certificate).');
                $io->writeln([
                    '',
                    'Isso costuma acontecer em instalações de PHP no Windows que não vêm com',
                    'um pacote de certificados raiz configurado. Pra corrigir:',
                    '',
                    '  1. Baixe o pacote oficial da Mozilla: https://curl.se/ca/cacert.pem',
                    '  2. Salve num lugar fixo, ex.: C:\\php\\cacert.pem',
                    '  3. Rode "php --ini" pra achar o php.ini que o CLI está usando, abra',
                    '     ele e defina (removendo o ";" na frente se já existir a linha):',
                    '       curl.cainfo = "C:\\php\\cacert.pem"',
                    '       openssl.cafile = "C:\\php\\cacert.pem"',
                    '  4. Feche e abra o terminal de novo (php.ini só é lido ao iniciar o',
                    '     processo) e rode "php bin/console app:importar-aeroportos-ourairports"',
                    '     de novo.',
                    '',
                ]);

                return false;
            }

            $io->error(sprintf('Falha ao baixar o CSV da OurAirports: %s', '' !== $erro ? $erro : 'erro desconhecido'));

            return false;
        }
        if ($status < 200 || $status >= 300) {
            $io->error(sprintf('OurAirports respondeu HTTP %d — tente de novo mais tarde.', $status));

            return false;
        }

        $io->writeln(sprintf('Download completo (%.1f MB).', $bytes / 1_048_576));

        return true;
    }

    /**
     * Faz o parse/import a partir do arquivo já baixado localmente — sem
     * nenhuma conexão de rede aberta nesta fase, então não existe timeout
     * de rede pra estourar aqui, não importa quanto tempo o import (com
     * seus flush/clear em lote) demorar.
     */
    private function importarDoArquivo(string $tmpPath, SymfonyStyle $io): int
    {
        $stream = fopen($tmpPath, 'rb');
        if (false === $stream) {
            $io->error(sprintf('Não consegui reabrir "%s" pra leitura.', $tmpPath));

            return Command::FAILURE;
        }

        $header = fgetcsv($stream);
        if (false === $header) {
            fclose($stream);
            $io->error('CSV vazio ou ilegível.');

            return Command::FAILURE;
        }
        $col = array_flip($header);
        foreach (['icao_code', 'gps_code', 'local_code', 'ident', 'iso_region', 'type', 'name', 'municipality', 'iso_country', 'latitude_deg', 'longitude_deg'] as $obrigatoria) {
            if (!isset($col[$obrigatoria])) {
                fclose($stream);
                $io->error(sprintf('Coluna esperada "%s" não encontrada no CSV — o formato da OurAirports pode ter mudado.', $obrigatoria));

                return Command::FAILURE;
            }
        }

        $existentes = $this->aeroportos->findTodosIcaosComoSet();
        $lidas = 0;
        $importadasIcaoReal = 0;
        $importadasCodigoLocal = 0;
        $pulasSemIdentificador = 0;
        $pulasCoordInvalida = 0;
        $pulasFechadas = 0;
        $pulasJaExistiam = 0;
        $pulasColisaoLocal = 0;
        $noLote = 0;

        while (false !== ($row = fgetcsv($stream))) {
            ++$lidas;
            if (0 === $lidas % 5000) {
                $io->write('.');
            }

            if ('closed' === ($row[$col['type']] ?? '')) {
                ++$pulasFechadas;
                continue;
            }

            [$icao, $icaoOficial] = $this->resolverIdentificador($row, $col);
            if (null === $icao) {
                ++$pulasSemIdentificador;
                continue;
            }

            if (isset($existentes[$icao])) {
                if ($icaoOficial) {
                    ++$pulasJaExistiam;
                } else {
                    ++$pulasColisaoLocal; // já existe (mesma pista reimportada, ou coincidência de código local com outra pista) - nunca sobrescreve
                }
                continue;
            }

            $lat = (float) ($row[$col['latitude_deg']] ?? 0);
            $lon = (float) ($row[$col['longitude_deg']] ?? 0);
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || (0.0 === $lat && 0.0 === $lon)) {
                ++$pulasCoordInvalida;
                continue;
            }

            $nome = trim($row[$col['name']] ?? '');
            if ('' === $nome) {
                $nome = $icao;
            }
            $municipio = trim($row[$col['municipality']] ?? '');
            $pais = trim($row[$col['iso_country']] ?? '');
            $cidade = match (true) {
                '' !== $municipio && '' !== $pais => $municipio.', '.$pais,
                '' !== $municipio => $municipio,
                '' !== $pais => $pais,
                default => $nome,
            };

            $aeroporto = new Aeroporto($icao, $nome, $cidade, $lat, $lon, $icaoOficial);
            $this->em->persist($aeroporto);
            $existentes[$icao] = true; // evita reimportar o mesmo código se ele se repetir em outra linha do CSV
            if ($icaoOficial) {
                ++$importadasIcaoReal;
            } else {
                ++$importadasCodigoLocal;
            }
            ++$noLote;

            if ($noLote >= self::TAMANHO_LOTE) {
                $this->em->flush();
                $this->em->clear();
                $noLote = 0;
            }
        }

        if ($noLote > 0) {
            $this->em->flush();
            $this->em->clear();
        }
        fclose($stream);

        $io->newLine();
        $io->success(sprintf(
            '%d aeroporto(s) importado(s) (%d com ICAO real, %d com código local nas regiões de missão) de %d linha(s) lida(s) — '
            .'%d já existiam, %d código local já presente/colidiu, %d sem identificador utilizável, %d coordenada inválida, %d fechados.',
            $importadasIcaoReal + $importadasCodigoLocal,
            $importadasIcaoReal,
            $importadasCodigoLocal,
            $lidas,
            $pulasJaExistiam,
            $pulasColisaoLocal,
            $pulasSemIdentificador,
            $pulasCoordInvalida,
            $pulasFechadas
        ));

        return Command::SUCCESS;
    }

    /**
     * Resolve o código que este aeroporto vai usar no catálogo, e se ele é
     * um ICAO oficial ou não (ver docblock da classe, seção "Filtro, em
     * duas camadas"):
     *
     * 1. `icao_code` válido (`/^[A-Z0-9]{3,8}$/`) → esse código,
     *    `icaoOficial = true`. Vale em qualquer país.
     * 2. Senão, se a linha está numa região de missão
     *    (`dentroDeRegiaoDeMissao()`): primeiro de `gps_code`/
     *    `local_code`/`ident` que for não-vazio e bater
     *    `/^[A-Z0-9]{2,8}$/` → esse código, `icaoOficial = false`.
     * 3. Senão, `[null, false]` — linha sem identificador utilizável (fora
     *    do escopo de missão sem ICAO, ou nenhuma das colunas de fallback
     *    tinha algo que servisse).
     *
     * @param list<string>         $row
     * @param array<string, int>   $col
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolverIdentificador(array $row, array $col): array
    {
        $icao = strtoupper(trim($row[$col['icao_code']] ?? ''));
        if (preg_match('/^[A-Z0-9]{3,8}$/', $icao)) {
            return [$icao, true];
        }

        $isoCountry = strtoupper(trim($row[$col['iso_country']] ?? ''));
        $isoRegion = strtoupper(trim($row[$col['iso_region']] ?? ''));
        if (!$this->dentroDeRegiaoDeMissao($isoCountry, $isoRegion)) {
            return [null, false];
        }

        foreach (['gps_code', 'local_code', 'ident'] as $colunaFallback) {
            $candidato = strtoupper(trim($row[$col[$colunaFallback]] ?? ''));
            if (preg_match('/^[A-Z0-9]{2,8}$/', $candidato)) {
                return [$candidato, false];
            }
        }

        return [null, false];
    }

    /**
     * `true` quando `$isoCountry`/`$isoRegion` (colunas do CSV) caem numa
     * das regiões de missão da rede — ver `self::PAISES_MISSAO` pro
     * porquê de cada país. Alasca é tratado à parte porque é uma região
     * dos EUA (`iso_country = 'US'`), não um país inteiro — os EUA fora
     * do Alasca **não** entram nessa exceção.
     */
    private function dentroDeRegiaoDeMissao(string $isoCountry, string $isoRegion): bool
    {
        if (\in_array($isoCountry, self::PAISES_MISSAO, true)) {
            return true;
        }

        return 'US' === $isoCountry && str_starts_with($isoRegion, self::PREFIXO_REGIAO_ALASCA);
    }
}
