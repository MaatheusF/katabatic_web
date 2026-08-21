# Katabatic Web

Portal web da Katabatic — companhia aérea virtual (MSFS 2024 + VATSIM) com
operação de carga, transporte de pessoal e pesquisa meteorológica em área
extrema. Duas bases em hemisférios opostos: **PAFA** (Fairbanks, Alasca) e
**SCCI** (Punta Arenas, Chile).

Este repositório é a implementação real (Symfony) do que antes existia só
como mockups em HTML autocontido. O pacote original de mockups e o contrato
de telemetria do ACARS ficam arquivados em `docs/` como referência enquanto
convertemos tela por tela.

## Estado atual (leia isto primeiro se estiver retomando o projeto)

As treze telas do mockup original já existem em Symfony e navegam entre si
de verdade (ver "Progresso" logo abaixo). Backend real (Doctrine + Postgres,
sem nada mock por trás) já cobre cinco fatias, nesta ordem histórica — cada
uma tem sua própria seção "## Backend: ..." mais abaixo com o que existe,
lacunas conhecidas de propósito e o passo a passo pra ligar na sua máquina:

1. **Login e perfil** — `Pilot` + Symfony Security, autenticação de verdade
   (era sessão mock).
2. **Adesão e solicitações** — `MembershipRequest`; formulário público de
   `/adesao` e o grid administrativo de `/solicitacoes` (aprovar/rejeitar)
   já persistem.
3. **Voos e telemetria** — `Voo`; Logbook do Portal e o relatório de
   `/voo` vêm do banco, com os 11 voos legados importados por
   `app:importar-voos-legados` (3 deles com telemetria real gravada).
4. **Frota, mapa ao vivo e histórico da aeronave** — `Aeronave`; Frota do
   Portal, `/nova-aeronave`, `/mapa-ao-vivo` e `/aeronave/{reg}` vêm do
   banco, com as 6 aeronaves legadas importadas por
   `app:importar-frota-legada`.
5. **Ingestão ACARS** — liga o script `tools/acars-capture/katabatic_capture.py`
   (rodado no PC do piloto via SimConnect/MSFS 2024) em dois endpoints do
   Symfony: `POST /api/acars/v1/voos/iniciar` (início do voo — marca a
   aeronave "Em voo" em tempo real, com autocorreção se o PC do piloto
   travar) e `POST /api/acars/v1/voos` (fim do voo — deriva telemetria via
   `TelemetryDeriver` e grava um `Voo` de verdade no Logbook). Esta é a
   única fatia que hoje faz o Logbook crescer com dado novo de verdade,
   fora de um comando de import.

O que **ainda é mock** (sem tabela/banco por trás): Agendamentos
(`/agendamentos`), a aba "Bases" do Portal, fotos de aeronave em
`/nova-aeronave`, os botões "Publicar"/"Salvar rascunho" da própria tela
`/novo-voo` (o Logbook cresce por fora dela, via ACARS), a contagem
`Pilot::$voos` no grid de Pilotos (fixa em 0), a posição ao vivo no Mapa ao
vivo (ainda é replay em loop da telemetria mais recente, não um feed real)
e busca de METAR/TAF/recorte GRIB. Lista completa e ordenada de próximos
passos em "Próximos passos" no fim deste arquivo.

Restrição importante de quem escreveu até aqui: todo este código foi
desenvolvido num sandbox **sem acesso de rede ao Packagist nem Docker** —
nada disso foi executado de fato, só validado com `php -l`, lint estático,
e fixtures manuais isoladas (ex.: `TelemetryDeriver` e
`Aeronave::getStatusEfetivo()` têm testes manuais via `php -r`/scripts
avulsos, não PHPUnit rodando de verdade). Cada fatia foi entregue por
download (arquivo a arquivo + um `.zip` preservando a estrutura de pastas)
pra quem está com o repositório na própria máquina (que tem Docker) copiar
por cima e rodar migrations/testar de fato. Se você é uma sessão nova
retomando este projeto, isso quer dizer: confie no código mas rode
`php -l`/os testes disponíveis de novo antes de assumir que algo passou,
e continue entregando qualquer fatia nova do mesmo jeito (download, nunca
escrita direta na pasta do usuário) a menos que o usuário diga que isso
mudou.

## Stack

- **PHP 8.2+** / **Symfony 7.2**
- **Twig** para templates
- **Postgres 16 + PostGIS** (via Docker) — já em uso por Login/Perfil,
  Adesão/Solicitações, Voos/telemetria, Frota e ingestão ACARS (ver
  "Backend" mais abaixo); só Agendamentos ainda é mock sem schema próprio
- Sem build step de frontend por enquanto: CSS/JS servidos como arquivos
  estáticos em `public/assets/`, para não depender de Node/npm

## Como rodar

Pré-requisitos: PHP 8.2+, Composer, Docker (agora obrigatório: Login e
Perfil já dependem do Postgres — ver "Backend: login e perfil" mais
abaixo).

```bash
composer install
docker compose up -d                              # sobe o Postgres+PostGIS
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate        # roda todas as migrations (pilot, membership_request, voo, aeronave, em_voo_desde...)
php bin/console app:importar-voos-legados          # opcional: popula os 11 voos legados (3 c/ telemetria real)
php bin/console app:importar-frota-legada          # opcional: popula as 6 aeronaves legadas
symfony serve                                      # ou: php -S localhost:8000 -t public
```

Acesse `http://localhost:8000` e entre em `/login` com CID `1234567`,
senha `katabatic-dev`. Pra usar a ingestão ACARS de verdade (script rodando
no PC do piloto), veja também o `ACARS_TOKEN` no `.env` e a seção "Backend:
ingestão ACARS (MVP)" mais abaixo.

> Este scaffold foi escrito manualmente (sem `composer create-project`)
> porque o ambiente onde ele foi criado não tinha acesso à internet liberado
> para o Packagist. Rode `composer install` na sua máquina para baixar as
> dependências de verdade — a estrutura de arquivos segue exatamente o que o
> `symfony/skeleton` + `webapp` pack gerariam.

## Estrutura

```
katabatic_web/
├── bin/console              CLI do Symfony
├── config/                  bundles, rotas, packages
├── docs/                    mockups originais + contrato ACARS (referência)
├── public/
│   ├── index.php             front controller
│   └── assets/
│       ├── css/base.css      tokens de design + componentes compartilhados
│       ├── css/pages/        CSS específico de cada tela
│       ├── js/theme-toggle.js  tema claro/escuro (compartilhado)
│       ├── js/lang-toggle.js   idioma PT/EN (compartilhado)
│       └── js/pages/         JS específico de cada tela
├── migrations/               schema do banco versionado (Doctrine Migrations)
├── src/
│   ├── Controller/
│   ├── Entity/               Pilot, MembershipRequest, Voo, Aeronave
│   ├── Repository/
│   ├── Security/             LoginFormAuthenticator
│   └── Kernel.php
├── templates/
│   ├── base.html.twig        layout com topbar/footer compartilhados
│   └── home/                 uma pasta por tela
└── docker-compose.yml         Postgres + PostGIS
```

## Progresso (mockup → tela real)

