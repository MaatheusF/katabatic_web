# Katabatic Web

Portal web da Katabatic — companhia aérea virtual (MSFS 2024 + VATSIM) com
operação de carga, transporte de pessoal e pesquisa meteorológica em área
extrema. Duas bases em hemisférios opostos: **PAFA** (Fairbanks, Alasca) e
**SCCI** (Punta Arenas, Chile).

Este repositório é a implementação real (Symfony) do que antes existia só
como mockups em HTML autocontido. O pacote original de mockups e o contrato
de telemetria do ACARS ficam arquivados em `docs/` como referência enquanto
convertemos tela por tela.

## Stack

- **PHP 8.2+** / **Symfony 7.2**
- **Twig** para templates
- **Postgres 16 + PostGIS** (via Docker) — ainda não conectado ao app; entra
  quando o primeiro mockup que depende de dados reais (Portal/Logbook) for
  implementado
- Sem build step de frontend por enquanto: CSS/JS servidos como arquivos
  estáticos em `public/assets/`, para não depender de Node/npm

## Como rodar

Pré-requisitos: PHP 8.2+, Composer, Docker (opcional, só quando o banco
entrar em uso).

```bash
composer install
docker compose up -d          # sobe o Postgres+PostGIS (quando necessário)
symfony serve                 # ou: php -S localhost:8000 -t public
```

