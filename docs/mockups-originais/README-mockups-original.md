# Katabatic — pacote de mockups e artefatos

Companhia aérea virtual (MSFS 2024 + VATSIM), operação de carga, transporte
de pessoal e pesquisa meteorológica em área extrema. Duas bases em
hemisférios opostos — **PAFA** (Fairbanks, Alasca) e **SCCI** (Punta Arenas,
Chile) — cada uma com estações avançadas em aeródromos menores ao redor.
Callsign **KBT**, telefonia **KATABATIC**.

Este pacote é o estado do projeto ao final de uma fase longa de decisões
de produto e prototipagem visual. Ainda não existe uma linha de backend —
tudo aqui é mockup em HTML autocontido, com dados de exemplo ou (em vários
casos) dados reais de voos de teste que o usuário gravou.

**Para retomar em uma conversa nova**: cole este README (ou anexe o zip
inteiro) e diga que é a continuação do projeto Katabatic. O README basta
para não repetir perguntas já respondidas — não é preciso recontar o
histórico.

## Estrutura do pacote

```
katabatic-mockups/
├── README.md                        este arquivo
├── payload-telemetria-acars.md      contrato de dados do ACARS (v1.1, validado em voo)
├── katabatic_capture.py             script de captura de SimVars (probe/record)
├── katabatic.bat                    atalho Windows para rodar o script
└── mockups/
    ├── katabatic-home.html          home institucional pública
    ├── katabatic-portal.html        área logada: Logbook, Frota, Bases
    ├── katabatic-voo.html           relatório de um voo (mapa, debrief, índice)
    ├── katabatic-novo-voo.html      formulário de registro de voo
    └── katabatic-nova-aeronave.html formulário de cadastro de aeronave
```

Todos os `.html` são autocontidos — abrem direto no navegador, sem servidor
nem build. Usam Leaflet + tiles CARTO/OpenStreetMap via CDN (precisam de
internet para os mapas; o resto funciona offline). Todas as telas têm
alternância de tema claro/escuro; a home e o mapa têm PT/EN.

## O que cada mockup cobre

**`katabatic-home.html`** — hero, quadro de situação das duas bases, mapa
com abas Norte/Sul mostrando ramificações até as estações avançadas
(coloridas pela condição prevista no destino), seção institucional com
disclaimer de simulação, bases, frota, últimas operações, critérios de
admissão de tripulação. O mapa tem três camadas de dado real: **Radar**
(RainViewer, cobertura só onde há estação em solo — bom no Alasca, quase
nulo na Patagônia), **Modelo** (OpenWeather Weather Maps 1.0, cobertura
global — pede uma chave de API grátis na primeira vez que é ativado) e o
próprio traçado de rota. Radar e Modelo se excluem mutuamente.

**`katabatic-portal.html`** — três abas: Logbook (lista filtrável por tipo/
período/busca, ordenável por cabeçalho, com toggle de densidade normal/
compacta), Frota (toggle grade/lista, filtro por base) e Bases (situação
meteorológica e estações avançadas de cada uma).

**`katabatic-voo.html`** — a tela mais elaborada. Usa **dados reais** dos
três voos de teste do usuário (claro/chuva/neve) em vez de mock inventado.
Tem: cabeçalho com rota e índice de dificuldade, 18 indicadores, barra de
fases do voo, mapa com três camadas (turbulência/clima/altitude) e popup
de condição por estação amostrada, painel de "debrief" com 7 gráficos
sincronizados por um cursor único (altitude, velocidade, VS, G, turbulência,
temperatura, vento) que também move um ponto no mapa, card de pouso com
veredito (suave/normal/firme/duro), composição do índice por parcela,
eventos legendados em linguagem humana, referências externas (SimBrief,
AvioDeck, VATSIM, download de CSV/GPX) e relatório de missão dividido em
resumo automático (gerado da telemetria) + relato do piloto (modo leitura
por padrão, com botão Editar/Salvar/Cancelar).

**`katabatic-novo-voo.html`** — formulário com duas abas: **Importar
telemetria** (fluxo real de hoje — arrasta a pasta gerada pelo
`katabatic_capture.py`, simula o parse e preenche automaticamente data/
hora/rota/aeronave/dificuldade) e **Registro manual** (sem telemetria,
marca o voo como "não verificado", dificuldade vira estimativa manual que
não entra no índice comparativo). Detecta a matrícula do arquivo importado
e avisa quando ela não bate com nenhuma aeronave da frota — pega o mesmo
bug real do `ATC ID` que apareceu nos testes.

**`katabatic-nova-aeronave.html`** — matrícula com prefixo por país
(Chile `CC-`/EUA `N`) que já mostra a forma normalizada sem hífen (a que o
ACARS vai enviar), tipo, base, limite de G e VS máxima de pouso (usados no
índice de dificuldade), fotos, e um aviso lembrando de configurar o
`ATC ID` no simulador com essa mesma matrícula.

## Decisões de produto já tomadas

- **Escopo enxuto**: logbook + frota + bases + mapa de clima. Sem sistema
  de ranks, contratos ou missões — decisão explícita para manter o projeto
  pequeno e terminável. Uso real no dia a dia, não para comercializar
  (embora possa abrir ao público um dia).
- **Dificuldade é sempre medida, nunca digitada.** Vem da telemetria
  (turbulência, G, precipitação, gelo, vento, superfície). Voos sem
  telemetria entram marcados "não verificado" e não contam no índice
  comparativo — reforçado tanto na tela do voo quanto no formulário de
  cadastro.
