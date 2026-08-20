# Payload de telemetria — ACARS Katabatic

Contrato entre o cliente ACARS (PC do piloto, MSFS 2024 via SimConnect) e a API de ingestão.

- **Versão:** 1.1 — revisada contra o simulador
- **Mudou da 1.0:** `SIM RATE` virou `SIMULATION RATE`; `AMBIENT VISIBILITY` e `AMBIENT PRECIP STATE` saíram do contrato (não acompanham o clima); visibilidade e teto passam a vir do METAR; `AMBIENT PRECIP RATE` confirmado; sinal de pitch/bank e de `td_vs_fpm` documentados.
- **Escopo:** define o que é lido do simulador, com que frequência, e em que formato chega ao servidor.
- **Não cobre:** cálculo do índice de dificuldade, camadas GRIB, autenticação de sessão web.

---

## 1. Princípios

1. **O cliente envia dado bruto; o servidor deriva.** Nada de calcular índice de dificuldade, pontuação de pouso ou fase de voo no cliente e mandar pronto. Se a fórmula mudar, você recalcula 200 voos com um comando em vez de pedir pra todo mundo atualizar o app.
2. **Unidade no nome do campo.** `alt_ft`, `gs_kt`, `vis_m`. Elimina a classe inteira de bugs de conversão silenciosa.
3. **Ninguém confia no cliente.** Ele roda na máquina do piloto. Tudo que chega é validado: saltos de posição, `sim_rate`, `slew`, sequência de timestamps.
4. **Perder rede não pode perder voo.** Fila local persistente, reenvio idempotente.
5. **Payload versionado.** O campo `schema` viaja em toda mensagem. Cliente antigo e cliente novo coexistem.
6. **Radical na coleta, conservador no envio.** Ler 40 variáveis é barato; mandar 40 campos a 1 Hz não é. Agrupar por taxa de mudança (seção 2).

---

## 2. Grupos de amostragem

O erro clássico é ler tudo na mesma frequência. A pressão barométrica não muda 60 vezes por minuto; a aceleração vertical, sim.

| Grupo | Taxa | Conteúdo | Envio |
|---|---|---|---|
| `A` — cinemática | 1 Hz | posição, atitude, velocidades | lote a cada 15 s |
| `B` — forças | 5 Hz agregado para 1 Hz | acelerações, fator de carga | estatística por segundo (min/max/RMS) |
| `C` — ambiente | 0,1 Hz (10 s) | clima, temperatura, gelo | lote a cada 60 s |
| `D` — estado | por mudança | trem, flap, luzes, motores | vira evento |
| `E` — integridade | 1 Hz | `sim_rate`, slew, pausa | anexado ao lote A |
| `F` — rajada de toque | 20 Hz, janela de ±5 s do toque | toda a cinemática + forças | lote único no evento `touchdown` |

O grupo B merece destaque: **é dele que sai a turbulência**, que é o dado que nenhuma outra VA tem. Amostrar a 5 Hz e enviar apenas `g_min`, `g_max` e `g_rms` por segundo dá o número sem inflar o volume.

O grupo F é o que permite calcular taxa de toque, bounce e G de impacto com precisão. Fora dessa janela, 20 Hz é desperdício.

---

## 3. Catálogo de SimVars

Nomenclatura oficial do SimConnect. Unidade explícita na requisição (o SimConnect converte).

### 3.1 Identificação (lido uma vez, no início da sessão)

| SimVar | Unidade | Campo | Nota |
|---|---|---|---|
| `TITLE` | string | `aircraft_title` | nome do addon, útil pra auditoria |
| `ATC MODEL` | string | `aircraft_icao` | tipo ICAO (C208, DHC6...) |
| `ATC ID` | string | `tail_number` | matrícula — casa com a tabela `aeronave` |
| `ATC FLIGHT NUMBER` | string | `flight_number` | |
| `ATC AIRLINE` | string | `airline` | |
| `TOTAL WEIGHT` | pounds | `weight_lb` | peso inicial e final |
| `FUEL TOTAL QUANTITY WEIGHT` | pounds | `fuel_lb` | também amostrado no grupo C |

### 3.2 Grupo A — cinemática (1 Hz)