- [x] `katabatic-home.html` → `/` (Home institucional)
- [x] `katabatic-portal.html` → `/portal` (Logbook, Frota, Bases) —
  Logbook (desde "Backend: voos e telemetria") e Frota (desde "Backend:
  mapa ao vivo e histórico da frota") já são backend real; Bases
  continua mock
- [x] `katabatic-voo.html` → `/voo` (relatório de voo — mapa, gráficos
  sincronizados, fases, pouso e relato do piloto). Telemetria real (3
  voos de teste) servida do banco via `GET /voo/telemetria` (ver
  "Backend: voos e telemetria") — relato do piloto agora persiste de
  verdade (`POST /voo/{codigo}/relato`)
- [x] `katabatic-novo-voo.html` → `/novo-voo` (registro de voo — importar
  telemetria com dropzone simulada, ou registro manual). Publicar/Salvar
  rascunho ainda são mock (`alert`) nesta tela em si; o Logbook já é
  tabela real e o seletor de aeronave já vem da Frota real (ver "Backend:
  mapa ao vivo e histórico da frota") — desde a ingestão ACARS (ver
  "Backend: ingestão ACARS (MVP)"), o Logbook já cresce de verdade, só que
  por fora desta tela (o script de captura manda o voo direto pro
  servidor, não passa pelo formulário de "Novo voo")
- [x] `katabatic-nova-aeronave.html` → `/nova-aeronave` (cadastro de
  aeronave — país/matrícula/tipo/base, limites operacionais, fotos e
  observações internas). "Salvar aeronave" é um POST de verdade (ver
  "Backend: mapa ao vivo e histórico da frota") — Fotos continua só
  maquete visual
- [x] Login (`/login`) → não existia mockup próprio; tela nova para o
  fluxo Home → Login → Portal. Autenticação **de verdade** desde a
  seção "Backend" abaixo (Doctrine + Security) — era mock via sessão
  (CID `1234567`, qualquer senha) até então.
- [x] Histórico de aeronave (`/aeronave/{reg}`) → não existia mockup
  próprio; tela nova acessada a partir de um card/linha da Frota no
  Portal. Mostra num mapa (Leaflet) todas as pernas voadas por uma
  matrícula num período filtrável, com traçado, origem/destino e label
  de cada perna, mais uma lista ao lado. Pernas agora vêm do Logbook de
  verdade (`VooRepository::findAllByAeronaveReg()`, ver "Backend: mapa
  ao vivo e histórico da frota") em vez de um histórico sintético. Ver
  detalhes na seção "Histórico de aeronave" abaixo.
- [x] Solicitação de adesão (`/adesao`) → não existia mockup próprio;
  tela pública nova onde quem quer virar piloto se candidata. "Enviar
  solicitação" é um POST de verdade (ver "Backend: adesão e
  solicitações").
- [x] Solicitações / Pilotos (`/solicitacoes`) → não existia mockup
  próprio; grid administrativo restrito a pilotos "admin" (ver seção
  "Administração" abaixo) pra revisar pedidos de adesão e ver a lista de
  pilotos cadastrados — aprovar/rejeitar já é backend real (ver
  "Backend: adesão e solicitações").
- [x] Mapa ao vivo (`/mapa-ao-vivo`) → não existia mockup próprio; mapa
  Leaflet em tela cheia com a posição simulada de toda a frota (em voo e
  em solo) num painel flutuante, mais clima em tempo real. Quem está
  "Em voo" vs. parado, e a base/posição de cada um, já vem da Frota real
  (ver "Backend: mapa ao vivo e histórico da frota") — a posição no mapa
  em si continua simulada (replay em loop da telemetria mais recente),
  sem feed ao vivo via ACARS ainda. Ver detalhes na seção "Mapa ao vivo"
  abaixo.
- [x] Agendamentos (`/agendamentos`) → não existia mockup próprio;
  reserva de aeronave pra um voo futuro, com a possibilidade de
  encadear várias pernas seguidas pra mesma aeronave. Ver detalhes na
  seção "Agendamento de voo" abaixo.
- [x] Manuais (`/manuais`) → não existia mockup próprio; hub de
  documentação/ajuda com o primeiro manual de verdade, Fraseologia
  VATSIM (`/manuais/fraseologia-vatsim`). Ver detalhes na seção
  "Manuais e Operações" abaixo.
- [x] Perfil (`/perfil`) → não existia mockup próprio; configurações da
  conta (nome, e-mail, foto), aberta pelo próprio cartão do piloto no
  rail. Ver detalhes na seção "Perfil do piloto" abaixo.

Todas as treze telas já navegam entre si por botões de verdade (não só
mockup estático lado a lado): Home → Login → Portal, Home/Login →
Adesão, Portal ↔ Voo, Portal ↔ Novo voo, Portal (Frota) ↔ Nova
aeronave, Portal (Frota) ↔ Histórico de aeronave, Portal ↔ Solicitações/
Pilotos (só pra admin), Portal ↔ Mapa ao vivo, Portal ↔ Agendamentos,
Portal ↔ Manuais ↔ Fraseologia VATSIM, Portal ↔ Perfil (a partir do
cartão do piloto no rail), e o rail lateral funciona em
qualquer uma das telas da área logada (mesmo
fora do Portal, onde ele faz um link real para `/portal?view=...` em
vez do troca-de-view em JS que só existe estando já no Portal).

As linhas do Logbook do Portal também abrem `/voo?id=...` de verdade —
mas só as 3 linhas de 19/08 cujo horário bate com um dos voos de teste
reais gravados em `flights.json` (03:22Z, 03:28Z e 03:34Z) ficam
clicáveis (cursor muda, `tabindex` pra teclado); as outras 3 linhas são
mock sem telemetria gravada e não têm pra onde ir ainda. Como o Logbook
mock e os voos reais são dados de fontes diferentes (só coincidem no
horário), o callsign/aeronave/rota mostrados na linha ainda não batem
100% com o que o relatório real exibe — mistura documentada e
**preservada de propósito** mesmo depois do Logbook virar tabela de
verdade (ver "Backend: voos e telemetria" mais abaixo pra por quê). O
bloco "Operações recentes" da Home (também mock) continua sem link por
esse mesmo motivo.

Em `/voo?id=`, o card "Relatório de missão" fica na coluna esquerda do
grid (junto com Fases, Trajetória e Debrief), não mais ocupando a
largura inteira da tela abaixo do grid.

### Mais indicadores no debrief de `/voo`

Três campos de telemetria real que já estavam gravados em
`flights.json` mas nunca apareciam na tela ganharam espaço: TAS
(velocidade verdadeira, `prof[i][8]`) virou uma 3ª linha no gráfico
"Velocidade" (que já tinha IAS e GS), combustível restante
(`env[i][7]`) virou um gráfico novo — "Combustível" — mostrando a
curva de consumo ao longo do voo em vez de só o total queimado, e
rajada máxima (`wind_max`, distinto da média/componente `windc` que já
existia) entrou no tile de vento dos KPIs — "Vento méd. / rajada"
mostra os dois números juntos (`+5 / 6.2 kt`) em vez de virar um tile
separado, pra manter os KPIs em linhas cheias de 6 (19 tiles deixava
uma linha só com 1 no final; o mesmo padrão "média/pico" já existia no
readout do vento). Os dois campos novos também entraram no readout que
acompanha o cursor sobre os gráficos (`tas` e `combustível`, junto dos
que já existiam).

De brinde: o cabeçalho tinha "Yakutsk" fixo no texto e "UEEE ↔ UEEE"
fixo no HTML — coincidentemente sempre corretos pros 3 voos de teste
gravados (todos realmente perto de Yakutsk, todos UEEE→UEEE), mas sem
vir de `F.orig`/`F.dest` de verdade. Como UEEE não está em
`airports.json` (telemetria real fica geograficamente separada da rede
mock, ver caveat do Logbook), o cabeçalho agora mostra o próprio ICAO
de origem em vez de inventar um nome de cidade que só cobre esse caso
de teste — fica certo pra qualquer voo futuro sem precisar de uma
tabela ICAO→cidade só pra isso.

A tabela do Logbook em `/portal` tem um limite de altura com scroll
interno (cabeçalho fixo enquanto rola) e paginação client-side a partir
de 500 linhas filtradas (`LB_PAGE_SIZE` em `portal.js`) — com os 11
voos atuais (ver "Backend: voos e telemetria") isso não muda nada
visualmente ainda, mas já deixa a tela pronta pra quando existirem
centenas/milhares de voos de verdade.  A visualização compacta (ícone
de linhas densas nos filtros) continua disponível e ajuda a caber mais
linhas na tela antes de precisar rolar.

### Logbook mais rico: detalhe por voo e estatísticas agregadas

*(Descrição original da fase de mockup — os 11 voos descritos aqui
agora vêm da tabela `voo` via `PortalController::logbookViewModel()`
em vez de um array PHP fixo; ver "Backend: voos e telemetria" pra como
isso foi migrado. A UI e o formato de dado que `portal.js` consome não
mudaram nada.)*

O Logbook cresceu de 6 para 11 voos (os 3 linkados à telemetria real
continuam com data/hora/`flightId` idênticos — só ganharam campos
novos — e as outras 8 linhas, sem telemetria gravada, cobrem 5 semanas
em vez de 5 dias, dando variedade de rota/aeronave/condição suficiente
pra estatística agregada fazer sentido) e cada linha ganhou campos que
não existiam antes: `dist` (nm), `combustivelKg`,
`carga` (payload/pax em texto livre), `tempoSoloMin`/`tempoArMin`
(a soma dos dois não fecha exato com `tempoMin` de propósito — é mock,
não precisa bater ao segundo) e `metar` (ambientação, não alimenta
nenhum cálculo). Esses campos não caberiam na tabela sem poluir, então
cada linha ganhou um botão "▸" numa coluna nova à esquerda que abre uma
linha de detalhe embaixo dela (clique no botão não navega pro
relatório de voo, mesmo em linhas com `flightId` — só o resto da linha
continua clicável pra isso).

Acima da tabela, o card **"Estatísticas do período filtrado"**
recalcula junto com os mesmos filtros da tabela (chips de tipo,
período, busca) — não é um resumo fixo do Logbook inteiro, é sempre
sobre o que está filtrado no momento. Mostra distância total,
combustível total, tempo ar/solo somado e % de voos com ocorrência em
tiles, mais rota mais voada, aeronave com mais horas (soma de
`tempoMin` por matrícula) e condição mais comum (bucketizada por
`condTag`: boas/adversas/severas, já que as strings de `cond` são
todas diferentes entre si) em três blocos, e uma tendência de
dificuldade — uma barra por voo, em ordem cronológica, colorida com a
mesma escala de cor (verde/laranja/vermelho) da coluna Dificuldade da
tabela. Tudo client-side em `portal.js` (`lbStatsRender`), sem endpoint
novo — os mesmos dados de `KATABATIC_LB` que já alimentavam a tabela.

A coluna "Data" do Logbook agora mostra o ano (`dd/mm/aaaa`) para
evitar ambiguidade entre voos de anos diferentes. A coluna "Ocorrência"
aceita múltiplos registros por voo (ex.: overspeed e quique no mesmo
pouso) — cada ocorrência vira uma tag própria, empilhada verticalmente
na célula (`ocorrencias: [{label, tag}, ...]`, guardado dentro de
`Voo::$dados` — ver "Backend: voos e telemetria"); voos sem ocorrência
mostram "—". O
mesmo campo em "Novo voo" (registro manual) virou um grupo de chips
multi-seleção (`#ocor-chips`) em vez de um `<select>` de escolha única,
com "Nenhuma" exclusivo (marcar "Nenhuma" desmarca as outras, e
vice-versa).

## Histórico de aeronave (`/aeronave/{reg}`)

Tela nova acessada pelo link "Ver histórico no mapa" em cada card da
Frota (ou "Histórico" na visualização em lista) — mostra, num mapa
Leaflet, todas as pernas voadas pela matrícula num período filtrável
(chips rápidos de 7/30/90/180 dias/todo o período, ou um intervalo
"De"/"Até" específico), mais um filtro por tipo de operação. Cada
perna vira uma linha curva entre origem e destino com um label
(callsign) — pernas repetidas na mesma rota abrem em leque em vez de
empilhar uma linha idêntica por cima da outra — e cada aeroporto visitado
ganha um marcador com o código ICAO. A cor e a espessura do traçado
seguem a ordem cronológica dentro do período filtrado: quanto mais
recente a perna, mais forte (opaca e grossa) a linha; quanto mais
antiga, mais fraca — dá pra ver de relance onde a aeronave andou
recentemente. Um resumo no topo mostra total de pernas, aeroportos
distintos, horas voadas e a perna mais antiga do período.

Ao lado do mapa, a lista de pernas (mesmo período) deixa navegar pelo
histórico sem depender só do mapa: clicar em qualquer parte da linha
(ou apertar Enter/espaço com ela focada) só foca o mapa na rota
daquela perna, sem sair da tela — clicar numa linha do mapa faz o
mesmo no sentido inverso, destacando a linha correspondente na lista.
Abrir o relatório de voo de verdade é uma ação separada, específica:
só acontece clicando no nome do voo (o callsign, ex. "KBT118", que é
um link em azul dentro da célula) na lista, ou no link "Ver relatório
real" dentro do popup da perna no mapa.

**Atualizado (backend real):** o histórico de pernas vem de
`VooRepository::findAllByAeronaveReg()` (ver "Backend: mapa ao vivo e
histórico da frota") — todo voo já registrado com essa matrícula, mais
recente primeiro. Antes desta fatia, era gerado deterministicamente em
`AeronaveController::aircraftLegs()` (sem `rand()`/hora do sistema, só
pra ter volume suficiente pra demonstrar "todas as pernas de uma
aeronave" ao longo de meses) porque o Logbook de verdade só tinha 11
linhas no total; hoje mostra o volume real, que ainda é pequeno pelo
mesmo motivo (cresce sozinho conforme o Logbook cresce). As coordenadas
dos 11 aeroportos da rede (bases + estações avançadas, ver
`PortalController::bases()`) ficam em `public/assets/data/airports.json`.
A perna mais recente das 3 matrículas que têm voo de teste real gravado
(N208KB, CC-KBA, N67KB — mesmas do link Logbook → Voo) carrega
`flightId` e é a única com nome de voo clicável na lista/popup; o
traçado no mapa desta tela, porém, sempre usa a linha entre as
coordenadas ICAO (mesmo nessa perna) — a telemetria gravada nos testes
fica geograficamente em outro lugar (ver caveat do Logbook acima),
plotá-la ali quebraria o enquadramento do mapa com o resto do
histórico.

## Mapa ao vivo (`/mapa-ao-vivo`)

Tela nova no rail (item "Mapa ao vivo", entre Bases e o grupo
Administração) — um mapa Leaflet em tela cheia (ocupa todo o espaço
abaixo da barra superior, sem o `.wrap` com padding das outras
páginas) mostrando a frota inteira agora: quem está em voo e quem está
em solo, num único painel flutuante sobre o mapa. **Atualizado (backend
real):** quem está "Em voo" vs. em solo, e a base/posição de cada um,
vêm de `App\Entity\Aeronave` (ver "Backend: mapa ao vivo e histórico da
frota") — a posição *no mapa* continua simulada (replay em loop da
telemetria gravada), sem feed de posição em tempo real via ACARS ainda.

Este mapa, o de Histórico de aeronave (`/aeronave/{reg}`) e o de
Trajetória em `/voo` aceitam zoom com o scroll do mouse
(`scrollWheelZoom: true` no `L.map(...)` de cada um) — os três são o
conteúdo principal da tela onde aparecem, então "roubar" o scroll da
página enquanto o cursor está sobre eles é o comportamento esperado
(igual Google Maps). Já o mapa pequeno embutido na Home (`home.js`)
mantém `scrollWheelZoom: false` de propósito: ali ele é só um preview
decorativo dentro de uma página que ainda tem conteúdo abaixo, então
ativar o zoom por scroll ali prenderia o scroll da página sem o
usuário estar necessariamente ali para interagir com o mapa.

As aeronaves marcadas como "Em voo" na frota (`Aeronave::$status`; hoje
CC-KBA e N208KB, semeadas por `app:importar-frota-legada`) aparecem com
um marcador que se move ao longo do arco entre origem e destino da rota
(mesma curva de Bézier usada no histórico de aeronave), repetindo **em
loop** a telemetria real de ~5-6 minutos gravada em `flights.json` para
essas duas matrículas (a mesma gravação que abre atrás do link "Ver
relatório real") — via
`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()` (ver
"Backend: mapa ao vivo e histórico da frota"); uma aeronave "Em voo" sem
nenhum voo com telemetria gravada simplesmente não aparece na lista. Altitude e velocidade indicada mostradas no popup e no painel
lateral vêm ponto a ponto dessa gravação real; a posição geográfica,
porém, é sempre sintetizada sobre a rota origem→destino (coordenadas
de `airports.json`) — a telemetria gravada nos testes fica
geograficamente em outro lugar (ver caveat do Logbook), então plotar a
lat/lon real quebraria por completo o enquadramento do mapa com o
resto da rede. O relógio UTC na barra e a animação em si usam o
horário real do navegador (`Date.now()`), não um horário mock — é só
uma demonstração contínua da interface, sem pretender ser um voo
específico "acontecendo agora"; isso deixa de existir assim que houver
um feed real de posição via ACARS, quando o marcador passa a refletir
eventos de verdade em vez de repetir uma gravação.

As outras 4 aeronaves da frota (CC-KBC, CC-KBD, N412KB, N67KB) ficam
paradas na base ou estação onde o Portal já as marca hoje (`pos`);
quando mais de uma aeronave está no mesmo aeroporto (N412KB e N67KB,
ambas em PAFA), os marcadores recebem um pequeno deslocamento pra não
ficarem um em cima do outro. O painel flutuante lista as duas seções
("Em voo" / "Em solo") e clicar numa linha foca o mapa na aeronave
correspondente e abre o popup dela — mesmo comportamento clicando
direto no marcador. Aeroportos da rede sem tráfego no momento aparecem
como pontos discretos, sem rótulo, só pra dar contexto geográfico.

**Clima em tempo real.** Ao contrário da posição das aeronaves (que é
simulada), o clima nesta tela é dado real, consumido direto do
navegador em duas APIs públicas gratuitas e sem chave — nenhuma delas
passa pelo backend Symfony, que continua sem nenhuma dependência de
API externa:

- **Open-Meteo** (`api.open-meteo.com`) dá a condição atual dos 11
  aeroportos da rede numa única chamada (coordenadas dos 11 em lote) —
  temperatura, vento, rajada e o código de tempo (WMO), que
  `mapa-ao-vivo.js` traduz em Claro/Nublado/Névoa/Garoa/Chuva/Neve/
  Tempestade. O ponto de cada aeroporto no mapa muda de cor conforme a
  condição, e aeroportos com algo além de céu claro/nublado (chuva,
  neve, névoa, tempestade) ganham um selo de texto — céu limpo não
  precisa de selo, só o ponto colorido já basta. Atualiza a cada 12
  minutos.
- **RainViewer** (`api.rainviewer.com`) dá o radar de precipitação em
  imagem, sobreposto ao mapa como uma camada (liga/desliga pelo
  interruptor "Radar de precipitação" no painel). Abre parado no frame
  mais recente — o botão "▶ Animar" ao lado é que faz percorrer em
  loop os últimos ~80 minutos de frames observados (a cada ~10 min de
  intervalo real); a lista de frames é atualizada a cada 10 minutos.

Como os aeroportos da rede ficam em regiões remotas (interior do
Alasca, Patagônia), a cobertura de radar de precipitação pode ser
fraca ou inexistente dependendo do lugar — isso é uma limitação real
da cobertura de radar meteorológico nessas regiões, não um bug; a
condição por aeroporto (Open-Meteo) não depende de radar e funciona em
qualquer coordenada do globo. Se alguma das duas APIs estiver fora do
ar, sem rede ou bloqueada por CORS, a chamada falha em silêncio
(`console.warn`) e o resto da tela continua funcionando normalmente —
o radar mostra "Radar indisponível" e desliga o interruptor sozinho; o
clima por aeroporto simplesmente não aparece, sem quebrar o mapa nem
os marcadores de aeronave.

## Agendamento de voo (`/agendamentos`)

Diferente de "Novo voo" (que registra um voo que **já aconteceu**),
esta tela reserva uma aeronave da frota pra um voo **futuro** — "vou
pegar essa aeronave hoje à noite e voar pra tal lugar", sem precisar
saber a hora exata, só uma janela. O ponto central é permitir agendar
**mais de uma perna seguida** pra mesma aeronave: se o destino do
último agendamento dela bate com a origem do próximo, as duas contam
como uma sequência encadeada — dá pra ver de relance o plano de voo da
aeronave pros próximos dias, não só uma reserva isolada.

Cada aeronave da frota tem um card com duas partes: uma linha do tempo
resumida no topo (`SCCI → SCNT → SCBA → SCCI`, por exemplo) só pra dar
a ideia da sequência de relance, e uma tabela com o detalhe de cada
perna (janela de horário, tipo de operação, piloto, notas). A coluna
"Sequência" da tabela (e a cor da seta na linha do tempo) mostra se
aquela perna **continua** de onde a anterior termina (`Continua de
SCNT`, em verde) ou se **muda** de posição sem uma perna de
reposicionamento no meio (`Muda de SCNT → SCBA`, em laranja) — a
primeira perna de cada aeronave é comparada contra a posição atual
dela (`pos`, mesmo campo do Portal/Mapa ao vivo) em vez da perna
anterior. Isso é só um aviso, não bloqueia o agendamento — pode ser um
reposicionamento manual que o piloto sabe que vai fazer.

O que **bloqueia** de verdade é duas reservas da mesma aeronave com
janelas de horário sobrepostas (checado em `agendamento.js` sempre que
o formulário muda) — regra pensada pra já deixar o comportamento certo
de quando existir mais de um piloto reservando aeronaves ao mesmo
tempo. O formulário sugere automaticamente a Origem da nova perna: a
posição atual da aeronave se ela não tem nada agendado ainda, ou o
destino do último agendamento dela se já tem uma sequência em
andamento — o campo continua editável (dá pra sobrescrever pra um
reposicionamento fora da sequência, com o aviso de "muda de" aparecendo
na tabela depois).

Cada perna tem **Editar** (reabre o mesmo formulário já preenchido,
trocando "Agendar voo" por "Salvar alterações" — a validação de
sobreposição e de sequência esperada passa a ignorar o próprio
agendamento sendo editado) e **Remover**, com confirmação em duas
etapas no próprio botão ("Remover" → "Confirmar?" por 4 segundos) em
vez de um `confirm()` nativo do navegador, que destoaria do resto da
interface.

Acima dos cards por aeronave tem a **Agenda da semana**: todas as
pernas de todas as aeronaves juntas, em ordem cronológica e agrupadas
por dia (`Hoje`/`Amanhã`/`dd-mm`) — é a visão pra responder "o que
acontece nas próximas horas", cruzando aeronaves, o que os cards por
aeronave (focados na sequência de *uma* aeronave por vez) não mostram
de relance. Tem busca por piloto, matrícula, rota ou tipo de operação,
que filtra só essa lista (os cards por aeronave abaixo continuam
mostrando o quadro completo, sem filtro — a Agenda é pra achar rápido,
os cards são pra contexto). Os rótulos "Hoje"/"Amanhã" (usados também
na coluna "Janela" dos cards por aeronave) vêm de `window.KATABATIC_NOW`
— o mesmo "agora" mock usado no resto do app.

É tudo mock (Solicitações deixou de ser o exemplo — ver "Backend:
adesão e solicitações" — mas o padrão era o mesmo): `AgendamentoController`
só monta o estado inicial (seis aeronaves da frota, seis pernas
já agendadas — duas sequências completas em CC-KBC e N412KB, uma
perna com aviso de posição em N67KB, e CC-KBD/CC-KBA/N208KB sem nada
agendado pra mostrar o estado vazio) e `agendamento.js` manipula um
array em memória a partir daí; criar, editar ou remover um agendamento
não persiste entre reloads.

**Ligação com o ACARS (ainda não implementada — é o design pretendido
quando o feed real existir):** ao chegar um evento de início de sessão
real do ACARS pra uma aeronave que tem agendamento em aberto, o
backend deveria tentar casar o evento com o agendamento usando
matrícula da aeronave + sobreposição de horário (não pelo callsign,
que é um dado mais frágil — pode nem estar preenchido ainda). Se
casar, o agendamento é promovido pra "em andamento" e depois
"concluído", virando a linha do Logbook direto (sem duplicar); se não
casar com nada, cai no comportamento de hoje do "Novo voo" (cria um
voo solto); se casar com mais de um agendamento em aberto ao mesmo
tempo pra mesma aeronave (não devia acontecer com a validação de
sobreposição funcionando, mas por segurança), fica sinalizado pra um
admin resolver manualmente em vez do sistema adivinhar.

## Manuais e Operações (`/manuais`)

Seção nova no rail (grupo "Operação", depois de Agendamentos — aberta a
qualquer piloto, não é área de admin) reservada pra documentação e
ajuda: hub em `/manuais` listando cards de manuais, cada um com sua
própria rota/template (`ManuaisController::manuals()` define
título/tag/resumo/rota de cada card; sem rota = `ready: false`, card
aparece desabilitado com a tag "Em breve"). Diferente do resto do app,
essa seção não tem dado mock nenhum — cada manual é conteúdo estático
escrito direto no template.

Só existe um manual de verdade até agora: **Fraseologia VATSIM**
(`/manuais/fraseologia-vatsim`), com a fraseologia padrão de
autoanúncio em UNICOM/CTAF em inglês, por etapa do voo (radio check,
táxi, antes da decolagem, decolagem, em rota, aproximação, circuito de
tráfego, pouso, e uma seção de emergência com Mayday/Pan-Pan) — faz
sentido como primeiro manual porque a maior parte da rede Katabatic
opera sem torre (as estações avançadas do Ártico/Patagônia, e boa
parte do tempo em PAFA/SCCI também). Cada chamada-modelo vem com
placeholders entre colchetes (`[Airport]`, `[Callsign]`, `[XX]`) e um
botão "Copiar" (`manual-fraseologia.js`, usa `navigator.clipboard`,
com fallback via `textarea` + `execCommand` pra navegador sem Clipboard
API). A página segue o padrão do resto do app — cabeçalho/rail em
português, conteúdo profundo (aqui, as chamadas de rádio em si) em
inglês, já que ensinar fraseologia em inglês é o propósito do manual,
não uma tradução de interface. Tem também um sumário fixo (`.m-toc`)
ao lado do texto com link pra cada seção.

Depois dos templates por etapa (que ficam com placeholder entre
colchetes, tipo `[Airport]`/`[Callsign]`/`[XX]`) tem um **exemplo
completo** (`#exemplo`, entre "Pouso" e "Emergência" no sumário): uma
perna inteira, do radio check ao "clear of runway" no destino, com
tudo preenchido de verdade em vez de placeholder — Katabatic 128
(N412KB, DHC-2 Beaver) voando PFYU → PAKP, a mesma perna que já existe
como mock no Logbook (ver `PortalController::logbook()`, id `KBT128`).
A ideia é fechar o ciclo "aqui está o molde" → "aqui está ele
preenchido, em sequência" antes de ir pra fraseologia de emergência,
que é um caso à parte. O texto do exemplo também chama atenção pra uma
pegadinha real de fraseologia: no template escrito usa dígito (`runway
21`), mas falado em voz alta pista/rumo/frequência são sempre um
dígito de cada vez ("two one"), enquanto altitude é a exceção e se fala
por extenso ("three thousand five hundred").

Os outros três cards do hub ("Procedimentos de emergência",
"Meteorologia polar", "Operação em pistas não pavimentadas") são só
placeholders — mostram o que vem a seguir sem prometer uma data, mesmo
espírito transparente do roadmap no fim deste documento.

## Perfil do piloto (`/perfil`)

Tela de configurações da conta, aberta pelo próprio cartão do piloto no
rodapé do rail (que virou link — antes era só um `<div>` decorativo).
`PerfilController` tem duas rotas (`GET`/`POST` em `/perfil`): a de
leitura renderiza o formulário com os dados atuais da sessão, a de
escrita valida nome (obrigatório), e-mail (obrigatório, formato
validado com `filter_var(..., FILTER_VALIDATE_EMAIL)`) e a foto
enviada (opcional — tipo MIME em `image/jpeg`, `image/png` ou
`image/webp`, tamanho máximo 3 MB), segue o padrão Post-Redirect-Get
(sucesso redireciona de volta pra `/perfil` via GET; erro re-renderiza
o mesmo formulário com a lista de erros e o que a pessoa já tinha
digitado, sem perder o preenchimento).

Foto vai pra `public/uploads/avatars/` com nome gerado (evita colisão e
evita reusar o nome original do upload) e o caminho público fica salvo
no piloto da sessão; sem foto, o avatar cai pro badge de iniciais (ver
`.pilot-badge` no rail e `.avatar-preview` na própria tela), calculadas
a partir do nome (`PerfilController::initialsFrom()`). A pasta
`public/uploads/` é ignorada pelo git (`.gitignore`) menos o
`.gitkeep`, já que o conteúdo é gerado em runtime.

**Atualizado (backend real):** "salvar o perfil" agora é um `UPDATE`
de verdade na tabela `pilot` via Doctrine (`$em->flush()`), não mais
só um array de sessão — ver a seção "Backend: login e perfil" logo
abaixo pra como isso foi ligado. `PerfilController::update()` também
confere se o e-mail novo já não está em uso por outro piloto
(constraint única no banco, checada antes de tentar salvar, pra dar um
erro amigável em vez de um 500 de violação de unicidade).

Sucesso na gravação usa flash message do Symfony (`addFlash('success',
...)`/`app.flashes('success')`) — primeiro uso desse mecanismo no
projeto —, exibida como um banner (`.flash`) no topo de `.main` em
`app_base.html.twig`, com botão de fechar. Como é lido direto do
`app_base` compartilhado, qualquer tela logada pode usar o mesmo padrão
no futuro (`$this->addFlash('success', '...')` no controller já é
suficiente).

## Backend: login e perfil (Doctrine + Security)

Primeira fatia real de backend do projeto — tudo antes disso era
mockup em array PHP + sessão. Login e Perfil agora são de verdade
(banco + Security do Symfony); as outras onze telas da área logada
continuam exatamente como estavam (ver "Estratégia de transição"
abaixo pra como as duas coisas convivem sem quebrar nada).

**O que existe agora:**

- `App\Entity\Pilot` (`src/Entity/Pilot.php`) — tabela `pilot`
  (`cid`, `name`, `email`, `password` com hash, `photo`, `admin`,
  `created_at`). Implementa `UserInterface` +
  `PasswordAuthenticatedUserInterface` do Security. `cid` é o
  "username" do sistema — cada CID VATSIM já é único por natureza.
  `getInitials()` continua calculado na hora a partir do nome, não é
  coluna (mesma lógica que já existia em `PerfilController`).
- `App\Repository\PilotRepository` — `findOneByCid()`, mais
  `PasswordUpgraderInterface` (o Security re-grava o hash sozinho se
  algum dia o custo do bcrypt configurado mudar).
- `App\Security\LoginFormAuthenticator` — autenticador customizado
  (não o `form_login` genérico do Symfony, pra manter controle total
  sobre a mensagem de erro e o template já existentes). GET e POST em
  `/login` são a mesma rota (`app_login`) — convenção padrão do
  Security pra login por formulário: o POST nunca chega no
  `LoginController`, o autenticador responde antes.
- `config/packages/security.yaml` — `password_hashers` fixado em
  `bcrypt` (não `'auto'`) de propósito: custo determinístico,
  garantindo que o hash semeado pela migration bate certo não importa
  quais extensões o PHP do servidor tem.
- Migration `migrations/Version20260821120000.php` — cria a tabela
  `pilot` e semeia o piloto de desenvolvimento que já existia como
  mock: **CID `1234567`, senha `katabatic-dev`** (hash gerado com
  `password_hash('katabatic-dev', PASSWORD_BCRYPT)`, direto no PHP,
  sem precisar do Symfony rodando). Troque essa senha (ou apague a
  linha do `INSERT` na migration) antes de qualquer ambiente que não
  seja a sua máquina de desenvolvimento.

**Estratégia de transição:** Portal, Voo, Novo voo, Nova aeronave,
Histórico de aeronave, Mapa ao vivo, Agendamentos, Manuais e
Solicitações/Pilotos ainda fazem seu próprio guard manual, lendo
`$request->getSession()->get('pilot')` como um array simples (mesmo
formato de sempre: `initials`/`name`/`cid`/`admin`/`email`/`photo`) —
nenhuma delas sabe nada de Security ainda. Pra não precisar migrar as
onze de uma vez só, `LoginFormAuthenticator::onAuthenticationSuccess()`
grava esse mesmo array na sessão depois de autenticar de verdade, e
`PerfilController::update()` também o atualiza depois de salvar — o
Security cuida da autenticação real, e o resto do site continua
enxergando exatamente o que já esperava. Por isso
`config/packages/security.yaml` não tem `access_control`: a
autorização em si (quem pode ver o quê) continua manual, tela por
tela, até essas onze migrarem também — próximo passo natural do
backend depois desta fatia.

**Lacunas conhecidas, de propósito, nesta primeira fatia:**

- Sem proteção CSRF no formulário de login ainda (precisaria de
  `symfony/security-csrf` + `CsrfTokenBadge` no autenticador) — ok pra
  ambiente de dev, mas deve entrar antes de qualquer coisa exposta de
  verdade.
- Sem "esqueci minha senha" — o único jeito de um piloto novo ganhar
  senha é a senha temporária mostrada uma vez no momento da aprovação
  (ver "Backend: adesão e solicitações" abaixo); se ela se perder,
  hoje só rodando SQL/uma fixture na mão pra trocar o hash.

**Como ligar isso na sua máquina** (nada disso roda neste ambiente de
sandbox — sem acesso de rede ao Packagist nem Docker aqui, então tudo
foi escrito à mão e validado só com `php -l`/lint estático; rode e
teste do seu lado):

```bash
# 1. Instala as dependências novas (composer.json já foi editado à
#    mão, então é `update`, não `install`, pra essas ficarem no lock)
composer update doctrine/dbal doctrine/doctrine-bundle doctrine/orm \
  doctrine/doctrine-migrations-bundle doctrine/doctrine-fixtures-bundle \
  symfony/security-bundle symfony/password-hasher symfony/maker-bundle

# 2. Sobe o Postgres (docker-compose.yml já existia, não mudou)
docker compose up -d

# 3. Cria o banco se ainda não existir, e roda a migration
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate

# 4. Login em /login: CID 1234567, senha katabatic-dev
```

Se `composer update` reclamar de versão de PHP/extensão faltando,
compare com `composer.json` (`php: ">=8.2"`) - o resto das versões
(`^3.x`/`7.2.*`) foi escolhido pra combinar com o que já estava
instalado, mas o Composer é quem resolve o grafo de verdade.

## Backend: adesão e solicitações

Segunda fatia de backend — liga as duas pontas que antes eram mock
independentes: o formulário público (`/adesao`) e o grid de
Solicitações da área logada (`/solicitacoes`). Ver "Estratégia de
transição" na seção anterior: esta fatia também não muda nada no
guard manual de sessão das outras telas.

**O que existe agora:**

- `App\Entity\MembershipRequest` (`src/Entity/MembershipRequest.php`)
  — tabela `membership_request`, um pedido por linha (`name`, `email`,
  `cid`, `experience`, `basePref`, `discord`/`heardAbout` opcionais,
  `motivation`, `status` começando em `'pendente'`, `createdAt`,
  `decidedAt`).
- `AdesaoController::submit()` (POST `/adesao`, JSON) — valida nome,
  e-mail, CID (regex `\d{6,8}`), motivação e os valores de experiência/
  base (mesmos valores dos chips do formulário), recusa CID que já é
  de um piloto ou que já tem um pedido pendente, e devolve `422` com a
  lista de erros ou `201` com o `id`/código do pedido
  (`KADE-%04d`, agora rastreável de verdade). `adesao.js` faz o
  `fetch` e trata as duas respostas — sucesso mostra o card de
  confirmação com o código real, erro mostra o motivo acima do
  formulário sem perder o preenchimento.
- `SolicitacoesController::aprovar()`/`rejeitar()` (POST
  `/solicitacoes/{id}/aprovar|rejeitar`, JSON, mesmo guard de admin do
  `index()`) — rejeitar só marca o pedido; aprovar cria um `Pilot` de
  verdade com uma **senha temporária aleatória** (8 caracteres hex,
  `random_bytes(4)`, hasheada na hora com o mesmo `UserPasswordHasherInterface`
  do Security) quando ainda não existe piloto com aquele CID, ou só
  liga o pedido a um piloto já existente (sem gerar senha nova) se já
  existir. A senha temporária volta uma única vez na resposta e
  `solicitacoes.js` mostra um banner verde com ela — não fica
  guardada em texto puro em lugar nenhum além dessa resposta única;
  se o admin fechar o banner sem anotar, só resetando via SQL/fixture
  (ver "Lacunas conhecidas" acima). **Não há envio de e-mail** — passar
  a senha pro piloto novo é manual (Discord, e-mail avulso etc.).
- `Pilot` ganhou duas colunas novas nesta fatia: `base` (preenchida a
  partir da `basePref` do pedido — `'Sem preferência'` vira `'PAFA'`)
  e `active` (sempre `true` num piloto novo — sem fluxo de desativação
  ainda). Ambas usadas só no grid de Pilotos em Solicitações.
- Migration `migrations/Version20260821130000.php` — cria
  `membership_request` e adiciona `pilot.base`/`pilot.active`.

**Lacunas conhecidas, de propósito, nesta fatia:**

- Sem CSRF nos POSTs de `/adesao` e `/solicitacoes/{id}/...` — mesmo
  gap já anotado pro login.
- Sem envio de e-mail (nem da confirmação do pedido, nem da senha
  temporária) — tudo isso é comunicado fora do sistema por enquanto.
- `voos` no grid de Pilotos continua fixo em `0` pra todo mundo — só
  passa a refletir a realidade quando o schema de voos/telemetria
  existir (ver "Backend: voos e telemetria" logo abaixo — ainda não
  religado nesta fatia, ver "Lacunas conhecidas" lá).

## Backend: voos e telemetria

Terceira fatia de backend — substitui os dois lugares que guardavam
dado de voo antes disso (o array mock em `PortalController::logbook()`
e o arquivo estático `public/assets/data/flights.json`, lido direto
pelo navegador de qualquer um com a URL) por uma única tabela real:
`voo`.

**O que existe agora:**

- `App\Entity\Voo` (`src/Entity/Voo.php`) — cada linha é um voo do
  Logbook. Só um punhado de colunas são "de verdade" (o que a tela
  filtra/ordena: `pilot`, `codigo`, `callsign`, `tipoOperacao`,
  `origem`, `destino`, `aeronaveReg`, `startedAt`, `tempoMin`,
  `dificuldade`); o resto — rota, modelo, condição, ocorrências,
  distância, combustível, carga, tempos de solo/ar, METAR, o relato do
  piloto e (quando existe) a telemetria inteira — fica dentro de uma
  coluna `json` (`dados`). Decisão deliberada: normalizar tudo em
  colunas/tabelas próprias (uma linha por amostra de telemetria, por
  exemplo) seria prematuro sem um banco de verdade neste ambiente pra
  testar contra, e sem ainda existir a ingestão real do ACARS que vai
  definir o volume/formato real de amostra — ver "Lacunas conhecidas".
  `codigo` (o antigo `flightId`) só existe pros voos que têm telemetria
  gravada; é o que decide quais linhas do Logbook ficam clicáveis
  (mesma regra de sempre, ver `portal.js`) e quais aparecem em `/voo`.
- `App\Repository\VooRepository` — `findAllForPilot()` (Logbook
  inteiro), `findComTelemetriaForPilot()` (só quem tem `codigo`, é a
  lista que `/voo` usa), `findOneByCodigoForPilot()` (relatório de um
  voo específico, já conferindo que é do piloto certo).
- `PortalController::index()` monta o Logbook a partir de
  `VooRepository::findAllForPilot()` (`logbookViewModel()` traduz cada
  `Voo` pro mesmo formato de array que `portal.js` sempre esperou —
  zero mudança no JS) e `logbookSummary()` agora calcula horas/voos/
  dificuldade média de verdade a partir desses dados em vez de vir
  fixo (`vatsimPct` continua 100 fixo — validar contra o datafeed da
  VATSIM é trabalho da ingestão real do ACARS).
- `VooController` ganhou duas rotas novas: `GET /voo/telemetria`
  devolve, em JSON, a telemetria de todos os voos do piloto logado que
  têm `codigo` — **exatamente** o mesmo formato de objeto que
  `flights.json` tinha (`label`/`wx`/`track`/`prof`/`env`/`events`/
  `phases`/`parcels`/`score`/.../`td`/`orig`/`dest`), então `voo.js`
  não mudou nada além de onde busca esse JSON (`flightsUrl` no
  template agora aponta pra essa rota, não mais pro arquivo estático).
  `POST /voo/{codigo}/relato` salva de verdade o relato do piloto — ver
  próximo bullet.
- **Relato do piloto agora persiste.** Antes desta fatia, o botão
  "Salvar relato" só mudava `F.pilot_report` em memória no navegador —
  um F5 apagava tudo. Agora `voo.js` faz um `fetch` POST pra rota
  acima, que grava dentro de `Voo::$dados['pilotReport']` via
  `Voo::setPilotReport()`; a tela mostra erro (sem perder o texto
  digitado) se o POST falhar.
- `bin/console app:importar-voos-legados` — os 11 voos que existiam
  como mock (3 com telemetria real do ACARS + 8 só narrativos) viram
  linhas de verdade na tabela, todos atribuídos ao piloto semeado pela
  migration de Login (CID `1234567` — não existe outro piloto de
  desenvolvimento pra atribuir isso corretamente). Idempotente: roda de
  novo sem duplicar. A telemetria dos 3 voos reais (~70 KB de JSON) é
  lida direto de `flights.json` em vez de transcrita à mão numa
  migration — ver docblock da classe pra por quê.
- Migration `migrations/Version20260821150000.php` — só cria a tabela
  `voo` (FK pra `pilot`, índice único em `codigo`); os dados em si
  entram pelo comando acima, depois de migrar.

**Preço documentado de misturar mock com dado real (mantido de
propósito):** os 3 voos com telemetria gravada foram capturados como
voos de teste locais perto de Yakutsk (UEEE→UEEE, ~10 minutos reais,
~9-13 nm) — nada a ver com a rota/duração/distância narrativas que o
Logbook sempre mostrou pra eles (ex.: "Fairbanks → Bettles", 52
minutos, 168 nm, ver `ImportarVoosLegadosCommand`). O import
**preserva** essa inconsistência: `tempoMin`/`dificuldade` (colunas de
verdade, o que o Logbook exibe) continuam os valores narrativos
antigos, não os da telemetria real — só a tela `/voo` (que lê
`dados['telemetria']` direto) mostra os números reais de duração/
distância/score daquele voo de teste. Resolve sozinho quando a
ingestão real do ACARS existir e um voo passar a ser uma coisa só
(plano de voo e telemetria gravada, não duas fontes independentes).

**Lacunas conhecidas, de propósito, nesta fatia:**

- ~~Frota continua mock~~ Resolvido na fatia seguinte, ver "Backend:
  mapa ao vivo e histórico da frota" (`App\Entity\Aeronave`).
  `aeronaveReg` em `Voo` continua guardando a matrícula como texto
  solto, sem FK pra `aeronave` (funciona pelo valor da string, não por
  relação — ver essa seção pra detalhe). `Pilot::$voos` (contagem de
  voos no grid de Pilotos em Solicitações) continua em 0, isso não fazia
  parte daquela fatia.
- "Novo voo" (`/novo-voo`) continua sem gravar nada — o seletor de
  aeronave já usa a Frota real (ver "Backend: mapa ao vivo e histórico
  da frota"), mas falta o POST de criação do voo em si.
- Sem paginação/streaming no `GET /voo/telemetria` — devolve a lista
  inteira do piloto de uma vez, igual o arquivo estático fazia. Ok com
  3 voos de teste; não escala pra um piloto com centenas de voos
  gravados de verdade.
- A telemetria em si continua uma coluna `json` por voo (não
  normalizada por amostra) — ver decisão em "O que existe agora". Isso
  muda quando a ingestão real do ACARS (`docs/payload-telemetria-acars.md`)
  for implementada — aí sim faz sentido desenhar o schema definitivo
  de amostras, com volume e padrão de consulta reais pra guiar a
  decisão em vez de adivinhar.
- Sem validação cruzada com VATSIM, sem cálculo de índice de
  dificuldade no servidor, sem busca de METAR/TAF — tudo isso é
  trabalho da ingestão real do ACARS (ver seção 7 do contrato de
  telemetria), não desta fatia de import/consulta.

**Como ligar isso na sua máquina** (depois de rodar as migrations —
ver "Backend: login e perfil" mais acima pro passo a passo geral):

```bash
php bin/console doctrine:migrations:migrate   # cria a tabela voo (entre outras pendentes)
php bin/console app:importar-voos-legados     # importa os 11 voos + telemetria de flights.json
```

## Backend: mapa ao vivo e histórico da frota

Quarta fatia de backend — substitui os quatro lugares que guardavam
essencialmente a mesma lista mock de aeronaves (`PortalController::fleet()`,
`NovoVooController::aircraftFleet()`, `AeronaveController::fleet()` e
`MapaAoVivoController::liveFlights()`/`parkedAircraft()`) por uma única
tabela real: `aeronave`. De quebra, o histórico de aeronave
(`/aeronave/{reg}`) e o Mapa ao vivo (`/mapa-ao-vivo`) passam a consultar
dado de verdade em vez de arrays fixos ou de um gerador sintético.

**O que existe agora:**

- `App\Entity\Aeronave` (`src/Entity/Aeronave.php`) — cada linha é uma
  aeronave da frota (`reg`, `pais`, `tipo`, `base`, `posIcao`, `status`,
  `limiteG`, `vsLimiteFpm`, `horas`, `observacoes`). `getStatusTag()`
  (cor do `<span class="tag tag-...">`) é sempre **calculada** a partir
  de `status`, nunca guardada numa coluna própria — evita as duas coisas
  saindo de sincronia.
- `App\Repository\AeronaveRepository` — `findOneByReg()`/`existsByReg()`
  (usados na validação de "Nova aeronave"), `findAllOrderedByBaseAndReg()`
  (Frota do Portal e seletor de aeronave do Novo voo),
  `findAllEmVoo()`/`findAllNotEmVoo()` (Mapa ao vivo).
- **"Salvar aeronave" agora persiste de verdade.** `NovaAeronaveController`
  ganhou `POST /nova-aeronave`: valida país/matrícula/tipo/base (mesmas
  regras que já existiam no JS — prefixo por país, base só PAFA/SCCI),
  confere duplicidade de matrícula e grava um `Aeronave` novo
  (`status` sempre começa `'Disponível'`, posição = base escolhida).
  `nova-aeronave.js` faz o `fetch` e redireciona pro Portal (view Frota)
  no sucesso; mostra erro (validação ou falha de rede) sem perder o que
  foi preenchido, mesmo padrão de `adesao.js`.
- `PortalController` (Frota) e `NovoVooController` (seletor de aeronave
  do Novo voo) agora leem `AeronaveRepository::findAllOrderedByBaseAndReg()`
  em vez de um array próprio cada um. `fleetSummary()` (contagem/horas
  totais/em voo/fora de base) também passou a ser calculado a partir da
  frota de verdade.
- **Histórico de aeronave (`/aeronave/{reg}`) usa o Logbook de
  verdade.** Antes desta fatia, `AeronaveController` gerava um histórico
  sintético de ~17 pernas por matrícula (offsets determinísticos, rotação
  de estação por rede, 3 "casos reais" amarrados a `flights.json`) só
  pra ter volume suficiente pra demonstrar o filtro de período. Agora
  usa `VooRepository::findAllByAeronaveReg()` (todo voo já registrado com
  essa matrícula, de qualquer piloto) — dado real, mas hoje pequeno
  (1-2 pernas por aeronave, já que só existem 11 voos gravados no
  total); cresce sozinho conforme o Logbook cresce, sem gerar nada
  artificialmente.
- **Mapa ao vivo (`/mapa-ao-vivo`) usa a frota de verdade pra decidir
  quem mostrar.** `liveFlights()` itera `AeronaveRepository::findAllEmVoo()`
  e, pra cada uma, busca o voo com telemetria mais recente dela
  (`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`) — se
  não achar nenhum, a aeronave simplesmente não aparece na lista (não
  tem gravação nenhuma pra simular movimento dela). `parkedAircraft()`
  lista `findAllNotEmVoo()` direto. A posição no mapa em si continua
  simulada (replay em loop da telemetria, ver seção "Mapa ao vivo" mais
  acima) — isso só muda quando existir ingestão real do ACARS.
- `bin/console app:importar-frota-legada` — as 6 aeronaves que existiam
  como mock (CC-KBA, CC-KBC, CC-KBD, N208KB, N412KB, N67KB, com os
  mesmos valores de tipo/base/posição/status/horas de sempre) viram
  linhas de verdade na tabela `aeronave`. Idempotente: roda de novo sem
  duplicar (dedup por `reg`).
- Migration `migrations/Version20260821160000.php` — só cria a tabela
  `aeronave` (índice único em `reg`); os dados em si entram pelo comando
  acima, depois de migrar.
- `portal.js` — removida a lista fixa `AC_HISTORY_REGS` que limitava o
  link "Ver histórico"/"Histórico ›" às 6 matrículas do mock antigo;
  agora aparece pra qualquer linha da Frota, já que `/aeronave/{reg}`
  faz uma consulta de verdade pra qualquer matrícula cadastrada.

**Lacunas conhecidas, de propósito, nesta fatia:**

- `status`/`posIcao` não são atualizados por nenhum evento automático —
  seguem o mesmo limite que o mock já tinha: começam no que
  `NovaAeronaveController` define na criação (`'Disponível'`, posição =
  base) e só mudam se alguém mexer direto no banco. Isso é trabalho da
  ingestão real do ACARS (ver README, seção de telemetria).
- Fotos do cadastro de aeronave continuam só maquete visual — os slots
  nunca tiveram um `<input type="file">` por trás, mesmo no mockup
  original; upload de foto fica fora do escopo desta fatia.
- `horas` no cadastro é só o ponto de partida digitado — não é
  recalculado a partir do Logbook (soma de `tempoMin` dos voos daquela
  matrícula) ainda.
- Histórico de aeronave hoje mostra pouco volume (1-2 pernas por
  matrícula) porque só existem 11 voos importados no total — ver
  "Backend: voos e telemetria" pra por quê.

**Como ligar isso na sua máquina** (depois de rodar as migrations —
ver "Backend: login e perfil" mais acima pro passo a passo geral):

```bash
php bin/console doctrine:migrations:migrate   # cria a tabela aeronave (entre outras pendentes)
php bin/console app:importar-frota-legada     # importa as 6 aeronaves mock
```

## Backend: ingestão ACARS (MVP)

Quinta fatia de backend — a primeira que traz voo de verdade pro Logbook
sem passar por um comando de import de dado legado. Até aqui, a única
forma de uma linha nova aparecer em `voo` era `app:importar-voos-legados`
(que só repete os mesmos 11 voos de sempre); `/novo-voo` nunca chegou a
gravar nada. Esta fatia liga o script de captura que já existia
(`tools/acars-capture/katabatic_capture.py`, rodado no PC do piloto via
SimConnect/MSFS 2024) num endpoint novo do Symfony, pra popular dado real.
Ficou em duas rodadas: primeiro só o POST de fechamento (Logbook/horas/
histórico), depois (mesma fatia, "fase 2") o status "Em voo" em tempo real
pro Mapa ao vivo — ambas descritas abaixo.

**Importante: isto NÃO é o cliente/servidor completo do contrato ACARS**
(`docs/payload-telemetria-acars.md`, v1.1) — aquele documento descreve uma
arquitetura bem maior (sessão aberta/fechada, telemetria em streaming de
lotes, fila SQLite com reenvio, gzip, recorte GRIB, busca de METAR,
validação cruzada com VATSIM), boa parte dela ainda pendente mesmo no
próprio documento. Decisão tomada em conversa: começar pelo pedaço mínimo
que já popula dado real — um único envio ao final do voo, autenticado por
um token fixo — e deixar sessão em tempo real/GRIB/METAR/VATSIM pra uma
próxima fatia.

**O que existe agora:**

- `tools/acars-capture/katabatic_capture.py` (script que já existia,
  atualizado) — o modo `--record` continua gravando `samples.csv`/
  `env.csv`/`events.csv`/`session.json` local exatamente como antes (nada
  muda pra quem só quer capturar sem enviar). Novidade: também guarda essas
  mesmas linhas em memória e, se `--pilot-cid`/`--tipo`/`--origem`/
  `--destino`/`--server` forem passados (por `katabatic.bat` ou
  diretamente), monta um payload JSON com tudo isso e faz um único
  `POST {server}/api/acars/v1/voos` quando a gravação termina (Ctrl+C).
  Sem esses argumentos, o script se comporta 100% como antes — só grava
  local. O payload é sempre salvo em `upload_payload.json` dentro da pasta
  da gravação, sirva o envio ou não — é a rede de segurança desta fatia:
  sem fila/retry automático, se o POST falhar o arquivo fica pronto pra
  reenviar manualmente depois.
- `tools/acars-capture/katabatic.bat` — bloco de configuração no topo
  (`KATABATIC_SERVER`/`KATABATIC_ACARS_TOKEN`/`KATABATIC_PILOT_CID`,
  preenche uma vez) e o atalho de gravação passa a aceitar
  `katabatic.bat KBT118 PAFA PABT carga` (callsign origem destino tipo) —
  `katabatic.bat KBT118` sozinho continua funcionando, só sem enviar nada.
- `App\Service\TelemetryDeriver` (`src/Service/TelemetryDeriver.php`) — o
  "servidor deriva" da seção 7 do contrato, versão mínima: recebe as
  amostras/ambiente/eventos crus e monta o mesmo blob que `flights.json`
  sempre usou (`track`/`prof`/`env`/`events`/`phases`/`parcels`/`score`/
  `dur`/.../`td`/`orig`/`dest`) — `voo.js` não muda uma linha pra exibir um
  voo real do ACARS. Serviço puro (zero dependência de Doctrine/Symfony),
  então testável isolado com `php -r` e um payload sintético.
- `POST /api/acars/v1/voos` (`src/Controller/Api/AcarsIngestaoController.php`)
  — autenticação por `Authorization: Bearer <token>` contra um token único
  fixo (`ACARS_TOKEN` no `.env`, não por piloto ainda). Valida o payload,
  resolve `Pilot` por `pilot_cid` e `Aeronave` por `aeronave_reg` (422 se
  qualquer um não existir — a aeronave precisa estar cadastrada em
  "Nova aeronave" antes), roda o `TelemetryDeriver` e persiste um `Voo` de
  verdade. Idempotente: `codigo` (nome da pasta de gravação,
  `AAAAMMDD_HHMMSS_CALLSIGN`) é a chave — um POST repetido (retry manual
  depois de falha de rede) encontra o voo já criado e devolve ele em vez de
  duplicar (`VooRepository::findOneByCodigo()`).
- Efeito colateral em `Aeronave`: soma as horas do voo e atualiza
  `posIcao` pro destino; e (fase 2) devolve o `status` pra "Disponível" e
  limpa `emVooDesde` — ver bullet abaixo.
- **Fase 2 — `POST /api/acars/v1/voos/iniciar`** (mesmo controller,
  mesma autenticação por `Authorization: Bearer`) — chamado automaticamente
  pelo script assim que a gravação começa (não precisa mais esperar o voo
  terminar pra algo acontecer no servidor). Valida `pilot_cid`/
  `aeronave_reg`/`started_at`, e marca `Aeronave::status = 'Em voo'` +
  `Aeronave::emVooDesde = started_at`. Os dois endpoints são
  independentes de propósito: se `iniciar` falhar (sem rede no começo do
  voo, por exemplo), a gravação continua normal e o `POST .../voos` de
  fechamento ainda roda no final — só o Mapa ao vivo não mostra "Em voo"
  durante esse voo específico. Se `iniciar` funcionar mas o de fechamento
  falhar (PC do piloto trava no meio do voo), a aeronave ficaria "Em voo"
  pra sempre sem o mecanismo abaixo.
- `Aeronave::emVooDesde` (nova coluna, `migrations/Version20260821170000.php`)
  + `Aeronave::getStatusEfetivo()` — em vez de um comando agendado
  "limpando" status travado, a própria entidade se autocorrige na leitura:
  se `status === 'Em voo'` mas `emVooDesde` for nulo ou tiver mais de 8h
  (`Aeronave::EM_VOO_MAX_HORAS`), `getStatusEfetivo()` devolve
  "Disponível" em vez do valor bruto salvo. Todo lugar que exibe o status
  da frota (`PortalController`, `NovoVooController`, `AeronaveController`,
  `MapaAoVivoController`, `getStatusTag()`) já usa `getStatusEfetivo()` em
  vez de `getStatus()` — o valor bruto continua existindo (é o que os dois
  endpoints ACARS de fato escrevem), só a leitura é que nunca mais mostra
  um "Em voo" congelado por um crash.

**Heurísticas do `TelemetryDeriver`, documentadamente v1/aproximadas**
(revisar quando houver voos reais suficientes pra calibrar contra, e
quando as peças que faltam no contrato existirem):

- Fases de voo: corte simples por `vs_fpm`/`on_ground` (solo/subida/
  cruzeiro/descida), sem suavização sofisticada.
- Índice de dificuldade e parcelas: fórmula própria com referências de
  escala arbitrárias — turbulência e pico de G em particular dependem de
  uma linha de base por aeronave que o próprio contrato já marca como
  pendente ("existe piso de ruído por aeronave").
- Través (`windc`): aproximado pelo heading da aeronave no toque (ou o
  último heading conhecido), não pelo heading de pista de verdade — falta
  base de aeroportos com heading de pista (item já pendente no roadmap).
- METAR não é buscado — o campo fica `null` no Logbook (ver "Backend: voos
  e telemetria" pra por quê isso importa: é a única fonte de
  visibilidade/teto, já que as SimVars não acompanham o clima).

**Lacunas conhecidas, de propósito, nesta fatia:**

- A **posição** da aeronave no mapa continua sem vir de um feed ao vivo —
  a fase 2 só ligou o status booleano ("Em voo"/"Disponível") em tempo
  real; o marcador do Mapa ao vivo pra quem está "Em voo" continua sendo o
  replay em loop do último voo com telemetria gravada dessa matrícula
  (`MapaAoVivoController::liveFlights()`), não uma posição real vinda do
  ACARS. Isso só muda quando existir streaming de posição em tempo real
  (sessão aberta/fechada do contrato completo, ver "Próximos passos").
- Token único fixo pra todos os pilotos, não por piloto — revisar quando
  houver mais de um piloto usando ACARS ao mesmo tempo.
- Sem gzip, sem fila persistente com reenvio automático no cliente (só o
  `upload_payload.json` local como rede de segurança) — o contrato
  completo pede os dois pra escala de produção real.
- `carga`/`combustivelKg` no Logbook: o payload ACARS não manda peso de
  carga nenhum, então `carga` vira um texto genérico
  ("Não informada pelo ACARS") em vez de inventar um número.
- `horas` da aeronave soma em números inteiros (sem fração) — voos curtos
  demais podem somar 0h, mesma limitação que o cadastro já tinha.

**Como ligar isso na sua máquina:**

```bash
# aplica a migration da fase 2 (coluna aeronave.em_voo_desde):
php bin/console doctrine:migrations:migrate

# no .env do servidor, troque o valor de exemplo:
# ACARS_TOKEN=troque-este-valor-antes-de-usar

# no katabatic.bat (ou nos argumentos do script direto), preencha:
#   KATABATIC_SERVER=http://localhost:8080
#   KATABATIC_ACARS_TOKEN=<o mesmo valor de ACARS_TOKEN>
#   KATABATIC_PILOT_CID=<CID VATSIM de um piloto já cadastrado>

# no PC do piloto, com o MSFS já dentro do voo:
katabatic.bat KBT118 PAFA PABT carga
# ... POST .../voos/iniciar dispara sozinho aqui — aeronave já aparece
# "Em voo" no Mapa ao vivo/Portal ...
# ... voa, Ctrl+C ao pousar/encerrar (dispara o POST .../voos de fechamento) ...
```

Fase 2 acrescentou uma migration (`Version20260821170000.php`, coluna
`aeronave.em_voo_desde`) — precisa rodar `doctrine:migrations:migrate` de
novo além de já ter o `ACARS_TOKEN` no `.env`.

## Administração (adesão, solicitações e pilotos)

**Solicitação de adesão (`/adesao`)** é uma tela pública, sem sessão —
acessível pelo botão "Iniciar avaliação" na Home (seção "Entrada por
avaliação") e por um link na tela de Login ("Ainda não é piloto da
Katabatic? Solicite sua adesão"). O formulário pede nome, e-mail
(obrigatório), CID VATSIM, ID do Discord (opcional — só pra facilitar
adicionar a pessoa no servidor da tripulação depois de aprovada),
experiência em voo simulado (chips), base preferida (chips), como
conheceu a Katabatic (opcional) e a motivação — mais a confirmação de
que leu os critérios de avaliação (que linka pra seção "Entrada por
avaliação" da Home) antes de liberar o botão de enviar. **Atualizado
(backend real):** "Enviar solicitação" agora é um POST de verdade (ver
"Backend: adesão e solicitações") — o card de confirmação mostra o
código real do pedido, e o que é enviado aqui **aparece** no grid de
Solicitações descrito abaixo, já que os dois lados agora consultam a
mesma tabela (`membership_request`).

**Solicitações / Pilotos (`/solicitacoes`)** é um grid dentro da área
logada, restrito a pilotos com um papel "admin" — desde a seção
"Backend" abaixo, uma coluna de verdade (`Pilot::$admin`, vira
`ROLE_ADMIN`); o piloto semeado pela migration (CID `1234567`) é admin,
então o login de desenvolvimento já dá acesso. Pilotos sem esse papel
não veem o item
no rail, e acessar a URL direto redireciona pro Portal
(`SolicitacoesController` confere a sessão de novo no servidor, não só
esconde o link). O rail ganha um grupo "Administração" com dois itens,
só visível pra admin:

- **Solicitações** — lista os pedidos de adesão vindos de
  `membership_request` (banco real, ver "Backend: adesão e
  solicitações") com filtro por status (Pendentes/Aprovados/
  Rejeitados/Todos) e busca por nome/CID/e-mail/Discord — o e-mail
  (sempre preenchido) e o Discord (quando informado) já aparecem na
  própria linha, abaixo do nome. Clicar numa linha expande o restante:
  e-mail, Discord, CID e como a pessoa conheceu a Katabatic, além da
  motivação completa. Pedidos pendentes têm botões **Aprovar**/**Rejeitar** —
  as duas ações são POST de verdade; aprovar move o pedido pro status
  "Aprovado" e cria um `Pilot` de verdade na lista de Pilotos (ou liga
  o pedido a um piloto já existente do mesmo CID), mostrando a senha
  temporária gerada num banner — tudo isso persiste de verdade, um
  reload não desfaz.
- **Pilotos** — lista o roster cadastrado (banco real, `Pilot`), com
  matrícula/CID, base, papel (piloto/admin), data de adesão, voos
  somados (ainda fixo em 0 pra todo mundo — ver "Lacunas conhecidas")
  e status (ativo/inativo), mais busca por nome/CID.

Ambas seguem o mesmo padrão de troca-de-view do Portal (Logbook/Frota/
Bases): um único `SolicitacoesController::index()` renderiza as duas
seções, e `solicitacoes.js` alterna qual fica visível via `?view=`, sem
recarregar a página. Como o rail é compartilhado entre Portal e esta
tela mas cada JS só é dono de parte dos links (`data-view`), tanto
`portal.js` quanto `solicitacoes.js` só interceptam clique nos
`data-view` que a própria página possui — outros links do rail (ex.:
Logbook clicado estando em Solicitações) navegam normal pra rota
deles, em vez de ficar preso reescrevendo a URL da página atual sem
sair dela.

## Tema e idioma

Alternância de tema (claro/escuro) e idioma (PT/EN) são compartilhadas
por todas as telas via `theme-toggle.js` e `lang-toggle.js`
(`public/assets/js/`), carregados uma única vez em `base.html.twig` e
persistidos em `localStorage` (`katabatic-theme` / `katabatic-lang`) —
a escolha feita em uma tela vale nas outras.

A tradução de conteúdo agora cobre o site inteiro — as 13 telas, casca
comum (rail, barra, flash messages) e conteúdo profundo (tabelas,
formulários, gráficos, mapas, badges de status). Mecanismo com duas
partes:

- **`data-i18n="chave"`** — traduz o `innerHTML` de um elemento. Cada
  tela define seu próprio dicionário (`window.KATABATIC_I18N_EN`, num
  `<script>` dentro de `{% block page_javascripts %}`) que se combina
  com o dicionário compartilhado de `app_base.html.twig` (rail, barra,
  flash, e um bloco `common.*` com vocabulário reaproveitado em várias
  telas — "Em voo"/"Disponível"/"Fora de base", tipos de operação,
  períodos de filtro — pra garantir a MESMA tradução em todo canto que
  a mesma palavra aparece, em vez de cada tela inventar a sua). Páginas
  públicas (Home, Login, Adesão) continuam com dicionário próprio
  completo, sem depender de `app_base.html.twig`.
- **`data-i18n-attr="atributo:chave"`** (novo) — mesma ideia, mas pra
  atributos (`aria-label`, `title`, `placeholder`, `value`) em vez de
  conteúdo. Duas chaves universais, `a11y.language`/`a11y.theme`,
  resolvem sozinhas em `lang-toggle.js` sem a tela precisar declarar
  nada — cobrem o par idioma/tema que se repete idêntico em toda tela.

Telas com JS próprio que renderizam tabelas/gráficos/mapas
dinamicamente (Portal, Voo, Aeronave, Mapa ao vivo, Agendamento,
Solicitações, Novo voo, Nova aeronave — praticamente todas) usam um
helper local `L(chave, textoPtDeFallback)` (ou `tr(...)` nas três
telas com mapa Leaflet — Home, Voo, Aeronave, Mapa ao vivo — onde um
`function L` bateria de frente com o `L` global da biblioteca Leaflet)
que devolve o texto em inglês só se o idioma atual for EN, senão cai
no PT; cada uma dessas telas escuta `katabatic:langchange` (evento
disparado por `lang-toggle.js` a cada troca) e re-executa suas funções
de render, então trocar de idioma no meio da tela atualiza tabela,
mapa e gráficos na hora, não só o que já estava na página no load.
Mensagens de erro vindas do servidor (validação de formulário, erro de
login) são mapeadas por texto exato pra uma chave de tradução na
própria tela, já que `data-i18n` só sabe traduzir o que ele mesmo
capturou — ver `login.error` e o mapa `perfilErrorKeys` em
`templates/perfil/index.html.twig` como referência.

Fora do escopo, de propósito: valores de dado mock em si (nome de
piloto, matrícula, texto livre de observação/METAR, nomes de lugar
como "PAFA · Fairbanks") e o conteúdo do manual de Fraseologia VATSIM
que já é en­sinado em inglês por natureza (as chamadas de rádio com
`[placeholders]`, o alfabeto fonético, o exemplo completo) — só a
"casca" ao redor desse manual (títulos, parágrafos explicativos,
botão "Copiar") foi traduzida.

## Ideias futuras: Ferramentas do piloto

Ainda não iniciado — só anotado aqui pra não perder a ideia. A intenção
é uma seção nova de calculadoras/ferramentas interativas pro piloto,
separada de "Manuais e Operações" (que é documentação estática) —
provavelmente um grupo próprio no rail, algo como "Ferramentas", com
link cruzado a partir dos Manuais onde fizer sentido. Candidatas
discutidas até agora:

- **Peso e balanceamento** por aeronave, usando os limites operacionais
  que já são cadastrados em Nova aeronave — é o dado que mais
  naturalmente já existe no app pra alimentar essa conta.
- **Planejamento de combustível** (distância + consumo + reserva).
- **Componente de vento cruzado/cauda** — relevante com as pistas
  curtas das estações avançadas do Ártico/Patagônia.
- **Altitude densidade** — afeta performance em pistas de altitude e
  frio.
- **Distância de decolagem/pouso ajustada** por peso, vento e altitude
  densidade — a mais crítica pro perfil de bush flying da rede.
- Extras mais simples: conversor de unidades, calculadora de ETA a
  partir de GS e distância.

## Próximos passos (da fase de mockup)

Ver `docs/mockups-originais/README-mockups-original.md` para o histórico
completo de decisões de produto. Resumo do que vem depois das telas:

1. ~~Schema do banco~~ Login, Perfil (`Pilot`, ver "Backend: login e
   perfil"), Adesão/Solicitações (`MembershipRequest`, ver "Backend:
   adesão e solicitações"), Voos/telemetria (`Voo`, ver "Backend: voos
   e telemetria") e Frota (`Aeronave`, ver "Backend: mapa ao vivo e
   histórico da frota") — quatro fatias, feitas. Resto do schema
   (agendamentos) ainda por vir.
2. Migrar as outras nove telas (Portal, Voo, Novo voo, Nova aeronave,
   Histórico de aeronave, Mapa ao vivo, Agendamentos, Manuais,
   Solicitações/Pilotos — todas já com dados reais onde existem, mas
   ainda com o guard manual de sessão) pra ler o piloto autenticado de
   verdade (`$this->getUser()`), tirando o shim descrito na seção de
   Backend.
3. ~~Frota como schema de verdade (`Aeronave`)~~ Feito (ver "Backend:
   mapa ao vivo e histórico da frota") — falta ainda "Novo voo" gravar
   um voo novo de verdade e a contagem de `voos` no grid de Pilotos
   (`Pilot::$voos`), que dependiam desse schema mas não são desta fatia.
4. ~~Fork do cliente ACARS~~ / ~~Importador dos dados do
   `katabatic_capture.py`~~ / ~~Status "Em voo" em tempo real~~ Feito, na
   medida do MVP (ver "Backend: ingestão ACARS (MVP)") —
   `katabatic_capture.py` manda um POST no início e outro ao final do voo,
   `AcarsIngestaoController` já persiste um `Voo` de verdade a partir do
   segundo, e `Aeronave::status`/`emVooDesde` já viram "Em voo" sozinhos
   no primeiro (com autocorreção se o PC do piloto travar no meio do
   voo). O que falta do contrato completo
   (`docs/payload-telemetria-acars.md`, v1.1): sessão aberta/fechada com
   **posição** em tempo real (pra o Mapa ao vivo parar de depender de
   replay — só o status booleano ficou real nesta fatia, a posição no
   mapa continua simulada), fila com reenvio automático no cliente, gzip,
   token por piloto.
5. Busca de METAR/TAF e validação cruzada com VATSIM — hoje
   `TelemetryDeriver` não busca nenhum dos dois (`metar` fica `null`,
   `través` é aproximado sem heading de pista de verdade); é o que dá
   sentido a normalizar a telemetria de `Voo::$dados['telemetria']` num
   schema de amostras mais rico, se algum dia fizer falta.
6. Job de recorte GRIB
7. Ligar todas as telas ao backend real