- **Duas fontes de clima, papéis diferentes**: OpenWeather (grátis) só
  para os tiles do mapa ao vivo; o histórico detalhado por voo continua
  vindo de um pipeline GRIB próprio (NOAA/NOMADS), porque é o diferencial
  real do produto. METAR (AviationWeather.gov) fica reservado para
  visibilidade e teto nos aeródromos, já que o simulador não fornece isso
  de forma confiável (ver seção de bugs abaixo).
- **Idiomas**: PT e EN agora; ES e RU no roadmap (RU pela comunidade
  russófona da VATSIM e pela proximidade Alasca–Chukotka).
- **Parceria com a VATSIM**: adiada para depois do primeiro ano — o foco
  agora é uso diário real, não certificação. Callsign KBT confirmado
  livre; matrículas sempre CC- (Chile) ou N (EUA); bases PAFA e SCCI com
  estações avançadas ao redor.
- **Tripulação por avaliação**, não entrada aberta — 3 voos de avaliação,
  critérios objetivos validados pelo próprio sistema (sem slew, sem sim
  rate ≠ 1, pouso dentro do limite de VS, etc.), porque o roster é
  selecionado e pequeno de propósito.
- **Navegação por lista, não por dropdown de callsign** — Logbook é lista
  plana ordenável/filtrável; a página do voo individual tem só
  anterior/próximo cronológico, sem seletor.

## Bugs reais já encontrados (testados em voo, não hipotéticos)

Tudo abaixo já está refletido no `payload-telemetria-acars.md` v1.1 e/ou
nos formulários de cadastro:

- `SIM RATE` não existe no MSFS 2024 — o nome certo é `SIMULATION RATE`.
- `AMBIENT VISIBILITY` e `AMBIENT PRECIP STATE` **não acompanham o clima**
  — ficam travados (~135-138 km e valor 4, respectivamente), mesmo dentro
  de nevasca. Visibilidade e teto precisam vir de METAR externo.
- `AMBIENT PRECIP RATE` funciona de verdade (confirmado com chuva e neve
  reais) — junto com `oat_c`, distingue chuva de neve.
- `AMBIENT WIND VELOCITY`/`DIRECTION` funcionam (variam de verdade em
  voo) — o vento fica no simulador, não precisa de METAR.
- `STRUCTURAL ICE PCT` funciona mas acumula devagar (0,26% em 5 min a
  -20 °C) — limiar de evento ajustado para 1%, não 5%.
- `ATC MODEL` devolve um token de localização, não o tipo ICAO — o tipo
  certo vem da tabela de frota pela matrícula, não do simulador.
- `ATC ID` é a **matrícula** da aeronave, não o callsign do voo —
  configurar errado deixa o voo "órfão" de aeronave. As telas de cadastro
  já avisam sobre isso.
- Pitch/bank vêm com sinal invertido; `td_vs_fpm` vem como magnitude
  positiva (diferente de `VERTICAL SPEED`, que é negativo descendo).
- `G FORCE` não é exatamente 1,0 em repouso — calibrar com tolerância.
- Um `\n` literal (em vez de quebra de linha real), inserido sem querer
  via script Python, corrompeu o CSS de uma tela em silêncio — motivo pelo
  qual toda edição feita por script agora passa por validação antes de
  ser entregue (ver seção seguinte).

## Como os mockups foram validados

Todo HTML/CSS/JS gerado ou editado por script neste projeto passou por
três checagens automáticas antes de ser considerado pronto — vale manter
esse hábito em qualquer edição futura:

1. **Aninhamento HTML real**, com `html.parser.HTMLParser` do Python
   percorrendo a pilha de tags (contagem de `<div>`/`</div>` não basta —
   já mascarou um bug de estrutura antes).
2. **Sintaxe JS**, com `node --check arquivo.js`.
3. **Execução real do JS** dentro de um DOM falso montado em Node
   (`getElementById`, `classList`, `style`, `addEventListener` etc.
   simulados via stubs), para pegar erros de runtime sem precisar de
   navegador.

## Próximos passos sugeridos, em ordem

1. **Schema do banco** (Postgres + PostGIS + TimescaleDB) — ainda não
   feito. `voo_posicao` como hypertable; ver o modelo ER discutido
   (piloto, aeronave, aeroporto, voo, voo_posicao, voo_evento, voo_clima).
2. **Fork do cliente ACARS** (candidato: snekACARS, Python/PyQt6) usando
   o `payload-telemetria-acars.md` como especificação — já validado contra
   o simulador de verdade, não é mais suposição.
3. **Servidor de ingestão** (Symfony) implementando os endpoints do
   contrato (`/sessions`, `/sessions/{id}/telemetry`,
   `/sessions/{id}/close`).
4. **Importador** que lê as pastas do `katabatic_capture.py` e insere como
   se tivessem vindo do ACARS — ponte entre os testes manuais de hoje e o
   sistema real. O formulário `katabatic-novo-voo.html` já modela essa UX.
5. **Job de recorte GRIB** disparado automaticamente no fechamento do voo
   — a janela de radar/modelo é curta, não dá para gerar sob demanda
   depois.
6. Ligar as telas ao backend real, trocando os arrays JS mock por chamadas
   de API.

## Como continuar numa conversa nova

Sugestão de abertura: anexar o zip (ou colar este README) e escrever algo
como *"Aqui está o pacote da Katabatic — vamos começar o schema do banco
de dados a partir do modelo ER que já discutimos"*, ou o próximo item da
lista acima que fizer mais sentido no momento.