| SimVar | Unidade | Campo |
|---|---|---|
| `PLANE LATITUDE` | degrees | `lat` |
| `PLANE LONGITUDE` | degrees | `lon` |
| `PLANE ALTITUDE` | feet | `alt_ft` |
| `PLANE ALT ABOVE GROUND` | feet | `agl_ft` |
| `INDICATED ALTITUDE` | feet | `alt_ind_ft` |
| `PLANE HEADING DEGREES TRUE` | degrees | `hdg_true` |
| `PLANE HEADING DEGREES MAGNETIC` | degrees | `hdg_mag` |
| `PLANE PITCH DEGREES` | degrees | `pitch` |
| `PLANE BANK DEGREES` | degrees | `bank` |
| `AIRSPEED INDICATED` | knots | `ias_kt` |
| `AIRSPEED TRUE` | knots | `tas_kt` |
| `GROUND VELOCITY` | knots | `gs_kt` |
| `VERTICAL SPEED` | feet per minute | `vs_fpm` |
| `SIM ON GROUND` | bool | `on_ground` |

> **Sinal invertido.** Pitch é negativo com o nariz em cima: um taildragger parado marcou `-10,7`. Bank segue a mesma lógica. Guardar cru no payload e normalizar no servidor, para não inverter o gráfico de perfil.
>
> Pedir explicitamente em `degrees` na definição do data block — o padrão é radianos.

### 3.3 Grupo B — forças (5 Hz → agregado)

| SimVar | Unidade | Campo agregado |
|---|---|---|
| `G FORCE` | gforce | `g_min`, `g_max`, `g_rms` |
| `ACCELERATION BODY X` | feet per second squared | `acc_x_rms` (lateral) |
| `ACCELERATION BODY Y` | feet per second squared | `acc_y_rms` (vertical) |
| `ACCELERATION BODY Z` | feet per second squared | `acc_z_rms` (longitudinal) |
| `ROTATION VELOCITY BODY X/Y/Z` | radians per second | `rot_rms` | opcional, mede o "chacoalhar" |

**Índice de turbulência** = desvio-padrão de `ACCELERATION BODY Y` numa janela móvel de 60 s, normalizado. Calculado no servidor a partir do `acc_y_rms`.

Dois cuidados medidos em voo:

- **Aceleração de corpo carrega gravidade.** Parada e inclinada 19°, a aeronave marcou `acc_x -2,32` e `acc_y -1,59`; nivelada, ambas perto de zero. Use sempre **variância**, nunca valor absoluto.
- **Vibração de motor entra na conta.** Com motor ligado e aeronave imóvel, `acc_y` = -1,25; com motor desligado, exatamente 0,0. Existe piso de ruído por aeronave — descontar essa linha de base, senão taxiar parece turbulência.
- **`G FORCE` não marca 1,0 em repouso.** Deu 0,982 nivelada. Calibrar com a média dos primeiros segundos parado, ou tolerar ±0,02.

### 3.4 Grupo C — ambiente (0,1 Hz)

Este grupo **é** o diferencial da Katabatic. É o que o piloto realmente enfrentou, não o que uma API meteorológica acha que estava acontecendo.

| SimVar | Unidade | Campo | Nota |
|---|---|---|---|
| `AMBIENT TEMPERATURE` | celsius | `oat_c` | |
| `TOTAL AIR TEMPERATURE` | celsius | `tat_c` | |
| `AMBIENT PRESSURE` | inHg | `qnh_inhg` | |
| `AMBIENT WIND VELOCITY` | knots | `wind_kt` | |
| `AMBIENT WIND DIRECTION` | degrees | `wind_dir` | |
| ~~`AMBIENT VISIBILITY`~~ | — | — | **FORA DO CONTRATO.** Medida com tempo bom e com tempo extremo: valor idêntico (~138 600 m). Não acompanha meteorologia. Visibilidade vem do METAR |
| ~~`AMBIENT PRECIP STATE`~~ | — | — | **FORA DO CONTRATO.** Travado em 4 (chuva) em todas as leituras, com sol ou temporal |
| `AMBIENT PRECIP RATE` | millimeters of water | `precip_rate` | **Confirmado.** 30,06 no temporal, 0,0 no tempo bom. Descartar a primeira leitura da sessão, que vem com lixo. Chuva ou neve: cruzar com `oat_c` |
| `AMBIENT IN CLOUD` | bool | `in_cloud` | |
| `AMBIENT DENSITY` | slugs per cubic feet | `air_density` | |
| `STRUCTURAL ICE PCT` | percent over 100 | `ice_pct` | operação extrema: essencial |
| `SURFACE TYPE` | enum | `surface_type` | cascalho, grama, neve — só em solo |
| `SURFACE CONDITION` | enum | `surface_cond` | molhada, com gelo — só em solo |

