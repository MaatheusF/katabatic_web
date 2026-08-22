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
sem nada mock por trás) já cobre sete fatias, nesta ordem histórica — cada
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
6. **Posição em tempo real (ACARS fase 3)** — um terceiro endpoint,
   `POST /api/acars/v1/voos/posicao`, chamado a cada ~12s pelo script
   enquanto o voo está em andamento; grava a posição mais recente em
   `App\Entity\PosicaoAoVivo` e o Mapa ao vivo passa a mostrar essa posição
   real (com polling em `GET /mapa-ao-vivo/posicoes`) em vez do replay em
   loop — que continua existindo como fallback pra quem ainda não manda
   heartbeat.
7. **Agendamento de voo** — `Agendamento`; reservar aeronave da frota pra
   um voo futuro, criar/editar/remover pernas e a checagem de
   sobreposição de horário já persistem de verdade (ver "Backend:
   agendamento de voo").
8. **Aeroportos e pouso alternativo (diversão)** — `Aeroporto`; catálogo
   de aeroportos migrou do `airports.json` fixo pra uma tabela com tela
   admin de cadastro (`/aeroportos`), a aba "Bases" do Portal já lista os
   postos avançados de cada base a partir desse catálogo (em vez do
   array mock fixo de antes), e `AcarsIngestaoController` agora detecta
   e grava quando um voo pousa num aeroporto diferente do declarado no
   plano de voo (`Voo::$destinoReal`), corrigindo a posição da aeronave
   sozinho e avisando visualmente (ver "Backend: aeroportos e pouso
   alternativo (diversão)"). **Atualizado:** o catálogo deixou de ter
   só os 11 aeroportos hand-cadastrados — `app:importar-aeroportos-ourairports`
   importa a base pública inteira do [OurAirports](https://ourairports.com/)
   (ICAO real em qualquer país, milhares de linhas; mais pistas sem ICAO
   nas regiões de missão — Ártico/Antártico + Cone Sul — via código
   local/FAA, marcadas com um selo "Local" na UI). Pra não quebrar a
   suposição de "catálogo pequeno" que o resto do app tinha, nada muda de
   visual pra quem usa a tela: os `<select>` de aeroporto viraram busca
   (por ICAO ou nome/cidade, mesmo endpoint `GET /aeroportos/buscar` em
   `/aeroportos` e `/agendamentos`), e tanto a tela admin quanto os
   mapas (`/aeronave/{reg}`, `/mapa-ao-vivo`) continuam só mostrando
   bases + postos avançados por padrão — o catálogo inteiro só aparece
   quando alguém busca por ele.

O que **ainda é mock** (sem tabela/banco por trás): o boletim de
vento/temperatura/visibilidade/teto no cabeçalho de cada base na aba
"Bases" do Portal (a lista de postos avançados abaixo dele já é real,
ver item 8 acima), fotos
de aeronave em `/nova-aeronave`, o modo "Importar telemetria" e o botão
"Salvar rascunho" da própria tela `/novo-voo` (ver "Backend: voos e
telemetria" pra por quê) — **atualizado:** "Publicar" no modo "Registro
manual" já grava um `Voo` de verdade, e busca de
METAR/TAF/recorte GRIB. A posição no Mapa ao vivo agora é real pra quem já
manda heartbeat de posição (ver fatia 6 acima) — só cai pro replay em loop
antigo quando não há heartbeat ainda. A contagem `Pilot::$voos` no grid de
Pilotos já é real (não é mais mock, ver "Backend: adesão e solicitações").
Lista completa e ordenada de próximos passos em "Próximos passos" no fim
deste arquivo.

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
  Adesão/Solicitações, Voos/telemetria, Frota, ingestão ACARS e
  Agendamento de voo (ver "Backend" mais abaixo)
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
php bin/console doctrine:migrations:migrate        # roda todas as migrations (pilot, membership_request, voo, aeronave, em_voo_desde, posicao_ao_vivo, agendamento...)
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
│   ├── Entity/               Pilot, MembershipRequest, Voo, Aeronave, PosicaoAoVivo, Agendamento
│   ├── Repository/
│   ├── Security/             LoginFormAuthenticator
│   └── Kernel.php
├── templates/
│   ├── base.html.twig        layout com topbar/footer compartilhados
│   └── home/                 uma pasta por tela
└── docker-compose.yml         Postgres + PostGIS
```

## Progresso (mockup → tela real)

- [x] `katabatic-home.html` → `/` (Home institucional). Os slots de foto
  (`.shot-frame`, ver `base.css`) eram só um contorno tracejado com uma
  dica de enquadramento (`data-hint`) até então — **atualizado:** hero,
  cabine (seção "A empresa"), as duas bases e a formação em
  "Tripulação" já usam fotos reais (`public/assets/img/home/`, JPEG
  otimizado — ~10 MB de PNG originais viraram ~1 MB no total). Faltam
  só as 6 fotos da Frota (`.shot-frame.r-square`, uma por matrícula,
  ver "Progresso" logo abaixo) — essas continuam o contorno tracejado
  por enquanto.
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
  telemetria com dropzone simulada, ou registro manual). O seletor de
  aeronave já vem da Frota real (ver "Backend: mapa ao vivo e histórico
  da frota") — desde a ingestão ACARS (ver "Backend: ingestão ACARS
  (MVP)"), o Logbook já cresce de verdade por fora desta tela também (o
  script de captura manda o voo direto pro servidor, não passa pelo
  formulário). **Atualizado:** "Publicar" no modo "Registro manual"
  agora grava um `Voo` de verdade também a partir desta tela (ver
  "Backend: voos e telemetria") — o modo "Importar telemetria" e
  "Salvar rascunho" continuam mock (`alert`)
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
- [x] Ferramentas do piloto (`/ferramentas`) → não existia mockup
  próprio; item do backlog ("Ideias futuras: Ferramentas do piloto")
  virou 4 calculadoras de verdade (vento cruzado/cauda, conversor de
  unidades + ETA, peso e balanceamento, distância de decolagem/pouso
  ajustada). Ver detalhes na seção "Backend: Ferramentas do piloto e
  tipos de aeronave" abaixo.
- [x] Tipos de aeronave (`/tipos-aeronave`) → não existia mockup
  próprio; cadastro admin do perfil de performance por tipo que
  alimenta as duas calculadoras acima que precisam de dado real. Ver
  mesma seção acima.

Todas as quinze telas já navegam entre si por botões de verdade (não só
mockup estático lado a lado): Home → Login → Portal, Home/Login →
Adesão, Portal ↔ Voo, Portal ↔ Novo voo, Portal (Frota) ↔ Nova
aeronave, Portal (Frota) ↔ Histórico de aeronave, Portal ↔ Solicitações/
Pilotos (só pra admin), Portal ↔ Mapa ao vivo, Portal ↔ Agendamentos,
Portal ↔ Manuais ↔ Fraseologia VATSIM, Portal ↔ Perfil (a partir do
cartão do piloto no rail), Portal ↔ Ferramentas do piloto, Portal ↔
Tipos de aeronave (só pra admin, a partir de Ferramentas quando falta
perfil cadastrado), e o rail lateral funciona em
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
pouso — `ocorrencias: [{label, tag}, ...]`, guardado dentro de
`Voo::$dados`, `tag` é sempre `bad` ou `warn` — ver "Backend: voos e
telemetria"), mas a célula mostra só a mais grave (`bad` vence `warn`,
ver `occPior()` em `portal.js`) em vez de empilhar todas verticalmente
— um voo com muitos eventos não faz mais a linha da tabela crescer pra
baixo. Um selo "+N" ao lado sinaliza que há outras (título com a lista
completa ao passar o mouse), e a lista inteira reaparece na linha de
detalhe expansível (botão "▸", mesma linha que já mostra
distância/combustível/tempo ar-solo/carga/METAR — ver
`.lb-detail-occ`); voos sem ocorrência mostram "—" nos dois lugares. O
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
frota"). **Atualizado de novo (ACARS fase 3 — posição em tempo real, ver
"Backend: posição em tempo real (ACARS fase 3)" mais abaixo):** a posição
*no mapa* agora é real pra quem já manda heartbeat de posição pelo ACARS
— atualizada por polling (`GET /mapa-ao-vivo/posicoes`, a cada ~12s) em
vez do replay em loop antigo. Uma aeronave "Em voo" sem heartbeat ainda
(cliente de captura desatualizado, ou decolou sem `--server`) continua
caindo no replay simulado de sempre — as duas convivem por aeronave, ao
mesmo tempo, na mesma tela; o popup de cada uma mostra "Posição real
(ACARS)" ou "Posição simulada" conforme o caso.

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

**Sem heartbeat de posição ainda (caminho antigo, mantido como
fallback):** as aeronaves marcadas como "Em voo" na frota
(`Aeronave::$status`) aparecem com um marcador que se move ao longo do
arco entre origem e destino da rota (mesma curva de Bézier usada no
histórico de aeronave), repetindo **em loop** a telemetria real de ~5-6
minutos gravada em `flights.json` da matrícula (a mesma gravação que
abre atrás do link "Ver relatório real") — via
`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()` (ver
"Backend: mapa ao vivo e histórico da frota"); uma aeronave "Em voo" sem
nenhum voo com telemetria gravada simplesmente não aparece na lista.
Altitude e velocidade indicada mostradas no popup e no painel lateral
vêm ponto a ponto dessa gravação real; a posição geográfica, porém, é
sempre sintetizada sobre a rota origem→destino (coordenadas de
`airports.json`) — a telemetria gravada nos testes fica geograficamente
em outro lugar (ver caveat do Logbook), então plotar a lat/lon real
quebraria por completo o enquadramento do mapa com o resto da rede. O
relógio UTC na barra e a animação em si usam o horário real do
navegador (`Date.now()`), não um horário mock.

**Com heartbeat de posição (ACARS fase 3):** o marcador usa a
`lat`/`lon` reais recebidas em `POST /api/acars/v1/voos/posicao`, com
uma interpolação linear simples entre o ping anterior e o mais recente
(pra não "saltar" a cada polling de ~12s) — ver
`mapa-ao-vivo.js#updateFlyingLive`. Altitude/velocidade indicada/
velocidade solo mostradas vêm do próprio ping (`alt_ft`/`ias_kt`/
`gs_kt`), não de uma gravação. Se o polling falhar ou o heartbeat parar
de chegar por mais de ~3 ciclos (`LIVE_STALE_MS`, ~36s), a aeronave cai
de volta pro replay simulado sozinha, sem piscar a cada ping perdido
isolado.

As outras 4 aeronaves da frota (CC-KBC, CC-KBD, N412KB, N67KB) ficam
paradas na base ou estação onde o Portal já as marca hoje (`pos`);
quando mais de uma aeronave está no mesmo aeroporto (N412KB e N67KB,
ambas em PAFA), os marcadores recebem um pequeno deslocamento pra não
ficarem um em cima do outro. O painel flutuante lista as duas seções
("Em voo" / "Em solo") e clicar numa linha foca o mapa na aeronave
correspondente e abre o popup dela — mesmo comportamento clicando
direto no marcador. Aeroportos da rede sem tráfego no momento aparecem
como pontos discretos, sem rótulo, só pra dar contexto geográfico.

**Clima em tempo real.** Assim como a posição das aeronaves com
heartbeat (e ao contrário das que ainda caem no replay simulado), o
clima nesta tela é dado real, consumido direto do navegador em duas
APIs públicas gratuitas e sem chave — nenhuma delas passa pelo backend
Symfony, que continua sem nenhuma dependência de API externa:

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
— **atualizado:** era um "agora" mock fixo (2026-08-19 12:00Z), agora é
o horário real do servidor no momento em que a página carrega.

**Atualizado (backend real, ver "Backend: agendamento de voo" mais
abaixo):** era tudo mock, mesmo padrão que Solicitações tinha antes de
ganhar backend — `AgendamentoController` só montava o estado inicial e
`agendamento.js` manipulava um array em memória a partir daí, sem
persistir entre reloads. Agora `App\Entity\Agendamento` é tabela de
verdade: criar, editar e remover são POSTs de verdade, persistem entre
reloads e ficam visíveis pra qualquer piloto logado, não só quem
criou (é uma agenda operacional compartilhada). A tabela nasce vazia —
as seis pernas que o mock sempre mostrava (duas sequências completas
em CC-KBC e N412KB, uma perna com aviso de posição em N67KB) eram só
pra demonstrar a tela funcionando, não um histórico real de nada.

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
- ~~`voos` no grid de Pilotos continua fixo em `0` pra todo mundo~~ —
  **religado** (ver "Backend: voos e telemetria" logo abaixo,
  `VooRepository::countsByPilot()`/`countForPilot()`): agora é a
  contagem de verdade da tabela `voo`, uma consulta agregada pro grid
  inteiro em vez de N+1.

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
  coluna `jsonb` (`dados` — **atualizado:** era `json`, convertida por
  `Version20260822100000`; o tipo DBAL da entidade continua
  `Types::JSON` de propósito, ver docblock de `Voo::$dados`).
  Decisão deliberada: normalizar tudo em colunas/tabelas próprias (uma
  linha por amostra de telemetria, por exemplo) seria prematuro sem um
  banco de verdade neste ambiente pra testar contra, e sem ainda
  existir a ingestão real do ACARS que vai definir o volume/formato
  real de amostra — ver "Lacunas conhecidas".
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
- **Marcar um voo como acidentado.** `POST /voo/{codigo}/acidentado`
  (`VooController::marcarAcidentado()`) — pro caso de um voo registrado
  por engano (acidente no meio do trajeto, sessão ACARS corrompida, ou
  qualquer motivo pra essa perna não valer). **Atualizado:** a versão
  original desta fatia (`excluir()`, ver git log) fazia hard delete —
  o voo (telemetria inteira incluída) sumia pra sempre do banco, sem
  registro de quem excluiu nem quando. Agora é uma marcação:
  `Voo::$status` vira `acidentado` (coluna nova, `Version20260822140000`)
  e a linha **continua na tabela** — dá pra auditar depois, e o piloto
  não perde o registro do que aconteceu. O voo marcado some das
  contagens/estatísticas (`VooRepository::countsByPilot()`/
  `countForPilot()` agora só contam `status = 'valido'`), mas continua
  visível no relatório (selo "Acidentado" no cabeçalho) e, opcionalmente,
  no histórico da aeronave (ver próxima seção). Continua desfazendo o
  mesmo efeito colateral que o fechamento normal teve na aeronave:
  subtrai as horas que aquele voo tinha somado e devolve `posIcao` pra
  `origem` da própria perna marcada — como se ela nunca tivesse
  partido. **Só é permitido no voo mais recente com telemetria de cada
  aeronave** (`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`,
  que não filtra por `status` — continua olhando a data, não se o voo
  já foi marcado): marcar uma perna no meio da história e "devolver" a
  posição pra origem dela corromperia a posição de verdade se pernas
  mais novas já aconteceram depois — pra desfazer uma sequência
  inteira, é marcar de trás pra frente. Botão em `/voo` (card "Marcar
  este voo como acidentado", dois cliques — arma "Confirmar?" por 4s,
  mesmo padrão sem `confirm()` nativo que Solicitações e Agendamento já
  usam) — some o botão e mostra "já marcado" no lugar, sem sair da
  página (o voo continua existindo, diferente da versão anterior que
  precisava redirecionar pro Portal).
- **Excluir um voo permanentemente.** `POST /voo/{codigo}/excluir`
  (`VooController::excluir()`) — de volta como uma **segunda** ação,
  separada de "marcar como acidentado" acima: pra quando marcar não
  basta (gravação de teste, duplicata, voo que simplesmente não devia
  existir) e a linha precisa mesmo sumir do banco, telemetria incluída,
  sem tombstone/auditoria (mesmo comportamento — e mesma lacuna — do
  hard-delete original desta fatia, ver git log). Mesmo guard de
  `marcarAcidentado()`: só o voo mais recente com telemetria de cada
  aeronave (`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`,
  que não filtra por `status` — um voo já acidentado ainda conta como
  "mais recente" se nada aconteceu depois dele). Se o voo excluído
  **já estava marcado acidentado**, o efeito colateral na aeronave
  (horas/posição) não é reaplicado — já tinha sido feito quando foi
  marcado; só aplica de novo se ainda estava `valido`. Botão separado
  em `/voo` (card "Excluir este voo permanentemente", mesmo padrão de
  dois cliques) — esse sim redireciona de volta pro Portal no sucesso,
  já que o voo deixa de existir de verdade.
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
  relação — ver essa seção pra detalhe). ~~`Pilot::$voos` (contagem de
  voos no grid de Pilotos em Solicitações) continua em 0~~ religado
  depois (ver "Backend: adesão e solicitações" acima,
  `VooRepository::countsByPilot()`).
- ~~"Novo voo" (`/novo-voo`) continua sem gravar nada~~ **Atualizado:**
  o modo "Registro manual" tem `POST /novo-voo/publicar` de verdade
  (`NovoVooController::publicar()`) — cria um `Voo` real (`codigo`
  fica `null`, mesmo estado dos voos históricos narrativos, então
  `portal.js` já trata a linha como "sem telemetria" sem precisar de
  uma flag nova) e aplica o mesmo efeito colateral na aeronave que a
  ingestão ACARS aplica ao pousar (`horas`/`posIcao`/`status`, ver
  `AcarsIngestaoController::ingerir()`). Sem telemetria medida,
  `dist`/`combustivelKg`/`tempoSoloMin`/`tempoArMin`/`metar` ficam
  zerados/nulos — não há como estimá-los só do que o piloto digitou.
  `objetivo`, `simbrief` (OFP) e `visibilidade` são capturados no
  formulário e guardados em `dados`, mas nenhuma tela ainda os exibe
  (mesmo tipo de lacuna que "carga" tinha na ingestão ACARS — guardado
  pra não perder o que foi digitado). O modo "Importar telemetria"
  continua mock (nomes de arquivo fixos, sem parsing de CSV real) e
  "Salvar rascunho" também — ver docblock de `NovoVooController` pro
  porquê de cada um.
- Sem paginação/streaming no `GET /voo/telemetria` — devolve a lista
  inteira do piloto de uma vez, igual o arquivo estático fazia. Ok com
  3 voos de teste; não escala pra um piloto com centenas de voos
  gravados de verdade.
- A telemetria em si continua uma coluna `jsonb` por voo (não
  normalizada por amostra — a conversão de `json` pra `jsonb` foi só
  de tipo físico, não de estrutura) — ver decisão em "O que existe
  agora". Isso muda quando a ingestão real do ACARS
  (`docs/payload-telemetria-acars.md`) for implementada — aí sim faz
  sentido desenhar o schema definitivo de amostras, com volume e
  padrão de consulta reais pra guiar a decisão em vez de adivinhar.
- ~~Sem validação cruzada com VATSIM, sem cálculo de índice de
  dificuldade no servidor~~ — o índice de dificuldade agora é
  calculado no servidor pela ingestão ACARS (`TelemetryDeriver`, ver
  "Backend: ingestão ACARS (MVP)"), incluindo filtragem de amostras
  com slew/sim_rate anormal antes de qualquer estatística agregada
  (ver docblock da classe). Ainda sem validação cruzada com o datafeed
  da VATSIM nem busca de METAR/TAF — isso continua fora do escopo.
- `excluir()` continua hard delete, sem tombstone/auditoria — uma vez
  excluído, o voo (telemetria inteira incluída) some pra sempre do
  banco, sem registro de quem excluiu nem quando. `marcarAcidentado()`
  cobre o caso mais comum (voo que não devia contar, mas vale manter
  pra histórico) sem esse problema — `excluir()` existe como uma
  segunda ação, deliberadamente mais destrutiva, pros casos em que a
  linha realmente não devia existir. Nenhuma das duas guarda **quem**
  agiu nem **quando** (só o `status` muda numa, a linha some na outra)
  — aceitável pro tamanho da operação hoje, mas é o próximo passo óbvio
  se algum dia importar auditoria de verdade (ex.: prestação de contas
  de horas voadas).

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
  artificialmente. **Atualizado:** `legViewModel()` manda um campo
  `acidentado` (`Voo::isAcidentado()`, ver "Backend: voos e
  telemetria") pra cada perna; `aeronave.js` filtra com base nele
  (checkbox "Mostrar acidentadas", ligado por padrão) tanto a lista
  quanto o traçado no mapa — quando ligado, a perna acidentada aparece
  com opacidade reduzida e um selo "Acidentado" ao lado do callsign, em
  vez de ficar indistinguível de um voo válido.
- **Trajeto real no mapa, não mais uma linha estimada entre
  aeroportos.** Antes desta correção, `aeronave.js` desenhava toda perna
  como uma curva entre as coordenadas fixas de origem/destino
  (`airports.json`) — nunca o caminho de verdade voado. Isso quebrava de
  duas formas: um voo de circuito (saiu e voltou pra mesma base, comum
  no primeiro voo de uma aeronave nova) tinha origem === destino, e a
  curva bezier degenerava numa linha de comprimento zero — só o
  marcador do aeroporto aparecia, a "rota" ficava invisível; e qualquer
  perna pra um aeroporto fora do punhado de ~11 ICAOs que
  `airports.json` conhece (bem provável fora do Alasca/Patagônia) era
  **excluída do mapa inteira**, mesmo tendo telemetria real gravada.
  Agora `AeronaveController::trackPoints()` manda, pra toda perna com
  telemetria ACARS, o `track` gravado de verdade (`Voo::
  getTelemetria()['track']`, decimado pra no máximo 180 pontos —
  `TRACK_MAX_PONTOS` — sem perder a última amostra); `aeronave.js`
  (`endpoints()`, `drawMap()`) desenha essas coordenadas exatas em vez
  de estimar, e usa esse mesmo trajeto — não mais `airports.json` — pra
  posicionar os marcadores de origem/destino, então funciona mesmo pra
  ICAOs fora do catálogo curado. Cor/espessura por recência (perna mais
  recente mais forte, mais antiga mais apagada) continua igual, agora
  aplicada ao traçado real. Perna **sem** telemetria (histórico só
  narrativo) continua sem trajeto de verdade pra mostrar — cai no
  fallback antigo (curva estimada entre os dois aeroportos, só quando
  ambos existem em `airports.json`); esse fallback também ganhou o
  laço-em-vez-de-linha-zero pro caso origem === destino, e o popup troca
  "PAFA → PAFA" por "Circuito em PAFA" nesse caso, com uma nota
  "traçado estimado" deixando claro que não é o caminho real.
- **Toggle "Mostrar todas as posições" — resolução completa sob
  demanda.** O `track` acima vem sempre decimado (180 pontos por perna)
  no carregamento inicial de `/aeronave/{reg}`, pra um histórico com
  muitos voos longos não pesar a tela de cara. Agora existe um checkbox
  ao lado dos outros filtros do mapa: desligado (padrão), continua
  usando esse `track` decimado; ao ligar, `aeronave.js` busca — sob
  demanda, com um indicativo de carregamento no próprio checkbox — o
  trajeto **sem decimar** de toda perna com telemetria da aeronave, via
  `GET /aeronave/{reg}/trajetos-completos` (`AeronaveController::
  trajetosCompletos()`, novo endpoint JSON, `{codigo: [[lat, lon], ...]}`
  por voo, reusando `trackPoints()` com decimação desligada). A resposta
  fica em cache no cliente (`FULL_TRACKS`) — desligar e religar o
  checkbox depois não refaz a busca. Se a busca falhar (rede fora, por
  exemplo), o checkbox volta a ficar desmarcado sozinho e mostra uma
  mensagem de erro no lugar do indicativo de carregamento, sem quebrar o
  mapa (continua no `track` decimado). Não precisa de migration — é só
  um novo endpoint de leitura em cima dos mesmos dados de `voo.dados`
  que já existiam.
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
pro Mapa ao vivo — ambas descritas abaixo. **Atualizado depois (peso
real, carga estimada, METAR e través com pista real):** ver "Backend:
realismo da ingestão ACARS" mais abaixo.

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
- **Atualizado:** amostras com slew ativo (`IS SLEW ACTIVE`) ou
  `sim_rate` fora de ~1× (±5%) agora são excluídas de toda estatística
  agregada (`dist`/min-max-avg/fases/través, e por consequência
  `score`/`dificuldade`) — ver `TelemetryDeriver::trustedSamples()`.
  Implementa o princípio #3 do contrato ("ninguém confia no cliente")
  pro que faltava: sem isso, um piloto acelerando o sim ou usando slew
  conseguia inflar (ou zerar) o índice de dificuldade e a distância do
  Logbook com números fisicamente impossíveis. `track`/`prof` (o que o
  mapa/gráfico do relatório mostra) continuam com a gravação inteira,
  sem esse filtro — o voo aparece completo, só as métricas derivadas é
  que ignoram o trecho não confiável. Se o voo inteiro cair fora do
  filtro, cai de volta pro conjunto sem filtro em vez de zerar tudo.

**Lacunas conhecidas, de propósito, nesta fatia:**

- ~~A posição da aeronave no mapa continua sem vir de um feed ao
  vivo~~ Resolvido na fatia seguinte, ver "Backend: posição em tempo
  real (ACARS fase 3)" logo abaixo — um heartbeat periódico simples
  (polling), não o streaming de sessão aberta/fechada do contrato
  completo (ver "Próximos passos" pra essa diferença).
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

## Backend: posição em tempo real (ACARS fase 3)

Sexta fatia de backend — tira o Mapa ao vivo do replay em loop pra quem já
manda posição de verdade. Continua sendo um **heartbeat periódico simples
(polling)**, não o streaming de sessão aberta/fechada com fila SQLite/gzip
que o contrato completo (`docs/payload-telemetria-acars.md`, v1.1) descreve
— decisão deliberada, mesma lógica das fatias anteriores: com 6 aeronaves
na frota, um POST leve a cada ~12s já resolve, e não existe (nem esta fatia
introduz) nenhuma infraestrutura de push no projeto (sem Mercure, sem
WebSocket).

**O que existe agora:**

- `App\Entity\PosicaoAoVivo` (`src/Entity/PosicaoAoVivo.php`) — tabela
  `posicao_ao_vivo`, **uma linha por aeronave** (upsert, nunca cresce):
  `lat`/`lon` obrigatórios, `altFt`/`hdgTrue`/`gsKt`/`iasKt`/`vsFpm`/
  `onGround` opcionais, `registradaEm` (horário do payload, relógio do PC
  do piloto) e `recebidaEm` (horário do servidor — é este que decide
  "velho demais", não o do cliente). Tabela à parte de `Aeronave` de
  propósito — ver docblock da entidade pra por quê (mesma razão de não
  normalizar telemetria em `Voo::$dados`: prematuro sem volume real pra
  guiar o schema definitivo, que o contrato completo já antecipa como
  sessão/amostra).
- `App\Repository\PosicaoAoVivoRepository` — `upsert()` (acha ou cria a
  linha da aeronave) e `findByAeronaves()` (uma consulta só pra montar a
  lista de "Em voo" do Mapa ao vivo, sem N+1).
- `POST /api/acars/v1/voos/posicao` (mesmo `AcarsIngestaoController`,
  mesma autenticação por `Authorization: Bearer`) — payload mínimo (só o
  Grupo A do contrato: `pilot_cid`, `aeronave_reg`, `at`, `lat`, `lon`
  obrigatórios; `alt_ft`/`hdg_true`/`gs_kt`/`ias_kt`/`vs_fpm`/`on_ground`
  opcionais). Exige que `iniciar` já tenha marcado a aeronave "Em voo"
  (422 se não) — mantém uma única fonte pra "o voo começou" em vez do
  heartbeat também poder promover a aeronave sozinho. `lat`/`lon` fora do
  intervalo válido (-90..90/-180..180) também é rejeitado — "ninguém
  confia no cliente" vale pro heartbeat também, mesmo sendo só posição.
- `Aeronave::ultimoPingEm` (nova coluna) — atualizada por `iniciar` (como
  primeiro heartbeat) e por `posicao` a cada ping.
  `Aeronave::getStatusEfetivo()` agora usa esse campo, quando preenchido,
  pra detectar uma sessão travada em **minutos** (`PING_MAX_MINUTOS`,
  10) em vez das 8 horas de antes (`EM_VOO_MAX_HORAS`) — um cliente que
  só chama `iniciar`/fechamento (sem atualizar pra mandar heartbeat)
  continua caindo no timeout antigo, sem quebrar.
- `MapaAoVivoController::liveFlights()` agora inclui `live: true` +
  posição real pra cada aeronave com ping recente; sem ping, `live:
  false` e cai no comportamento antigo (replay simulado, só aparece se
  houver telemetria gravada pra repetir). Rota nova, `GET
  /mapa-ao-vivo/posicoes`, devolve só isso em JSON — é o que
  `mapa-ao-vivo.js` consulta por polling a cada `POLL_MS` (12s no
  cliente, mesmo número do intervalo recomendado pro heartbeat).
- `mapa-ao-vivo.js` — `updateFlyingLive()` interpola linearmente entre o
  ping anterior e o mais recente ao longo da janela entre os dois (evita
  o marcador "saltar" a cada polling); `LIVE_STALE_MS` (3× `POLL_MS`,
  ~36s) é a tolerância antes de uma aeronave "Em voo" sem ping novo cair
  de volta pro replay simulado sozinha — tolera 1-2 polls perdidos sem
  alternar entre os dois modos a cada falha isolada. O popup de cada
  aeronave mostra "Posição real (ACARS)" ou "Posição simulada" conforme
  o caso.
- `tools/acars-capture/katabatic_capture.py` — `PositionPinger`, uma
  thread separada que manda a amostra mais recente do Grupo A (já lida
  pelo loop principal a 1 Hz) a cada `--pos-interval` segundos (padrão
  12; `0` desliga só o heartbeat, sem desligar início/fechamento). Nunca
  bloqueia a captura: sem fila nem retry (diferente do payload de
  fechamento, que tem `upload_payload.json` como rede de segurança) — se
  um ping falhar, o próximo (12s depois) resolve sozinho; mensagens de
  erro são deduplicadas (só imprime de novo se o erro mudar) pra não
  poluir o console num voo longo sem servidor. `katabatic.bat` ganhou
  `KATABATIC_ACARS_POS_INTERVAL` (padrão 12) na configuração do topo.
- `App\Event\AeronavePosicaoAtualizadaEvent` — disparado a cada ping
  aceito, **sem nenhum listener hoje**. Existe como encaixe pronto pra
  um push futuro (Mercure — é o que a seção 5.3 do contrato completo já
  pressupõe — ou WebSocket) se o polling deixar de ser suficiente algum
  dia: um listener novo se registra sozinho, sem tocar em
  `AcarsIngestaoController` nem no resto do fluxo.
- Migration `migrations/Version20260822090000.php` — cria
  `posicao_ao_vivo` e adiciona `aeronave.ultimo_ping_em`.

**Lacunas conhecidas, de propósito, nesta fatia:**

- Ainda é polling, não push — ver decisão no topo desta seção. Uma
  aeronave que passa a "Em voo" ou pousa **entre** um reload e outro só
  aparece/some da lista no próximo reload da página; o polling só
  refina a *posição* de quem já estava na lista carregada, não
  adiciona/remove aeronave da tela (`mapa-ao-vivo.js`, ver comentário no
  topo do arquivo).
- Sem fila/retry nos pings de posição (aceitável — é heartbeat, não
  telemetria que precisa ser perfeita; o próximo ping, 12s depois,
  resolve sozinho).
- Sem gzip — payload de posição é minúsculo (~200 bytes), não precisa.
- Token único fixo continua igual (mesma lacuna já anotada na fase 2).
- Grupos B (forças/G) e eventos discretos continuam só chegando no
  fechamento do voo — o índice de dificuldade/turbulência não muda
  nesta fatia.
- Sem correlação com Agendamentos — isso é outro item do roadmap ("Ligação
  com o ACARS" em "Agendamento de voo").

**Como ligar isso na sua máquina:**

```bash
# aplica a migration desta fatia (tabela posicao_ao_vivo, coluna
# aeronave.ultimo_ping_em):
php bin/console doctrine:migrations:migrate

# no PC do piloto, sem nada extra pra configurar - o heartbeat já vai
# junto com o mesmo comando de sempre:
katabatic.bat KBT118 PAFA PABT carga
# ... POST .../voos/posicao dispara sozinho a cada 12s (ajustável via
# KATABATIC_ACARS_POS_INTERVAL no .bat, ou --pos-interval direto no
# script) enquanto grava - o Mapa ao vivo já mostra a posição real
# assim que o primeiro ping chegar ...
```

## Backend: agendamento de voo

Sétima fatia de backend — a tela `/agendamentos` (ver "Agendamento de
voo" acima pra como ela se comporta) sai de "array mock manipulado só
em memória do navegador" pra tabela de verdade, mesmo padrão de
Solicitações: criar/editar/remover são `fetch` POST de verdade,
persistem entre reloads e são visíveis pra qualquer piloto logado
(não só quem criou).

**O que existe agora:**

- `App\Entity\Agendamento` (`src/Entity/Agendamento.php`) — uma linha
  por perna agendada: `aeronave` (FK de verdade pra `Aeronave` — ver
  docblock da classe pra por que esta, diferente de `Voo::$aeronaveReg`,
  não repete o gap de matrícula como texto solto: `Agendamento` nasceu
  depois de `Aeronave` já ser schema real, então não faz sentido herdar
  aquele atalho), `origem`, `destino`, `tipoOperacao`, `piloto` (texto
  livre, não FK — o formulário sugere o nome do piloto logado mas
  permite sobrescrever pra agendar em nome de outra pessoa), `de`/`ate`
  (janela de horário) e `notas` opcionais.
- `App\Repository\AgendamentoRepository` — `findAllOrderedByWindow()`
  (estado inicial da tela) e `hasOverlap()`, a validação que decide de
  verdade se duas pernas da mesma aeronave se sobrepõem.
- `AgendamentoController` ganhou três rotas novas: `POST /agendamentos`
  (criar), `POST /agendamentos/{id}/atualizar` (editar) e
  `POST /agendamentos/{id}/remover` (remover) — todas exigem sessão
  (mesmo guard simples do resto do app), nenhuma exige o papel "admin"
  (é uma agenda operacional compartilhada, não uma lista pessoal por
  piloto). A frota do formulário (`fleet`) passou a vir de
  `AeronaveRepository::findAllOrderedByBaseAndReg()` em vez do array
  mock que a tela sempre teve.
- **Atualizado:** os campos Origem/Destino do formulário eram um
  `<select>` alimentado pelo catálogo de aeroportos inteiro — deixou de
  fazer sentido depois que esse catálogo passou a ter milhares de
  linhas (ver "Importação global" em "Backend: aeroportos e pouso
  alternativo (diversão)" abaixo). Viraram um combobox de busca
  (mesmo endpoint `GET /aeroportos/buscar` da tela admin de
  aeroportos): digita ICAO ou cidade, escolhe da lista, o valor de
  verdade continua num `<input type="hidden">` com o mesmo id que o
  `<select>` antigo tinha (`#f-origem`/`#f-destino`), então toda a
  validação/sugestão de sequência existente não precisou mudar.
- **Validação em duas camadas, de propósito.** `agendamento.js`
  continua checando sobreposição de horário e sugerindo/avisando sobre
  a sequência de posição no próprio navegador, pra dar feedback
  imediato sem round-trip — mas isso sozinho não basta: duas abas ou
  dois pilotos diferentes podem validar cada um contra o próprio
  snapshot em memória e ainda assim os dois POSTs criarem uma
  sobreposição de verdade no banco. `AgendamentoRepository::hasOverlap()`
  roda de novo no servidor em cada criação/edição e é quem decide de
  fato — se um POST perder essa corrida, a resposta vem com o erro e
  `agendamento.js` mostra ele no formulário em vez de fingir que salvou
  (ver docblock do controller). O aviso de sequência (perna não
  continua de onde a anterior chegou) continua só informativo, nunca
  bloqueou nada, nem no mock — não foi replicado no servidor.
- Migration `migrations/Version20260822120000.php` — cria a tabela
  `agendamento` (FK pra `aeronave`, índice composto em
  `aeronave_id, de, ate` — a mesma consulta de `hasOverlap()` roda a
  cada criação/edição). Sem seed: as seis pernas que existiam no mock
  eram só pra mostrar a tela funcionando, não um histórico real de
  nada — a tabela nasce vazia.

**Lacunas conhecidas, de propósito, nesta fatia:**

- Sem correlação com o ACARS ainda — ver "Ligação com o ACARS" em
  "Agendamento de voo" acima pro design pretendido (promover um
  agendamento em aberto pra "em andamento"/"concluído" quando o feed
  real bater com ele por matrícula + janela de horário). Fica pra uma
  fatia futura, depois que a ingestão ACARS tiver sessão aberta/
  fechada de verdade (ver "Backend: ingestão ACARS (MVP)").
- Corrida check-then-insert na validação de sobreposição: entre o
  `SELECT` de `hasOverlap()` e o `INSERT`/`UPDATE` que persiste o
  agendamento não há nenhum lock nem constraint de banco — dois POSTs
  simultâneos pra exatamente o mesmo instante ainda podem, em teoria,
  criar uma sobreposição (mesma janela de corrida que já existe em
  `AdesaoController::submit()` pra CID duplicado). Aceitável no volume
  de uso de hoje (poucos pilotos, cliques não são simultâneos ao
  segundo); revisar com um índice de exclusão (`EXCLUDE USING gist`)
  se isso um dia virar problema de verdade.
- Sem paginação em `findAllOrderedByWindow()` — devolve todos os
  agendamentos de uma vez, igual o mock fazia. Ok pro volume de uma
  frota pequena; não escala pra um histórico de agendamentos que
  nunca são limpos.

**Como ligar isso na sua máquina:**

```bash
php bin/console doctrine:migrations:migrate   # cria a tabela agendamento (entre outras pendentes)
```

## Backend: aeroportos e pouso alternativo (diversão)

Duas peças relacionadas, entregues juntas porque a segunda depende do
catálogo de aeroportos da primeira pra resolver "em qual aeroporto essa
aeronave pousou de verdade".

**Catálogo de aeroportos (`Aeroporto`)** substitui o antigo
`public/assets/data/airports.json` fixo (11 aeroportos hardcoded) por
uma tabela de verdade, com uma tela administrativa pra cadastrar novos
sem precisar de deploy:

- `GET /aeroportos` (admin-only, mesmo guard de sessão que
  `/solicitacoes` — `$pilot['admin']`) lista o catálogo e tem o
  formulário de cadastro (ICAO, nome, cidade, lat/lon, e opcionalmente
  marcar o aeroporto como "posto avançado" de PAFA ou SCCI).
- `POST /aeroportos` grava o novo aeroporto (valida ICAO único no
  formato `[A-Z0-9]{3,8}`, lat -90..90, lon -180..180).
- `GET /aeroportos/catalogo` é o endpoint público (qualquer piloto
  logado) que devolve o catálogo no mesmo formato JSON do
  `airports.json` antigo (`{ ICAO: { name, city, lat, lon,
  postoAvancadoDe } }`) — só esse endpoint mudou nos três
  controllers que consomem o catálogo (`AeronaveController`,
  `MapaAoVivoController`, `AgendamentoController`); os três arquivos
  JS que leem esse JSON (`aeronave.js`, `mapa-ao-vivo.js`,
  `agendamento.js`) não precisaram de nenhuma mudança de código,
  porque o formato é byte-compatível com o antigo.
- **"Posto avançado" é só organização/exibição**, de propósito — marcar
  um aeroporto novo como posto avançado de PAFA ou SCCI NÃO o transforma
  numa opção válida de `Aeronave::base` (isso continua fixo em
  PAFA/SCCI só). O rótulo aparece nos popups de aeroporto do mapa de
  histórico da frota (`/aeronave/{reg}`) e do Mapa ao vivo
  (`/mapa-ao-vivo`) como "Posto avançado de PAFA/SCCI", pra dar contexto
  visual sem mexer na regra de negócio de onde uma aeronave pode ser
  baseada. A aba "Bases" do Portal também já lista esses postos (ver
  abaixo).
- Cadastro é admin-only por decisão de produto (evitar ICAO duplicado/
  malformado poluindo o catálogo que todo mundo usa pra selecionar
  origem/destino em `/agendamentos`).
- `app:importar-aeroportos-legado` semeia os 11 aeroportos que já
  existiam em `airports.json`, já marcando os 9 que não são base
  (PABT/PFYU/PAKP/PASC/PAOT de PAFA, SCNT/SCGZ/SCFM/SCBA de SCCI) como
  posto avançado — exatamente a classificação que o array mock da aba
  Bases sempre teve embutida, só que agora vive no catálogo. Idempotente
  com backfill: quem já rodou o comando antes dessa marcação existir só
  precisa rodar de novo — a linha não duplica, só ganha o
  `postoAvancadoDe` que ainda faltava.

**Importação global (`app:importar-aeroportos-ourairports`)** — os 11
aeroportos hand-cadastrados eram suficientes pro MVP, mas limitavam
demais onde um voo podia pousar/ser agendado. Este comando novo importa
a base pública inteira do [OurAirports](https://ourairports.com/)
(CSV em domínio público, `davidmegginson.github.io/ourairports-data/airports.csv`,
atualizado com frequência pela comunidade — não é um dump estático que
envelhece no repo):

- Baixa o CSV via `curl_exec()` direto (extensão `ext-curl` do PHP, sem
  dependência de composer nova) **pra um arquivo temporário primeiro, e
  só depois faz o parse** (`fgetcsv()` linha a linha) a partir do
  arquivo local — não as duas coisas ao mesmo tempo direto do socket.
  Duas versões anteriores tropeçaram nisso: a primeira lia via
  `symfony/http-client` (`$response->toStream()`) e persistia no banco
  linha a linha durante o próprio download, o que estourava o timeout
  de inatividade sempre que o consumo ficasse mais lento que a rede
  esperava entre pacotes; separar download e parse em duas fases devia
  ter resolvido, mas em ambiente real (Windows, com Xdebug carregado)
  o download sozinho, sem processamento nenhum durante ele, continuou
  morrendo com `Idle timeout reached` — sinal de que o problema estava
  em como o transporte que o HttpClient escolhia nesse ambiente decidia
  "isso travou", não no consumo. Trocado por `curl_exec()` com
  `CURLOPT_LOW_SPEED_LIMIT`/`CURLOPT_LOW_SPEED_TIME` (só desiste se a
  velocidade cair abaixo de ~1 KB/s por 30s seguidos — o critério de
  "travou de verdade" que `curl`/navegadores usam) resolveu; `CURLOPT_FILE`
  grava direto no arquivo temporário à medida que os bytes chegam, sem
  carregar os ~12 MB inteiros na memória de uma vez, e a fase de import
  não tem mais nenhuma conexão de rede aberta pra estourar timeout,
  não importa quanto tempo o `flush()`/`clear()` em lote demore.
- Filtra pra só importar aeródromo **com ICAO real** (`icao_code`
  batendo o mesmo regex de validação que `POST /aeroportos` já usava,
  `[A-Z0-9]{3,8}`), excluindo `type=closed` e linhas sem lat/lon
  válidos — em qualquer país do mundo. **Exceção nas regiões de missão
  da rede** (Ártico/Antártico + Cone Sul): uma pista sem ICAO ainda
  entra via `gps_code`/`local_code`/`ident`, ver "Pistas sem ICAO nas
  regiões de missão" logo abaixo.
- **Nunca sobrescreve nem duplica** o que já existe: pré-carrega todos
  os ICAOs já cadastrados numa única query (`findTodosIcaosComoSet()`)
  e pula qualquer linha do CSV cujo ICAO já esteja no catálogo — os 11
  aeroportos hand-cadastrados (com `cidade`/`postoAvancadoDe` mantidos
  a mão) nunca são tocados por este comando, rodar antes ou depois de
  `app:importar-aeroportos-legado` dá o mesmo resultado. Idempotente:
  rodar de novo depois de um `Ctrl+C` no meio ou uma queda de rede só
  importa o que ainda faltava.
- Grava em lotes de 500 (`flush()` + `clear()` a cada lote) pra não
  estourar memória numa importação desse tamanho — mesmo padrão que
  `app:importar-voos-legados`/`app:importar-frota-legada` já usavam em
  escala menor.
- `cidade` dos aeroportos importados é `municipality + ", " + iso_country`
  (ex.: "Fairbanks, US") — **diferente** dos 11 hand-cadastrados, que
  têm nome de região por extenso em PT-BR ("Fairbanks, Alasca"). O CSV
  não tem esse nível de curadoria em escala global; documentado como
  limitação conhecida, não um bug (ver "Próximos passos"). Nenhum
  aeroporto importado por este comando vem com `postoAvancadoDe` —
  isso é sempre uma decisão manual de admin (ver marcação abaixo).

**Pistas sem ICAO nas regiões de missão** — decisão tomada em conversa:
a rede opera bush flying justamente em áreas onde muita pista real não
tem ICAO cadastrado (a maioria do que o OurAirports classifica como
"pequeno aeródromo" nessas regiões só tem um código local/FAA/gps).
Exigir ICAO em qualquer lugar deixaria essas pistas invisíveis pro app
mesmo sendo exatamente onde a rede voa. A exceção vale só nos países/
regiões de missão (`ImportarAeroportosOurairportsCommand::PAISES_MISSAO`
+ Alasca): Chile, Argentina, Antártida + ilhas subantárticas próximas
(Malvinas/Falkland, Geórgia do Sul), Canadá e Rússia inteiros,
Groenlândia e Svalbard, e Alasca (via `iso_region` começando com
`US-AK` — o resto dos EUA não entra nessa exceção).

- Ordem de fallback quando `icao_code` está vazio: `gps_code` →
  `local_code` → `ident`, o primeiro que for não-vazio e bater um
  regex mais frouxo que o de ICAO (`[A-Z0-9]{2,8}` — sem exigir 3
  caracteres mínimo). Toda linha importada assim entra com
  `Aeroporto::$icaoOficial = false`; tudo o mais (11 legados + ICAO
  real do resto do mundo) é `true`.
- **Um código local não é globalmente único do jeito que ICAO é** —
  duas pistas de países diferentes podem coincidir no mesmo código por
  acaso. Uma colisão dessas (com um ICAO real já existente, ou com
  outro código local já importado — inclusive numa nova rodada do
  próprio comando) é tratada como "pulado", nunca sobrescreve o que já
  está no catálogo; o resumo final do comando conta isso à parte
  (`código local já presente/colidiu`) pra não confundir com "já
  existia" (que é sempre um ICAO real repetido).
- **UI mostra um selo "Local"** discreto ao lado do código sempre que
  `icaoOficial=false` — na lista/busca da tela admin (`/aeroportos`),
  no combobox de origem/destino do Agendamento, e nos popups de
  aeroporto dos dois mapas (`/aeronave/{reg}`, `/mapa-ao-vivo`, só
  quando esse ICAO acabar marcado como posto avançado — é o único jeito
  de um código sem ICAO oficial entrar no catálogo pequeno que os mapas
  consomem). Evita que alguém confie num código que não existe fora
  deste catálogo (não aparece em cartas de navegação nem no simulador
  fora do contexto da própria pista). **Isso foi a primeira mudança em
  `aeronave.js`/`mapa-ao-vivo.js` desde a importação global** — até
  aqui os dois continuavam intocados porque só consumiam o catálogo sem
  precisar saber o que havia dentro dele; o selo precisa que o JS
  literalmente leia `icaoOficial` do payload, então deixou de ser
  "zero mudança de código", só ficou pequena (uma condicional a mais no
  HTML do popup).
- Cadastro manual (`POST /aeroportos`, tela `/aeroportos`) **continua
  exigindo ICAO real** — a exceção é só pro import em massa; um admin
  nunca cria um aeroporto com `icaoOficial=false` na mão, evita alguém
  digitar um código inventado sem querer.

**Catálogo pequeno pra tudo que é visual, busca pra tudo que é
seleção** — com o catálogo saltando de 11 pra milhares de linhas, três
suposições antigas do resto do app deixaram de valer e cada uma ganhou
o próprio ajuste, sem precisar de PostGIS nem paginação:

- `GET /aeroportos/catalogo` (o endpoint que `/aeronave/{reg}` e
  `/mapa-ao-vivo` usam pra desenhar aeroportos no mapa) **passou a
  devolver só bases + postos avançados** (`findCatalogoReferenciaArray()`,
  o mesmo pequeno conjunto que já alimentava a aba "Bases" do Portal),
  não mais o catálogo inteiro — sem essa mudança, cada mapa tentaria
  desenhar um ponto pra cada um dos milhares de aeroportos importados,
  e o JSON embutido na página pesaria megabytes. A mudança em si é
  inteiramente no servidor — `aeronave.js`/`mapa-ao-vivo.js` só
  ganharam depois uma condicional pequena pro selo "Local" (ver
  "Pistas sem ICAO nas regiões de missão" acima), não pra essa parte.
- `GET /aeroportos/buscar?q=` (rota `app_aeroportos_buscar`, exige
  sessão, sem exigir admin) é o jeito de alcançar qualquer aeroporto
  do catálogo grande: `q` casa por prefixo de ICAO ou por trecho de
  nome/cidade (case-insensitive), `LIMIT 20`, sem paginação — pensado
  pra busca-enquanto-digita, não pra listar o catálogo inteiro aos
  poucos. É o mesmo endpoint usado nos dois lugares que antes eram
  `<select>`: a busca administrativa em `/aeroportos` (pra marcar posto
  avançado, ver abaixo) e o combobox de origem/destino em
  `/agendamentos` (ver "Backend: agendamento de voo" acima).
- **Tela `/aeroportos`** (admin): a lista principal virou "Bases e
  postos avançados" (o mesmo conjunto pequeno de sempre, ordenado por
  ICAO) com o total do catálogo completo ao lado (`countAll()`), e
  ganhou um card de busca novo — digitar 2+ caracteres dispara
  `GET /aeroportos/buscar` (debounce de 300 ms) e mostra os resultados
  numa tabela separada, cada linha com um botão **Marcar posto de
  PAFA/SCCI** (ou **Remover**, se já for posto avançado de alguma base).
- `POST /aeroportos/{icao}/posto-avancado` (rota
  `app_aeroportos_marcar_posto`, admin-only, mesmo guard de
  `$pilot['admin']`) é a peça que faltava: antes da importação global,
  todo aeroporto nascia já com `postoAvancadoDe` decidido no mesmo
  formulário de cadastro — não havia como marcar um aeroporto
  *existente*. Agora que a importação traz milhares de linhas sempre
  com `postoAvancadoDe = null`, esta rota deixa um admin promover
  qualquer ICAO encontrado na busca pra posto avançado de PAFA ou SCCI
  (ou desmarcar, mandando `postoAvancadoDe: null`) sem re-cadastrar o
  aeroporto do zero. Valida que a base é uma das duas válidas
  (`PAFA`/`SCCI`) e devolve 404 se o ICAO não existir no catálogo.
- `AeroportoRepository::findNearest()` (usado por `AcarsIngestaoController`
  pra resolver pouso alternativo, ver abaixo) ganhou um pré-filtro por
  bounding box antes do Haversine exato — com o catálogo pequeno o
  Haversine em PHP sobre `findAll()` já era rápido o bastante, mas
  varrer milhares de linhas a cada pouso não seria. A query SQL agora
  restringe candidatos por `lat`/`lon` dentro de uma caixa (raio +
  correção de longitude por latitude, `cos(deg2rad($lat))`) antes do
  loop de distância exata em PHP — sem PostGIS, mesma filosofia de
  lat/lon simples do resto do catálogo.

**Aba "Bases" do Portal (`PortalController::bases()`)** religada ao
catálogo — a lista de "Estações avançadas" de cada base
(`AeroportoRepository::findPostosAvancadosDe()`) não é mais um array
fixo: um aeroporto cadastrado em `/aeroportos` e marcado como posto
avançado de PAFA ou SCCI aparece aqui sozinho, sem deploy. A distância
mostrada (`dist`) também passou a ser calculada de verdade (Haversine a
partir das coordenadas de PAFA/SCCI no catálogo), em vez de um número
digitado à mão. O que continua mock, de propósito (sem schema de
estação/METAR ainda — mesma lacuna de sempre, ver "Próximos passos"):
o boletim de vento/temperatura/visibilidade/teto no cabeçalho de cada
base, e a cor do indicador (`dot`) de cada posto avançado, que agora
fica fixa num tom neutro em vez de continuar inventando uma cor por
estação sem dado real por trás.

**Pouso alternativo / diversão (`Voo::$destinoReal`)** resolve o caso de
um voo planejado de A pra B que acaba pousando em C (problema técnico,
clima, etc.) — sem isso, `AcarsIngestaoController` sempre confiava cegamente
no `destino` que o piloto declarou no plano de voo pra atualizar
`Aeronave::posIcao` no fim do voo, mesmo quando a telemetria real (GPS)
mostrava a aeronave pousada em outro lugar:

- No fim do voo (`POST /api/acars/v1/voos`), antes de gravar a posição
  da aeronave, o controller agora tenta resolver o aeroporto real de
  pouso a partir da telemetria: primeiro o evento de touchdown
  (`telemetria.td.lat/lon`, ignorando o sentinela `(0.0, 0.0)` que
  significa "nenhum evento de touchdown foi mandado"), com fallback pra
  última amostra do track (`telemetria.track`). Esse ponto (lat/lon) é
  cruzado contra o catálogo via `AeroportoRepository::findNearest()`
  (distância Haversine, raio padrão de 15 km — sem PostGIS, mesma
  filosofia de `PosicaoAoVivo` de usar lat/lon float simples porque o
  catálogo é pequeno).
- **Se o aeroporto mais próximo encontrado for igual ao `destino`
  declarado** (ou nenhum aeroporto conhecido estiver a menos de 15 km do
  ponto de pouso), nada muda — comportamento idêntico ao de antes
  (`destino` declarado é usado, `Voo::$destinoReal` fica `null`).
- **Se for diferente**, o sistema se autocorrige: `Aeronave::posIcao` é
  gravado com o aeroporto real (não o declarado), e `Voo::$destinoReal`
  guarda esse ICAO real — o `destino` original do voo nunca é
  sobrescrito (continua sendo a rota planejada, exibida como rota
  principal em toda tela). `Voo::hasPousoAlternativo()` retorna
  `true` quando os dois divergem.
- O aviso aparece em três lugares: o relatório de voo (`/voo/{id}`)
  mostra um selo "Pousou em XXXX" ao lado do cabeçalho de rota; o
  histórico de aeronave (`/aeronave/{reg}`) marca a perna com o mesmo
  selo na lista e usa o pouso real (não o declarado) pra posicionar o
  aeroporto de chegada no mapa; e `GET /voo/{id}/telemetria` expõe
  `destino_real` no payload pra qualquer consumidor futuro.
- Corrigido de brinde nesta fatia: um bug preexistente em `voo.js` onde
  `renderHead()` sobrescrevia `innerHTML` de `#h-route` inteiro a cada
  render — o que também apagava o `<span id="crash-badge">` (selo
  "Acidentado" de uma fatia anterior), que é filho desse mesmo elemento.
  Isso significava que o selo de acidentado nunca aparecia de verdade,
  mesmo quando o voo estava marcado como acidentado. Corrigido isolando
  o texto da rota num `<span id="h-route-text">` próprio, que é o que
  `renderHead()` agora substitui — `#crash-badge` e o novo
  `#diversion-badge` ficam intactos como irmãos.

Lacunas conhecidas de propósito: sem PostGIS (o pré-filtro por bounding
box em `findNearest()` resolve bem o tamanho do catálogo de hoje, mas
uma extensão espacial de verdade escalaria melhor se o catálogo crescer
muito mais); raio de 15 km é fixo (não configurável por tela); busca
(`GET /aeroportos/buscar`) não tem fuzzy matching, só prefixo de ICAO
e substring de nome/cidade — erro de digitação não acha nada; nenhum
dos comboboxes novos (admin e Agendamento) tem navegação por teclado
(setas/Enter), só clique/toque; `cidade` dos aeroportos importados via
OurAirports usa código de país ISO cru (`"Fairbanks, US"`) em vez de
nome de região por extenso em PT-BR, diferente dos 11 hand-cadastrados
(ver "Importação global" acima); código local (pistas sem ICAO, ver
"Pistas sem ICAO nas regiões de missão" acima) não tem verificação
antecipada de colisão entre países — só descobre no momento do import,
e a resolução é sempre "quem chegou primeiro fica".

**Como ligar isso na sua máquina:**

```bash
php bin/console doctrine:migrations:migrate      # cria a tabela aeroporto e a coluna voo.destino_real
php bin/console app:importar-aeroportos-legado   # importa os 11 aeroportos que já existiam em airports.json
php bin/console app:importar-aeroportos-ourairports  # importa a base pública inteira (OurAirports) - precisa de internet, pode demorar alguns minutos
```

O import global usa `ext-curl` do PHP direto, não precisa de nenhuma
dependência nova de composer (ver "Importação global" acima pro porquê
de não ser mais `symfony/http-client`). Se você chegou a rodar
`composer require symfony/http-client` numa tentativa anterior, pode
tirar com `composer remove symfony/http-client` — deixou de ser usado
por qualquer coisa no projeto.

**Gotcha conhecido no Windows:** se o comando falhar com `SSL
certificate problem: unable to get local issuer certificate`, é o PHP
local sem um pacote de certificados raiz configurado (comum em
instalações standalone/XAMPP no Windows — diferente da maioria das
distros Linux, que já vêm com isso pronto) — não é um problema no CSV
nem no servidor remoto. O comando já detecta esse erro específico e
imprime o passo a passo de correção na tela (baixar o `cacert.pem`
oficial da Mozilla e apontar `curl.cainfo`/`openssl.cafile` pra ele no
php.ini do CLI), então basta seguir as instruções que aparecem e rodar
o comando de novo.

Rodar o import é importante: sem ele o catálogo fica vazio, os
seletores de aeroporto em `/aeronave/{reg}`, `/mapa-ao-vivo` e
`/agendamentos` não mostram nenhum aeroporto até alguém cadastrar um
novo em `/aeroportos`, e a aba "Bases" do Portal mostra zero estações
avançadas em cada base. Quem já tinha rodado o import legado antes da
marcação de posto avançado existir só precisa rodar de novo (é
idempotente com backfill, ver acima); o import global também é seguro
de rodar mais de uma vez (idempotente, nunca sobrescreve). **Precisa
rodar `doctrine:migrations:migrate` antes do import** — a coluna
`aeroporto.icao_oficial` (ver "Pistas sem ICAO nas regiões de missão"
acima) é nova; sem ela o comando falha ao tentar persistir a primeira
pista sem ICAO. O resto do schema (tabela `aeroporto` em si) já existe
desde a fatia anterior.

## Backend: Ferramentas do piloto e tipos de aeronave

Item do backlog ("Ideias futuras: Ferramentas do piloto", abaixo desta
seção até então) virou tela de verdade: **`/ferramentas`**, aberta a
qualquer piloto logado (não é área de admin, mesmo padrão de
`ManuaisController`), com 4 calculadoras client-side na mesma página
(`FerramentasController`, `ferramentas.js`):

- **Vento cruzado/cauda** — trigonometria pura (pista + direção/
  intensidade do vento), não depende de nenhum dado cadastrado.
- **Conversor de unidades + ETA** — distância (NM/km/mi), velocidade
  (kt/km-h/mph), peso (lb/kg), combustível (gal/L) e altitude (ft/m),
  mais tempo de voo/horário de chegada a partir de distância + GS
  (+ horário de partida opcional). Também não depende de dado
  cadastrado.
- **Peso e balanceamento** — soma tripulação + carga + combustível
  (com seletor de densidade Avgas/Jet A) ao peso vazio do tipo e
  compara com o MTOW. **Deliberadamente não calcula CG/envelope/
  momento** — é só a checagem de peso total, não substitui o
  manifesto de peso e balanceamento real do voo.
- **Distância de decolagem/pouso ajustada** — aplica uma regra de
  bolso (correção por altitude de densidade + componente de vento,
  ver `FerramentasController::index()`/`ferramentas.js` pros fatores
  exatos) sobre a distância de referência do tipo. **Estimativa
  aproximada**, avisada como tal na própria tela — não interpola o
  gráfico de performance do POH. Como `App\Entity\Aeroporto` não tem
  campo de elevação (ver "Lacunas conhecidas" mais abaixo), a
  elevação da pista entra manualmente, não vem do cadastro de
  aeroporto.

**De onde vêm os números:** de `App\Entity\TipoAeronave`, tabela nova
(`tipo_aeronave`, ver migration) com o perfil de performance **por
tipo** de aeronave (peso vazio, MTOW, combustível máximo, consumo
médio, distância de decolagem/pouso de referência — nível do mar, ISA,
sem vento), cadastrada por um admin em **`/tipos-aeronave`**
(`TipoAeronaveController`, CRUD completo — criar, editar, remover,
mesmo guard `$pilot['admin']` de `AeroportoController`). A ligação com
a frota é por **valor de string** (`TipoAeronave::$nome` precisa bater
exatamente com `Aeronave::$tipo`), não por FK — mesmo padrão que
`Voo::$aeronaveReg`↔`Aeronave::$reg` já usa pra relação "fraca" (ver
docblock de `Voo`), e pelo mesmo motivo: `Aeronave::$tipo` já é texto
livre (inclui "outro tipo" digitado à mão em `NovaAeronaveController`),
então uma FK travaria exatamente o caso que já é permitido hoje. O
formulário de `/tipos-aeronave` oferece um `<select>` com os tipos que
já existem na frota (`AeronaveRepository::findDistinctTipos()`) pra
reduzir o risco de erro de digitação nesse casamento por string, com
"outro tipo" como opção pra cadastrar um perfil antes mesmo de existir
alguma aeronave daquele tipo na frota.

**Nenhum valor de performance foi pré-cadastrado.** Toda `TipoAeronave`
nasce com os campos numéricos em `null` — a tabela é só a estrutura;
quem preenche os números reais (tirados do POH/AFM de cada tipo) é o
admin, à mão, pelo formulário. `/ferramentas` mostra um aviso (com link
pra `/tipos-aeronave`) em vez de calcular algo em cima de um tipo sem
perfil cadastrado ou com os campos relevantes em branco — nunca assume
um valor. Unidades usadas são as mesmas do POH de cada fabricante
(libras, galões, pés), pra copiar direto sem converter.

Rail: **Ferramentas** entrou no grupo "Operação" (visível a qualquer
piloto, depois de Manuais) e **Tipos de aeronave** entrou no grupo
"Administração" (só admin, depois de Aeroportos).

**Como ligar isso na sua máquina:**

```bash
php bin/console doctrine:migrations:migrate   # cria a tabela tipo_aeronave
```

Sem isso, `/tipos-aeronave` cadastra normalmente (a tabela existe), mas
`/ferramentas` mostra o aviso de "sem perfil cadastrado" pra toda
aeronave/tipo até alguém preencher os números.

**Lacunas conhecidas, de propósito, nesta fatia:**

- Sem envelope de CG/momento na calculadora de peso e balanceamento
  (ver acima) — só checagem de peso total vs. MTOW.
- Distância ajustada é regra de bolso, não gráfico de performance real
  interpolado — tratar como estimativa, nunca como número final de
  despacho.
- `App\Entity\Aeroporto` não tem campo de elevação, então a distância
  ajustada não puxa a elevação da pista de destino/origem automaticamente
  — item futuro se isso incomodar na prática.
- As fotos de marketing enviadas pro Home mostram uma aeronave com
  pintura "TBM 850", tipo que não existe na frota seed de 6 aeronaves
  (`ImportarFrotaLegadaCommand`) nem, portanto, seria coberto por um
  `TipoAeronave` cadastrado a partir do `<select>` de tipos conhecidos —
  discrepância de composição de frota ainda não resolvida, sinalizada
  aqui pra não se perder.
- Sem endpoint de importação/fonte pública de dados de performance —
  cadastro é sempre manual, à mão, um tipo de cada vez.
- Duas candidatas da lista original de ideias ainda não viraram
  calculadora: **planejamento de combustível** (distância + consumo +
  reserva) e **altitude densidade** como ferramenta própria (hoje só
  existe embutida dentro da calculadora de distância ajustada, não como
  conta isolada) — ficam anotadas aqui pra não se perder, sem prioridade
  definida.

## Backend: realismo da ingestão ACARS (peso, carga, METAR, través de pista)

Quatro enriquecimentos em `AcarsIngestaoController::ingerir()`, todos
"melhor esforço" (nenhum bloqueia a gravação do voo se faltar dado) e
**nenhum exigindo mudança no script de captura** (`katabatic_capture.py`
já manda tudo que os dois primeiros precisam) — decisão tomada em
conversa depois de revisar o que a ingestão MVP (ver "Backend: ingestão
ACARS (MVP)") ainda deixava na mesa:

- **Peso real de decolagem vs. MTOW do tipo.** `ident.weight_lb`
  (`TOTAL WEIGHT`, lido uma vez no início da sessão — ver
  `docs/payload-telemetria-acars.md`, seção 3.1) sempre esteve no
  payload, mas nunca tinha sido lido no servidor. Agora vira
  `telemetria.pesoDecolagemLb`, comparado com o `pesoMaxDecolagemLb` do
  `TipoAeronave` cadastrado (ver "Backend: Ferramentas do piloto e
  tipos de aeronave" acima) — `voo.js` mostra dois tiles novos nos KPIs
  ("Peso decolagem" / "Margem até MTOW", este com aviso quando
  negativo). Ambos os tiles somem quando faltar peso no payload ou MTOW
  no cadastro do tipo — nunca um número inventado.
- **Carga/payload estimada por subtração.** `carga` deixou de ser
  sempre "Não informada pelo ACARS" — quando o payload trouxe
  `weight_lb` e o tipo tem `pesoVazioLb` cadastrado, vira `peso total −
  peso vazio − combustível inicial` (rotulado "estimado" no Logbook,
  detalhe do voo). Sem CG: bagagem mal distribuída não muda o número,
  só o centro de gravidade, que esta conta nem tenta calcular.
- **METAR real da origem.** Novo `App\Service\MetarClient` (`curl_exec()`
  direto contra `aviationweather.gov`, mesmo padrão de
  `ImportarAeroportosOurairportsCommand` — sem dependência nova de
  composer, sem chave/token) busca o METAR mais recente publicado pro
  ICAO de origem no momento em que o servidor processa o fechamento do
  voo. Preenche `Voo::$dados['metar']` (sempre `null` até esta fatia) —
  aparece no detalhe do Logbook ("METAR na decolagem"). **Aproximação
  documentada:** é o METAR mais recente no momento do POST, não o
  METAR histórico de verdade do horário exato do voo (ver docblock de
  `MetarClient` pra por quê) — a diferença costuma ser de minutos, dado
  que os voos são curtos e o cliente envia assim que a gravação
  encerra.
- **Través com heading de pista real.** Novo campo opcional
  `Aeroporto::$pistaPrincipalHeadingMag` (0-359, magnético — cadastrado
  no formulário "Novo aeroporto" ou marcado depois via `POST
  /aeroportos/{icao}/pista-principal`, mesmo padrão estreito de
  "marcar posto avançado"). Quando o aeroporto de pouso REAL do voo tem
  essa informação, `TelemetryDeriver::recomputeWindcComHeadingDePista()`
  recalcula o través contra a pista de verdade em vez da aproximação
  padrão (heading da aeronave no toque) — `voo.js` marca o tile de
  vento com um título explicando a fonte quando isso acontece. **Não
  corrige variação magnética** entre o heading magnético cadastrado e
  `wind_dir` (que o simulador manda em graus verdadeiros) — imprecisão
  documentada, relevante sobretudo em latitudes altas do Ártico, onde a
  variação magnética pode ser grande.

**Como ligar isso na sua máquina:**

```bash
php bin/console doctrine:migrations:migrate   # cria aeroporto.pista_principal_heading_mag
```

Peso/carga/través já funcionam com o que já está cadastrado hoje
(`ext-curl` do PHP, já usado em outra fatia, é o único requisito extra
— sem instalação nova). METAR depende só de o servidor alcançar
`aviationweather.gov` pela rede; se estiver bloqueado (firewall
corporativo, ambiente sem internet), o campo cai pro fallback "Não
disponível" sozinho, sem quebrar nada.

**Lacunas conhecidas, de propósito, nesta fatia:**

- METAR só busca o aeródromo de ORIGEM (o rótulo "METAR na decolagem"
  já existia assim) — não busca o do destino, nem popula o boletim
  ainda-mock da aba "Bases" do Portal (`PortalController`), que
  continua fora do escopo desta fatia.
- Carga estimada não sabe se o número faz sentido (peso vazio errado
  no cadastro do tipo produz uma "carga" errada silenciosamente) — é
  só subtração, sem nenhuma validação cruzada.
- Heading de pista modela só UMA pista por aeroporto (a principal),
  não múltiplas orientações — e continua exigindo cadastro manual, sem
  fonte externa (nenhuma base pública de pistas foi integrada).
- Limite de G continua cadastrado por AERONAVE (`Aeronave::$limiteG`),
  não herdado do `TipoAeronave` — ideia que ficou de fora desta fatia,
  descrita mas não implementada.

## Fotos do voo (galeria no relatório)

Galeria de fotos anexada pelo próprio piloto ao relatório de um voo
(`/voo?id=...`) — ideia de realismo parecida com a fatia anterior
(METAR/peso/carga/través): print do FlightAware, foto da cabine, o
que fizer sentido pra aquela missão.

- `App\Entity\Voo::getFotos()`/`setFotos()` — lista de
  `{arquivo, enviadoEm}` guardada dentro de `dados`, mesmo lugar de
  `pilotReport` (nunca uma coluna própria — segue o padrão que o
  relato já usava). São só as referências; os arquivos em si ficam em
  disco.
- `VooController::adicionarFotos()` (`POST /voo/{codigo}/fotos`,
  multipart, campo `fotos[]`) e `removerFoto()`
  (`POST /voo/{codigo}/fotos/remover`) — mesmo guard de posse de
  `relato()`/`marcarAcidentado()` (só o piloto que voou pode mexer na
  galeria daquele voo). Limite de 12 fotos por voo
  (`VooController::MAX_FOTOS_POR_VOO`). Envio em lote é parcial de
  propósito: se 1 de 5 fotos falhar, as outras 4 ainda são salvas — a
  resposta sempre traz a galeria atualizada mais o erro da última
  falha, se houve.
- `App\Service\FotoVooUploader` — processa e salva cada foto em
  `public/uploads/voos/{codigo}/` (mesma pasta usada pra fotos de
  perfil em `public/uploads/avatars/`, ver `PerfilController`, só que
  organizada por voo em vez de flat). Quando a extensão **GD** está
  disponível (`gdDisponivel()`), a foto não é só movida: é reencodada
  pra JPEG, redimensionada (lado maior até 2000px) e regravada — dois
  efeitos colaterais deliberados nisso:
  1. **Descarta o EXIF original**, inclusive coordenadas de GPS, se a
     foto veio direto da câmera/celular do piloto. Diferente da foto
     de perfil (só o próprio piloto vê), a galeria de um voo é visível
     pra quem acessa aquele relatório — sem isso, uma foto "de
     verdade" vazaria de onde ela foi tirada.
  2. **Lê a orientação EXIF antes de descartar o resto** e gira a
     imagem de acordo (`exif_read_data()`, extensão `ext-exif`, best
     effort) — sem isso, foto tirada em retrato no celular sai deitada
     depois do reencode.

  Sem GD instalada, cai num fallback simples (move direto, mesmo
  caminho de `PerfilController::storePhoto()`) — a galeria continua
  funcionando, só sem reencode/rotação/limpeza de EXIF nesse caso. Pra
  checar se sua instalação de PHP tem GD: `php -m | grep -i gd`
  (`ext-exif`, opcional, é separada: `php -m | grep -i exif`).
- `VooController::excluir()` (hard-delete de um voo) ganhou uma
  limpeza extra: apaga a pasta de fotos em disco
  (`FotoVooUploader::removerPasta()`) antes de apagar a linha, pra não
  deixar arquivo órfão pra trás. `marcarAcidentado()` não mexe nas
  fotos (o voo continua existindo, só marcado).
- `voo.js`/`voo/index.html.twig` — novo card "Fotos do voo" logo
  abaixo do relato do piloto: grade de miniaturas (clique abre a foto
  em tamanho real numa aba nova), botão de remover que aparece no
  hover, e um input de upload múltiplo. Nenhum toggle de "público vs.
  privado" foi construído: hoje o Logbook já é sempre por piloto (nem
  `/voo` nem `/portal` mostram voo de outro piloto — ver docblock de
  `VooController::telemetria()`), então "todo mundo vê" já é o
  comportamento padrão de quem já pode ver aquele relatório. Se um dia
  existir uma visão de Logbook cross-piloto, as fotos aparecem nela de
  graça, sem precisar mexer em mais nada.

**Lacunas conhecidas, de propósito, nesta fatia:**

- Sem moderação/denúncia de conteúdo — qualquer imagem que passe na
  checagem de MIME/tamanho é aceita. Numa VA pequena e com posse
  restrita ao próprio piloto isso é aceitável por ora, mas não escala
  pra uma comunidade grande sem alguma camada de revisão.
- Sem cota de disco por piloto ou por instância — só o limite de 12
  fotos por voo. Uso de disco cresce com o uso da galeria; não há
  limpeza automática além do que `excluir()` já faz.
- `public/uploads/` precisa existir e ser gravável pelo usuário do
  servidor web (mesma exigência que a foto de perfil já tinha) — sem
  isso, o upload falha com "Não foi possível salvar a foto no
  servidor." em vez de quebrar a página.

## Referências externas (relatório de voo)

O card "Referências externas" do relatório de voo sempre teve 5 links
— todos mock (`href="#"`) desde o mockup original. Cada um virou real
de um jeito diferente, dependendo do que dava pra saber de verdade:

- **CSV bruto** (`GET /voo/{codigo}/telemetria.csv`) e **Traço GPX**
  (`GET /voo/{codigo}/track.gpx`) — 100% dados nossos, sem depender de
  nada externo. `VooController::telemetriaCsv()`/`trackGpx()` montam o
  arquivo na hora a partir da telemetria já gravada (`track`/`prof`/
  `env`, ver `TelemetryDeriver`). O CSV é uma linha por segundo (mesma
  cadência de `track`/`prof`, que compartilham o mesmo array de
  amostras) com as colunas de `env` (mais esparsas, ~0,1 Hz)
  preenchidas pelo último valor conhecido — mesma lógica de "último
  valor até aqui" que `voo.js` já usa no hover do debrief (`at()`). O
  GPX é track única em GPX 1.1, altitude convertida de pés pra metros
  (exigência do formato). Mesmo guard de posse dos outros endpoints do
  voo (`findOneByCodigoForPilot()`).
- **VATSIM** — vira um link real pro perfil público de estatísticas do
  piloto: `https://stats.vatsim.net/stats/{cid}`. **Atenção:** esse
  formato de URL foi o mais confiável que achamos, mas não veio de
  documentação oficial confirmada — se não estiver resolvendo mais,
  troque só a string em `templates/voo/index.html.twig` (é a única
  ocorrência, dentro do `href` do card). Não é a sessão online exata
  desse voo (não guardamos nenhum ID de sessão VATSIM), é o perfil
  agregado do piloto.
- **AvioDeck** — vira um link real pro perfil do piloto lá:
  `https://aviodeck.app/@{usuário}`, formato confirmado observando
  perfis reais publicados. Depende de um campo novo e opcional em
  `/perfil` (`Pilot::$aviodeckUsername`, ver `PerfilController`) — sem
  usuário cadastrado, o card vira um convite pra preencher o perfil em
  vez de link morto. Também não é a entrada exata desse voo no
  AvioDeck (a plataforma importa voos automaticamente da VATSIM, o
  piloto não tem um "link daquele voo" pra colar), é o perfil.
- **SimBrief** — o mais diferente dos cinco: **o próprio piloto cola o
  link**, voo por voo (`POST /voo/{codigo}/simbrief`,
  `Voo::getSimbriefLink()`/`setSimbriefLink()`, mesma ideia de
  `pilotReport` — mora dentro de `dados`, editável a qualquer momento
  no relatório, nunca vem do ACARS). **Por que não um campo fixo no
  perfil, como AvioDeck:** o SimBrief não expõe uma URL pública estável
  por Pilot ID pra "o plano de voo tal, de tal data" — só "o último
  plano gerado por esse Pilot ID" (`simbrief.com/api/xml.fetcher.php?
  userid=...`, que também é só uma API em XML, não uma página de
  navegador), que fica errado assim que o piloto gera outro OFP
  qualquer depois. Como o SimBrief entrega um link de verdade pro
  piloto no momento em que ele gera o plano, pedir pra colar esse link
  é o único jeito confiável de linkar o OFP certo — o card mostra
  "Adicionar" quando não tem link salvo, e um ✎ pra editar/trocar
  quando já tem. Validação rasa (só confere `http(s)` + domínio
  `simbrief.com`), não confirma que é o plano certo — isso o piloto
  garante ao colar.

**Lacunas conhecidas, de propósito:**

- Formato de URL do VATSIM não confirmado contra documentação oficial
  (ver aviso acima) — teste com seu próprio CID depois de subir esta
  fatia.
- SimBrief/AvioDeck não validam que o link/usuário realmente existe
  (sem chamada às APIs deles) — só formato de URL/domínio.
- Sem link direto pro plano de voo específico no AvioDeck nem pra
  sessão VATSIM específica desse voo — ambos ficam no nível de perfil
  do piloto, não do voo, porque nenhum dos dois serviços expõe (ou nós
  guardamos) um identificador por voo pra linkar contra.

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
  somados (**atualizado:** contagem de verdade vinda de `voo`, ver
  `VooRepository::countsByPilot()`/`countForPilot()` — antes ficava
  fixo em 0 pra todo mundo) e status (ativo/inativo), mais busca por
  nome/CID.

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

## Próximos passos (da fase de mockup)

Ver `docs/mockups-originais/README-mockups-original.md` para o histórico
completo de decisões de produto. Resumo do que vem depois das telas:

1. ~~Schema do banco~~ Login, Perfil (`Pilot`, ver "Backend: login e
   perfil"), Adesão/Solicitações (`MembershipRequest`, ver "Backend:
   adesão e solicitações"), Voos/telemetria (`Voo`, ver "Backend: voos
   e telemetria"), Frota (`Aeronave`, ver "Backend: mapa ao vivo e
   histórico da frota"), Agendamento de voo (`Agendamento`, ver
   "Backend: agendamento de voo") e Aeroportos (`Aeroporto`, ver
   "Backend: aeroportos e pouso alternativo (diversão)") — schema
   completo pras oito fatias de backend feitas até agora.
2. Migrar as outras nove telas (Portal, Voo, Novo voo, Nova aeronave,
   Histórico de aeronave, Mapa ao vivo, Agendamentos, Manuais,
   Solicitações/Pilotos — todas já com dados reais onde existem, mas
   ainda com o guard manual de sessão) pra ler o piloto autenticado de
   verdade (`$this->getUser()`), tirando o shim descrito na seção de
   Backend.
3. ~~Frota como schema de verdade (`Aeronave`)~~ / ~~Contagem de `voos`
   no grid de Pilotos (`Pilot::$voos`)~~ / ~~"Novo voo" gravar um voo
   novo de verdade~~ Feito (ver "Backend: mapa ao vivo e histórico da
   frota", "Backend: adesão e solicitações" e "Backend: voos e
   telemetria" acima) — falta ainda o modo "Importar telemetria" (essa
   tela) fazer parsing de CSV de verdade, mas a via real de telemetria
   hoje já é a ingestão ACARS ao vivo (ver item 4 abaixo).
4. ~~Fork do cliente ACARS~~ / ~~Importador dos dados do
   `katabatic_capture.py`~~ / ~~Status "Em voo" em tempo real~~ / ~~Posição
   em tempo real no Mapa ao vivo~~ Feito, na medida do MVP (ver "Backend:
   ingestão ACARS (MVP)" e "Backend: posição em tempo real (ACARS fase
   3)") — `katabatic_capture.py` manda um POST no início, um heartbeat de
   posição a cada ~12s durante o voo e um POST de fechamento no final;
   `AcarsIngestaoController` já persiste um `Voo` de verdade a partir do
   último, `Aeronave::status`/`emVooDesde` já viram "Em voo" sozinhos no
   primeiro (com autocorreção — em minutos, não mais horas, pra quem já
   manda heartbeat), e o Mapa ao vivo já mostra posição real (via polling)
   em vez de só replay pra quem manda o heartbeat. O que falta do contrato
   completo (`docs/payload-telemetria-acars.md`, v1.1): sessão aberta/
   fechada de verdade com streaming em grupos A-F (o que existe hoje é um
   heartbeat simples, não uma sessão com sequência/reenvio), fila com
   reenvio automático no cliente, gzip, token por piloto, push (Mercure/
   WebSocket) em vez de polling.
5. Busca de METAR/TAF e validação cruzada com VATSIM — hoje
   `TelemetryDeriver` não busca nenhum dos dois (`metar` fica `null`,
   `través` é aproximado sem heading de pista de verdade); é o que dá
   sentido a normalizar a telemetria de `Voo::$dados['telemetria']` num
   schema de amostras mais rico, se algum dia fizer falta.
6. Job de recorte GRIB
7. Ligar todas as telas ao backend real
8. ~~Religar a aba "Bases" do Portal ao catálogo `Aeroporto` novo~~ Feito
   (ver "Backend: aeroportos e pouso alternativo (diversão)") — falta
   ainda o boletim de vento/temperatura/visibilidade/teto de cada base,
   que segue mock (sem schema de estação/METAR, mesma lacuna do item 5).
9. ~~Importar o catálogo de aeroportos inteiro (não só os 11
   hand-cadastrados)~~ / ~~Trazer pistas sem ICAO nas regiões de
   missão~~ Feito (ver "Importação global" e "Pistas sem ICAO nas
   regiões de missão" em "Backend: aeroportos e pouso alternativo
   (diversão)") — `app:importar-aeroportos-ourairports` traz ICAO real
   de qualquer país mais código local/FAA nas regiões de missão
   (Ártico/Antártico + Cone Sul), `/aeroportos/buscar` substituiu os
   `<select>` de aeroporto por busca em todo lugar que precisava
   escolher um, mapas/telas continuam só mostrando bases + postos
   avançados por padrão, e um selo "Local" na UI distingue código sem
   ICAO oficial. Falta: fuzzy matching na busca, navegação por teclado
   nos comboboxes novos, nome de região por extenso em PT-BR pra
   aeroportos importados (hoje usam código de país ISO cru), e detecção
   antecipada de colisão de código local entre países (ver "Lacunas
   conhecidas" na mesma seção).