Acesse `http://localhost:8000`.

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
├── src/
│   ├── Controller/
│   ├── Entity/               (a partir do schema do banco)
│   └── Kernel.php
├── templates/
│   ├── base.html.twig        layout com topbar/footer compartilhados
│   └── home/                 uma pasta por tela
└── docker-compose.yml         Postgres + PostGIS
```

## Progresso (mockup → tela real)

- [x] `katabatic-home.html` → `/` (Home institucional)
- [x] `katabatic-portal.html` → `/portal` (Logbook, Frota, Bases)
- [x] `katabatic-voo.html` → `/voo` (relatório de voo — mapa, gráficos
  sincronizados, fases, pouso e relato do piloto). Telemetria real (não
  mock) de 3 voos de teste em `public/assets/data/flights.json`
- [x] `katabatic-novo-voo.html` → `/novo-voo` (registro de voo — importar
  telemetria com dropzone simulada, ou registro manual). Publicar/Salvar
  rascunho ainda são mock (`alert`); viram POST de verdade quando o
  Logbook for tabela real
- [x] `katabatic-nova-aeronave.html` → `/nova-aeronave` (cadastro de
  aeronave — país/matrícula/tipo/base, limites operacionais, fotos e
  observações internas). "Salvar aeronave" ainda é mock (`alert`)
- [x] Login (`/login`) → não existia mockup próprio; tela nova para o
  fluxo Home → Login → Portal. Autenticação mock via sessão (CID
  `1234567`, qualquer senha) — troca por Security real quando o schema
  do banco existir.
- [x] Histórico de aeronave (`/aeronave/{reg}`) → não existia mockup
  próprio; tela nova acessada a partir de um card/linha da Frota no
  Portal. Mostra num mapa (Leaflet) todas as pernas voadas por uma
  matrícula num período filtrável, com traçado, origem/destino e label
  de cada perna, mais uma lista ao lado. Ver detalhes na seção
  "Histórico de aeronave" abaixo.
- [x] Solicitação de adesão (`/adesao`) → não existia mockup próprio;
  tela pública nova onde quem quer virar piloto se candidata. "Enviar
  solicitação" ainda é mock (troca o formulário por uma confirmação, sem
  POST de verdade).
- [x] Solicitações / Pilotos (`/solicitacoes`) → não existia mockup
  próprio; grid administrativo restrito a pilotos "admin" (ver seção
  "Administração" abaixo) pra revisar pedidos de adesão e ver a lista de
  pilotos cadastrados.
- [x] Mapa ao vivo (`/mapa-ao-vivo`) → não existia mockup próprio; mapa
  Leaflet em tela cheia com a posição simulada de toda a frota (em voo e
  em solo) num painel flutuante. Ver detalhes na seção "Mapa ao vivo"
  abaixo.

Todas as dez telas já navegam entre si por botões de verdade (não só
mockup estático lado a lado): Home → Login → Portal, Home/Login →
Adesão, Portal ↔ Voo, Portal ↔ Novo voo, Portal (Frota) ↔ Nova
aeronave, Portal (Frota) ↔ Histórico de aeronave, Portal ↔ Solicitações/
Pilotos (só pra admin), Portal ↔ Mapa ao vivo, e o rail lateral
funciona em qualquer uma das telas da área logada (mesmo fora do
Portal, onde ele faz um link real para `/portal?view=...` em vez do
troca-de-view em JS que só existe estando já no Portal).

As linhas do Logbook do Portal também abrem `/voo?id=...` de verdade —
mas só as 3 linhas de 19/08 cujo horário bate com um dos voos de teste
reais gravados em `flights.json` (03:22Z, 03:28Z e 03:34Z) ficam
clicáveis (cursor muda, `tabindex` pra teclado); as outras 3 linhas são
mock sem telemetria gravada e não têm pra onde ir ainda. Como o Logbook
mock e os voos reais são dados de fontes diferentes (só coincidem no
horário), o callsign/aeronave/rota mostrados na linha ainda não batem
100% com o que o relatório real exibe — mistura documentada aqui até o
Logbook virar tabela de verdade. O bloco "Operações recentes" da Home
(também mock) continua sem link por esse mesmo motivo.

Em `/voo?id=`, o card "Relatório de missão" fica na coluna esquerda do
grid (junto com Fases, Trajetória e Debrief), não mais ocupando a
largura inteira da tela abaixo do grid.

A tabela do Logbook em `/portal` tem um limite de altura com scroll
interno (cabeçalho fixo enquanto rola) e paginação client-side a partir
de 500 linhas filtradas (`LB_PAGE_SIZE` em `portal.js`) — com as 6
linhas mock atuais isso não muda nada visualmente ainda, mas já deixa a
tela pronta para quando o Logbook virar consulta de verdade e puder ter
centenas/milhares de voos. A visualização compacta (ícone de linhas
densas nos filtros) continua disponível e ajuda a caber mais linhas na
tela antes de precisar rolar.

A coluna "Data" do Logbook agora mostra o ano (`dd/mm/aaaa`) para
evitar ambiguidade entre voos de anos diferentes. A coluna "Ocorrência"
aceita múltiplos registros por voo (ex.: overspeed e quique no mesmo
pouso) — cada ocorrência vira uma tag própria, empilhada verticalmente
na célula (`ocorrencias: [{label, tag}, ...]` em
`PortalController::logbook()`); voos sem ocorrência mostram "—". O
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

O histórico de pernas é mock, gerado deterministicamente em
`AeronaveController::aircraftLegs()` (sem `rand()`/hora do sistema) —
o Logbook de verdade hoje só tem 6 linhas no total, poucas para
demonstrar de forma convincente "todas as pernas de uma aeronave" ao
longo de meses; isso deixa de ser necessário quando o Logbook virar
tabela de verdade e a consulta puder ser filtrada por matrícula
diretamente. As coordenadas dos 11 aeroportos da rede (bases + estações
avançadas, ver `PortalController::bases()`) ficam em
`public/assets/data/airports.json`. A perna mais recente das 3
matrículas que têm voo de teste real gravado (N208KB, CC-KBA, N67KB —
mesmas do link Logbook → Voo) carrega `flightId` e é a única com nome
de voo clicável na lista/popup; o traçado no mapa desta tela, porém,
sempre usa a linha entre as coordenadas ICAO (mesmo nessa perna) — a
telemetria gravada nos testes fica geograficamente em outro lugar (ver
caveat do Logbook
acima), plotá-la ali quebraria o enquadramento do mapa com o resto do
histórico.

## Mapa ao vivo (`/mapa-ao-vivo`)

Tela nova no rail (item "Mapa ao vivo", entre Bases e o grupo
Administração) — um mapa Leaflet em tela cheia (ocupa todo o espaço
abaixo da barra superior, sem o `.wrap` com padding das outras
páginas) mostrando a frota inteira agora: quem está em voo e quem está
em solo, num único painel flutuante sobre o mapa. É a tela mais
"ao vivo" do produto hoje, mas ainda **totalmente mock** — não existe
feed de posição em tempo real via ACARS.

As 2 aeronaves marcadas como "Em voo" no Portal (CC-KBA e N208KB — ver
`PortalController::fleet()`) aparecem com um marcador que se move ao
longo do arco entre origem e destino da rota (mesma curva de Bézier
usada no histórico de aeronave), repetindo **em loop** a telemetria
real de ~5-6 minutos gravada em `flights.json` para essas duas
matrículas (a mesma gravação que abre atrás do link "Ver relatório
real"). Altitude e velocidade indicada mostradas no popup e no painel
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
avaliação" da Home) antes de liberar o botão de enviar. "Enviar
solicitação" é mock: troca o card do formulário por um
card de confirmação com um ID de solicitação fictício, sem POST de
verdade — o que é enviado aqui **não** aparece no grid de Solicitações
descrito abaixo, já que os dois lados usam conjuntos de dados mock
independentes até existir um backend de verdade (ver
`AdesaoController`).

**Solicitações / Pilotos (`/solicitacoes`)** é um grid dentro da área
logada, restrito a pilotos com um papel "admin" — um flag mock simples
adicionado à sessão do piloto (`LoginController::findMockPilot()`); o
único piloto mock hoje (CID `1234567`) é admin, então o login de
desenvolvimento já dá acesso. Pilotos sem esse papel não veem o item
no rail, e acessar a URL direto redireciona pro Portal
(`SolicitacoesController` confere a sessão de novo no servidor, não só
esconde o link). O rail ganha um grupo "Administração" com dois itens,
só visível pra admin:

- **Solicitações** — lista os pedidos de adesão (mock, 6 registros com
  status variados) com filtro por status (Pendentes/Aprovados/
  Rejeitados/Todos) e busca por nome/CID/e-mail/Discord — o e-mail
  (sempre preenchido) e o Discord (quando informado) já aparecem na
  própria linha, abaixo do nome. Clicar numa linha expande o restante:
  e-mail, Discord, CID e como a pessoa conheceu a Katabatic, além da
  motivação completa. Pedidos pendentes têm botões **Aprovar**/**Rejeitar** —
  aprovar move o pedido pro status "Aprovado" e adiciona a pessoa na
  lista de Pilotos (papel "piloto", 0 voos, ativo); tudo isso é mock,
  só no array em memória desta página — não persiste, um reload volta
  ao estado inicial.
- **Pilotos** — lista o roster cadastrado (mock, 7 pilotos), com
  matrícula/CID, base, papel (piloto/admin), data de adesão, voos
  somados e status (ativo/inativo), mais busca por nome/CID.

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

A tradução de conteúdo (`data-i18n` + dicionário em inglês) por ora
cobre: a Home inteira (já existia), e nas telas da área logada (Portal,
Voo, Novo voo, Nova aeronave, Histórico de aeronave, Solicitações/
Pilotos) e no Login, a casca comum e as ações principais — rail
lateral (incluindo o grupo "Administração"), título/voltar da barra,
botões como "Novo voo", "Cancelar", "Salvar rascunho", "Publicar voo",
"Salvar aeronave", "Compartilhar", o fluxo de login inteiro (com o novo
link "Solicite sua adesão") e o botão "Área do piloto" na tela de
Adesão. Conteúdo profundo dessas telas (tabelas do Logbook/Frota, o
formulário de Adesão, o grid de Solicitações/Pilotos, formulários de
Novo voo/Nova aeronave, gráficos e leituras do relatório de voo)
continua só em português — ainda é tudo mock ou vem direto da
telemetria, sem `data-i18n` nele; entra quando essas telas ganharem
dados de verdade.

## Próximos passos (da fase de mockup)

Ver `docs/mockups-originais/README-mockups-original.md` para o histórico
completo de decisões de produto. Resumo do que vem depois das telas:

1. Schema do banco (Postgres + PostGIS + TimescaleDB)
2. Fork do cliente ACARS usando `docs/payload-telemetria-acars.md`
3. Servidor de ingestão dos endpoints do contrato ACARS
4. Importador dos dados do `katabatic_capture.py`
5. Job de recorte GRIB
6. Ligar todas as telas ao backend real