**Risco de gelo** é derivado no servidor: `oat_c` entre -12 °C e +2 °C **com** (`in_cloud` ou `precip_state != 2`), corroborado por `ice_pct`.

### 3.5 Grupo D — estado (por mudança, vira evento)

| SimVar | Unidade | Evento gerado |
|---|---|---|
| `GEAR HANDLE POSITION` | bool | `gear_up` / `gear_down` |
| `FLAPS HANDLE INDEX` | number | `flaps_change` |
| `SPOILERS HANDLE POSITION` | percent | `spoilers` |
| `BRAKE PARKING POSITION` | bool | `parking_brake` |
| `ENG COMBUSTION:1..4` | bool | `engine_start` / `engine_shutdown` |
| `LIGHT LANDING` / `LIGHT BEACON` / `LIGHT STROBE` | bool | `lights` |
| `ELECTRICAL MASTER BATTERY` | bool | `battery` |
| `STALL WARNING` | bool | `stall_warning` |
| `OVERSPEED WARNING` | bool | `overspeed` |
| `CRASH FLAG` / `CRASH SEQUENCE` | enum | `crash` |

### 3.6 Grupo E — integridade

| SimVar | Unidade | Campo | Para quê |
|---|---|---|---|
| `SIMULATION RATE` | number | `sim_rate` | rejeitar trecho se ≠ 1. **Não é `SIM RATE`** — esse nome não existe e devolve `UNRECOGNIZED_ID` |
| `IS SLEW ACTIVE` | bool | `slew` | invalida o voo |
| `SIM PAUSED` (evento `Pause`) | bool | `paused` | descontar do tempo de voo |
| `ZULU TIME` / `ABSOLUTE TIME` | seconds | `sim_time` | detectar troca de horário do sim |
| `REALISM` | mask | `realism` | opcional |

### 3.7 Grupo F — janela de toque (20 Hz)

Além de todo o grupo A e B, no evento de toque:

| SimVar | Unidade | Campo |
|---|---|---|
| `PLANE TOUCHDOWN NORMAL VELOCITY` | feet per minute | `td_vs_fpm` — **magnitude positiva**, ao contrário de `VERTICAL SPEED`. Um toque a 499 fpm chega como `499`, não `-499` |
| `PLANE TOUCHDOWN PITCH DEGREES` | degrees | `td_pitch` |
| `PLANE TOUCHDOWN BANK DEGREES` | degrees | `td_bank` |
| `PLANE TOUCHDOWN HEADING DEGREES MAGNETIC` | degrees | `td_hdg` |
| `PLANE TOUCHDOWN LATITUDE` / `LONGITUDE` | degrees | `td_lat` / `td_lon` |

---

## 4. Eventos discretos

O cliente detecta e envia. Cada evento carrega `t`, `type`, `lat`, `lon`, `alt_ft` e um `data` livre em JSON.

**Ciclo de voo:** `session_start`, `engine_start`, `pushback`, `taxi_out`, `takeoff`, `climb`, `cruise`, `descent`, `approach`, `touchdown`, `taxi_in`, `engine_shutdown`, `session_end`.

**Excedências:** `overspeed`, `stall`, `overg` (com `g_max`), `gear_overspeed`, `hard_landing`, `bounce`, `crash`.

**Operacionais Katabatic:** `icing_onset` (ice_pct cruzou 5 %), `severe_turbulence` (índice acima do limiar), `low_vis_approach` (vis < 1500 m abaixo de 1000 ft AGL), `cargo_state` (manual, o piloto marca).

**Integridade:** `sim_rate_change`, `slew_detected`, `pause`, `resume`, `connection_lost`, `connection_restored`.

> Fase de voo é detectada no cliente porque depende de histórico contínuo (o servidor recebe lotes). Mas é enviada como **evento**, e o servidor pode recalcular a partir da série se quiser.

---

## 5. Contrato de API

Base: `https://<host>/api/acars/v1`
Auth: `Authorization: Bearer <token_acars>` — token por piloto, revogável, gerado no perfil web.
Encoding: JSON, UTF-8, `Content-Encoding: gzip` obrigatório em lotes.

### 5.1 Abrir sessão

`POST /sessions`

```json
{
  "schema": "1.0",
  "session_id": "0195f3b2-8c41-7e2a-9d55-3f1a2b7c8d90",
  "client": { "name": "katabatic-acars", "version": "0.4.1", "os": "win11" },
  "sim": { "name": "MSFS2024", "build": "1.5.9.0" },
  "pilot_cid": "1234567",
  "aircraft": {
    "aircraft_title": "Cessna 208B Grand Caravan EX",
    "aircraft_icao": "C208",
    "tail_number": "PP-KTB",
    "weight_lb": 8100,
    "fuel_lb": 1450
  },
  "planned": {
    "origin": "SBCH",
    "destination": "SBFI",
    "tipo_operacao": "carga",
    "objetivo_missao": "Suprimento da base sul",
    "observacao": "Carga sensível a temperatura"
  },
  "started_at": "2026-08-18T13:04:22Z"
}
```

Resposta `201`: `{ "session_id": "...", "voo_id": "...", "accepted_schema": "1.0" }`

O `session_id` é **gerado pelo cliente** (UUIDv7). Isso é o que torna o reenvio idempotente: se a resposta se perder, o cliente repete o `POST` e o servidor devolve o mesmo `voo_id` em vez de criar um voo duplicado.

### 5.2 Enviar telemetria

`POST /sessions/{session_id}/telemetry`

```json
{
  "schema": "1.0",
  "seq": 42,
  "samples": [
    {
      "t": "2026-08-18T13:19:07Z",
      "lat": -27.13445, "lon": -52.66123,
      "alt_ft": 8500, "agl_ft": 6120, "alt_ind_ft": 8480,
      "hdg_true": 214.5, "pitch": 1.8, "bank": -12.4,
      "ias_kt": 142, "tas_kt": 161, "gs_kt": 155, "vs_fpm": -320,
      "on_ground": false,
      "g_min": 0.71, "g_max": 1.48, "g_rms": 1.09,
      "acc_y_rms": 3.42,
      "sim_rate": 1, "slew": false
    }
  ],
  "env": [
    {
      "t": "2026-08-18T13:19:00Z",
      "oat_c": -4.2, "tat_c": -1.1, "qnh_inhg": 29.74,
      "wind_kt": 38, "wind_dir": 291,
      "vis_m": 2400, "precip_state": 4, "precip_rate": 2.1,
      "in_cloud": true, "air_density": 0.00189, "ice_pct": 12.5,
      "fuel_lb": 1180
    }
  ],
  "events": [
    { "t": "2026-08-18T13:18:44Z", "type": "icing_onset",
      "lat": -27.11, "lon": -52.64, "alt_ft": 8600,
      "data": { "ice_pct": 5.2, "oat_c": -3.9 } }
  ]
}
```

Regras:

- `seq` é monotônico por sessão. O servidor guarda o último `seq` aceito e **descarta duplicatas** silenciosamente (resposta `200`, não erro).
- Lote máximo: 200 amostras ou 512 KB comprimido.
- Servidor responde `202 Accepted` imediatamente e processa em fila. Nunca fazer o cliente esperar pelo processamento.
- `409` significa sessão já fechada — o cliente para de tentar e arquiva localmente.

### 5.3 Fechar sessão

`POST /sessions/{session_id}/close`

```json
{
  "schema": "1.0",
  "ended_at": "2026-08-18T14:41:09Z",
  "final": {
    "origin_actual": "SBCH",
    "destination_actual": "SBFI",
    "weight_lb": 7240,
    "fuel_lb": 310,
    "block_time_s": 5807,
    "air_time_s": 5220,
    "distance_nm": 187.4
  },
  "touchdown": {
    "td_vs_fpm": -214,
    "td_pitch": 4.1, "td_bank": -0.8, "td_hdg": 214,
    "td_lat": -25.5998, "td_lon": -54.4869,
    "burst": [ { "t": "...", "g_max": 1.62, "vs_fpm": -214, "ias_kt": 71 } ]
  },
  "observacao": "Aproximação com teto baixo, arremetida na primeira tentativa."
}
```

O servidor então: consolida o voo, calcula o índice de dificuldade, dispara o job de recorte GRIB e publica no Mercure pra tirar a aeronave do mapa ao vivo.

### 5.4 Códigos de resposta

| Código | Significado | Ação do cliente |
|---|---|---|
| `200` / `201` / `202` | aceito | segue |
| `400` | payload inválido | **não reenviar** — logar e descartar |
| `401` | token inválido | pede novo login |
| `409` | sessão já fechada | arquiva local |
| `413` | lote grande demais | divide pela metade |
| `429` | rate limit | backoff exponencial, respeita `Retry-After` |
| `5xx` | erro do servidor | fila, backoff, reenvia |

---

## 6. Resiliência no cliente

1. **Fila persistente em SQLite.** Toda amostra é gravada em disco antes de tentar ir pra rede. Queda de internet, queda do servidor, notebook hibernando — nada se perde.
2. **Backoff exponencial** com teto de 5 min. Nunca martelar o servidor.
3. **Recuperação de crash.** Se o app fechar no meio do voo, ao reabrir ele detecta a sessão aberta e oferece retomar (mesmo `session_id`).
4. **Voo offline completo.** Se o servidor ficar inacessível o voo inteiro, o cliente guarda tudo e sincroniza depois. O PIREP entra com atraso, mas entra.
5. **Relógio.** Usar o horário do sistema em UTC, e enviar também `sim_time`. Divergência grande entre os dois é sinal de manipulação.

---

## 7. O que o servidor deriva (nunca vem do cliente)

- Fases de voo consolidadas e tempos de bloco/ar
- **Índice de dificuldade** e suas parcelas (vento de través, teto/visibilidade, turbulência RMS, pico de G, gelo, tipo/comprimento de pista, período noturno)
- Pontuação de pouso
- Classificação de acidente/incidente a partir de `crash`, `overg`, `hard_landing`
- Rota como `geography(LineString)` simplificada (Douglas-Peucker) pra render rápido
- **Busca de METAR/TAF do aeródromo mais próximo** no horário do voo (AviationWeather.gov). Passou de opcional a obrigatório: é a única fonte de **visibilidade e teto**, já que as variáveis do simulador não acompanham o clima
- Validação cruzada com o datafeed da VATSIM (callsign, CID, presença online na janela do voo)
- Recorte GRIB do bounding box + janela temporal

---

## 8. Estado das pendências

Resolvido em voo (MSFS 2024, C185F, Aleutas):

- [x] `AMBIENT PRECIP RATE` existe e responde à intensidade
- [x] `AMBIENT VISIBILITY` **não** reflete o clima — descartada, substituída por METAR
- [x] `AMBIENT PRECIP STATE` travado — descartada
- [x] `SURFACE TYPE` / `SURFACE CONDITION` funcionam com cenário em streaming e fora de aeroporto (asfalto → floresta → neve, seco → molhado → neve)
- [x] `SIMULATION RATE` é o nome correto
- [x] `ATC MODEL` devolve token de localização (`ATCCOM.AC_MODEL C185.0.text`); tipo da aeronave vem da tabela de frota pela matrícula, não do simulador
- [x] Campos `td_*` são reais e persistentes

Ainda em aberto:

- [ ] **`AMBIENT WIND VELOCITY` / `DIRECTION` acompanham o clima?** Não testado com preset de vento forte. Se travarem como a visibilidade, o vento de través também migra para o METAR — e aí o índice fica majoritariamente METAR
- [ ] `STRUCTURAL ICE PCT` sobe de fato em condição de gelo?
- [ ] `CRASH FLAG` com detecção de colisão desligada nas opções
- [ ] Base de aeroportos externa escolhida (OurAirports?)
- [ ] Limite de G por tipo: tabela manual na frota

### Composição atual do índice de dificuldade

| Parcela | Fonte |
|---|---|
| Turbulência | Simulador (`acc_y`, variância) |
| Pico e fator de carga | Simulador (`G FORCE`) |
| Superfície e condição de pista | Simulador (`SURFACE TYPE` / `CONDITION`) |
| Precipitação | Simulador (`AMBIENT PRECIP RATE` + `oat_c`) |
| Gelo | Simulador (`STRUCTURAL ICE PCT`) — a confirmar |
| Visibilidade e teto | **METAR** |
| Vento de través | Em julgamento — simulador ou METAR |
| Pista: comprimento, elevação | Base de aeroportos externa |
