#!python3
# (shebang no formato do py launcher do Windows: '/usr/bin/env python3'
#  faz o launcher procurar 'python3' e cair no stub da Microsoft Store)
# -*- coding: utf-8 -*-
"""
Katabatic - captura de SimVars (MSFS 2024 / SimConnect)

Instrumento de medicao, nao o ACARS completo do contrato v1.1
(docs/payload-telemetria-acars.md - aquele ainda descreve uma arquitetura
maior, com sessao aberta/fechada, streaming em lotes, fila SQLite, gzip;
nada disso existe aqui). Serve para tres fins:

  1. --probe   Le uma vez cada variavel do contrato de payload e diz quais
               existem, quais falham e que valor devolvem. E o que responde
               as pendencias da secao 8 do documento payload-telemetria-acars.

  2. --record  Grava o voo em CSV com as mesmas colunas do payload (fica no
               disco, sempre - e a rede de seguranca) e, se --server/--tipo/
               --origem/--destino/--pilot-cid forem passados, manda POSTs
               pro backend Symfony: um UNICO POST de fechamento quando a
               gravacao termina (Ctrl+C), e (novo, ACARS fase 3) um POST
               curto de POSICAO a cada --pos-interval segundos (padrao 12)
               enquanto grava, pro Mapa ao vivo parar de depender de replay -
               ver AcarsIngestaoController e README, "Backend: posicao em
               tempo real (ACARS fase 3)". Sem --server/--tipo/--origem/
               --destino/--pilot-cid, se comporta exatamente como antes (so
               grava local, sem nenhum POST).

  3. --rebuild Reconstroi upload_payload.json de uma gravacao existente, a
               partir de samples.csv/env.csv/events.csv - sem precisar do
               simulador aberto. Existe pra quando --record e encerrado sem
               Ctrl+C (queda de energia, crash do simulador, PC travou): os
               tres CSVs ficam gravados no disco (flush frequente, ver
               Recorder), mas session.json/upload_payload.json nunca sao
               escritos (isso so acontece em Recorder.close(), chamado no
               fim normal do loop). "PASTA" e a pasta da gravacao (ex.:
               voos/20260823_141500_KBT118). callsign/matricula sao
               recuperados do evento "session_start" (primeira linha de
               events.csv) quando existir; pilot_cid/tipo/origem/destino
               continuam so por CLI (nunca ficam gravados em CSV nenhum) e
               sao opcionais - uma gravacao sem eles fica com esses campos
               vazios no upload_payload.json, exatamente como uma gravacao
               feita sem --tipo/--origem/--destino em --record, e da pra
               preencher depois na hora de importar pelo site (ver
               NovoVooController, "Backend: importacao de telemetria via
               upload"). Nao faz upload sozinho por padrao - so escreve o
               arquivo (mesma "rede de seguranca" de sempre); passe
               --server/--token junto se quiser mandar direto.

Requisitos (na maquina que roda o simulador):
    python -m pip install SimConnect
    MSFS 2024 aberto e ja dentro do voo (nao no menu).

Uso:
    python katabatic_capture.py --probe
    python katabatic_capture.py --record --callsign KBT118
    python katabatic_capture.py --record --callsign KBT412 --dir D:/voos

    # com envio pro servidor (fechamento + posicao periodica; token via
    # --token ou pela variavel de ambiente KATABATIC_ACARS_TOKEN, pra nao
    # sobrar no historico do shell):
    python katabatic_capture.py --record --callsign KBT118 \\
        --pilot-cid 1234567 --tipo carga --origem PAFA --destino PABT \\
        --server http://localhost:8080

    # gravacao que caiu sem Ctrl+C (queda de energia, crash) - reconstroi
    # upload_payload.json a partir do que ja esta em disco:
    python katabatic_capture.py --rebuild voos/20260823_141500_KBT118

    # voo de helicoptero - desliga o debounce de "quique" de pista, ja que
    # hover-taxi pousa/decola varias vezes de proposito em poucos segundos
    # (ver --categoria acima):
    python katabatic_capture.py --record --callsign KBT512 --categoria helicoptero

Encerre a gravacao com Ctrl+C. Um Ctrl+C fecha os arquivos direito, para o
heartbeat de posicao e tenta o envio de fechamento (se configurado).
"""

import argparse
import csv
import json
import math
import os
import re
import signal
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

try:
    from SimConnect import SimConnect, Request
except ImportError:
    print("Falta a biblioteca. Rode:  python -m pip install SimConnect")
    sys.exit(1)


# --------------------------------------------------------------------------
# Catalogo de variaveis. Espelha o documento de payload: (campo, SimVar, unidade)
# --------------------------------------------------------------------------

IDENT = [
    ("aircraft_title", b"TITLE", b"string"),
    ("aircraft_icao", b"ATC MODEL", b"string"),
    ("tail_number", b"ATC ID", b"string"),
    ("flight_number", b"ATC FLIGHT NUMBER", b"string"),
    ("airline", b"ATC AIRLINE", b"string"),
    ("weight_lb", b"TOTAL WEIGHT", b"pounds"),
    ("fuel_lb", b"FUEL TOTAL QUANTITY WEIGHT", b"pounds"),
]

# Grupo A - cinematica, 1 Hz
GROUP_A = [
    ("lat", b"PLANE LATITUDE", b"degrees"),
    ("lon", b"PLANE LONGITUDE", b"degrees"),
    ("alt_ft", b"PLANE ALTITUDE", b"feet"),
    ("agl_ft", b"PLANE ALT ABOVE GROUND", b"feet"),
    ("alt_ind_ft", b"INDICATED ALTITUDE", b"feet"),
    ("hdg_true", b"PLANE HEADING DEGREES TRUE", b"degrees"),
    ("hdg_mag", b"PLANE HEADING DEGREES MAGNETIC", b"degrees"),
    ("pitch", b"PLANE PITCH DEGREES", b"degrees"),
    ("bank", b"PLANE BANK DEGREES", b"degrees"),
    ("ias_kt", b"AIRSPEED INDICATED", b"knots"),
    ("tas_kt", b"AIRSPEED TRUE", b"knots"),
    ("gs_kt", b"GROUND VELOCITY", b"knots"),
    ("vs_fpm", b"VERTICAL SPEED", b"feet per minute"),
    ("on_ground", b"SIM ON GROUND", b"bool"),
]

# Grupo B - forcas, lido a 5 Hz e agregado por segundo
GROUP_B = [
    ("g", b"G FORCE", b"gforce"),
    ("acc_x", b"ACCELERATION BODY X", b"feet per second squared"),
    ("acc_y", b"ACCELERATION BODY Y", b"feet per second squared"),
    ("acc_z", b"ACCELERATION BODY Z", b"feet per second squared"),
]

# Grupo C - ambiente, 0.1 Hz.
GROUP_C = [
    ("oat_c", b"AMBIENT TEMPERATURE", b"celsius"),
    ("tat_c", b"TOTAL AIR TEMPERATURE", b"celsius"),
    ("qnh_inhg", b"AMBIENT PRESSURE", b"inHg"),
    ("wind_kt", b"AMBIENT WIND VELOCITY", b"knots"),
    ("wind_dir", b"AMBIENT WIND DIRECTION", b"degrees"),
    ("vis_m", b"AMBIENT VISIBILITY", b"meters"),
    ("precip_state", b"AMBIENT PRECIP STATE", b"mask"),
    ("precip_rate", b"AMBIENT PRECIP RATE", b"millimeters of water"),
    ("in_cloud", b"AMBIENT IN CLOUD", b"bool"),
    ("air_density", b"AMBIENT DENSITY", b"slugs per cubic feet"),
    # Corrigido: pedir em "percent over 100" faz o SimConnect devolver a
    # FRACAO 0.0-1.0 (1.0 = totalmente gelado) - e o resto do app
    # (limiar de 1.0 pro evento icing_onset, peso *4 no indice de
    # dificuldade, exibicao "X.XX %" em voo.js) sempre assumiu que o
    # valor gravado ja era 0-100 direto. Resultado: todo voo gravado
    # antes desta linha reportou o gelo estrutural ~100x menor do que
    # o simulador realmente modelou. Unidade oficial confirmada em
    # docs.flightsimulator.com/html/Programming_Tools/SimVars/Simulation_Variable_Units.htm
    # ("Percent Over 100": 0.0-1.0; "Percent": 0-100) - pedir direto em
    # "percent" corrige na fonte, sem precisar mexer em mais nada. Ver
    # docs/payload-telemetria-acars.md, secao 8.
    ("ice_pct", b"STRUCTURAL ICE PCT", b"percent"),
    ("surface_type", b"SURFACE TYPE", b"enum"),
    ("surface_cond", b"SURFACE CONDITION", b"enum"),
    ("fuel_lb", b"FUEL TOTAL QUANTITY WEIGHT", b"pounds"),
]

# Grupo D - estado, vira evento quando muda
GROUP_D = [
    ("gear", b"GEAR HANDLE POSITION", b"bool"),
    ("flaps", b"FLAPS HANDLE INDEX", b"number"),
    ("spoilers", b"SPOILERS HANDLE POSITION", b"percent"),
    ("parking_brake", b"BRAKE PARKING POSITION", b"bool"),
    ("eng1", b"ENG COMBUSTION:1", b"bool"),
    ("eng2", b"ENG COMBUSTION:2", b"bool"),
    ("light_landing", b"LIGHT LANDING", b"bool"),
    ("stall_warning", b"STALL WARNING", b"bool"),
    ("overspeed", b"OVERSPEED WARNING", b"bool"),
    ("crash", b"CRASH FLAG", b"enum"),
    # Novo: liga/desliga do anti-ice, viram "state_deice_estrutural" /
    # "state_deice_parabrisa" de graça (check_state() abaixo trata
    # qualquer campo deste grupo genericamente) - ver
    # App\Service\TelemetryDeriver::STATE_FIELD_LABELS pro rotulo em
    # PT/voo.js. Direto do pedido do piloto: gelo grudou no para-brisa,
    # precisou ligar anti-ice, e isso nao ficava registrado em lugar
    # nenhum antes (so o STRUCTURAL ICE PCT, que mede acumulo na
    # estrutura, nao a decisao do piloto de ligar o sistema).
    ("deice_estrutural", b"STRUCTURAL DEICE SWITCH", b"bool"),
    ("deice_parabrisa", b"WINDSHIELD DEICE SWITCH", b"bool"),
]

# Grupo E - integridade
GROUP_E = [
    ("sim_rate", b"SIMULATION RATE", b"number"),   # "SIM RATE" nao existe no 2024
    ("slew", b"IS SLEW ACTIVE", b"bool"),
    ("sim_time", b"ZULU TIME", b"seconds"),
]

# Lidas apenas no toque
TOUCHDOWN = [
    ("td_vs_fpm", b"PLANE TOUCHDOWN NORMAL VELOCITY", b"feet per minute"),
    ("td_pitch", b"PLANE TOUCHDOWN PITCH DEGREES", b"degrees"),
    ("td_bank", b"PLANE TOUCHDOWN BANK DEGREES", b"degrees"),
    ("td_hdg", b"PLANE TOUCHDOWN HEADING DEGREES MAGNETIC", b"degrees"),
    ("td_lat", b"PLANE TOUCHDOWN LATITUDE", b"degrees"),
    ("td_lon", b"PLANE TOUCHDOWN LONGITUDE", b"degrees"),
]

ALL_GROUPS = [
    ("IDENT", IDENT), ("A", GROUP_A), ("B", GROUP_B),
    ("C", GROUP_C), ("D", GROUP_D), ("E", GROUP_E), ("TD", TOUCHDOWN),
]


# --------------------------------------------------------------------------
# Camada de leitura
# --------------------------------------------------------------------------

class VarReader:
    """Cria um Request por SimVar e le com tolerancia a falha.

    Variavel que nao existe no SDK simplesmente vira None em vez de
    derrubar a captura - e o probe mostra exatamente quais foram.
    """

    def __init__(self, sm):
        self.sm = sm
        self.requests = {}
        self.dead = set()

    def register(self, spec):
        for field, simvar, unit in spec:
            if field in self.requests:
                continue
            try:
                self.requests[field] = Request((simvar, unit), self.sm, _time=0)
            except Exception as exc:
                self.dead.add(field)
                print("  ! nao registrou %-14s (%s): %s" % (field, simvar.decode(), exc))

    def read(self, field):
        if field in self.dead:
            return None
        req = self.requests.get(field)
        if req is None:
            return None
        try:
            value = req.value
        except Exception:
            self.dead.add(field)
            return None
        if value is None:
            return None
        if isinstance(value, bytes):
            return value.decode("utf-8", "ignore").strip()
        if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
            return None
        return value

    def read_group(self, spec):
        return {field: self.read(field) for field, _, _ in spec}


ICAO_FROM_TOKEN = re.compile(r"AC_MODEL[ _]([A-Z0-9]{2,8})", re.I)


def guess_icao(atc_model, title):
    """ATC MODEL no MSFS 2024 volta como 'ATCCOM.AC_MODEL C185.0.text'.

    Tira o tipo de dentro do token. Se nao der, devolve o texto cru - no
    backend o tipo definitivo vem da tabela de frota pela matricula, nao daqui.
    """
    if atc_model:
        match = ICAO_FROM_TOKEN.search(str(atc_model))
        if match:
            return match.group(1).upper()
        if "." not in str(atc_model):
            return str(atc_model).strip().upper()
    return (str(title).split()[0].upper() if title else None)


def now_iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z"


def rnd(value, digits=3):
    if isinstance(value, float):
        return round(value, digits)
    return value


# --------------------------------------------------------------------------
# Modo probe
# --------------------------------------------------------------------------

def do_probe(reader):
    print("\nSondagem de SimVars - MSFS precisa estar dentro de um voo.\n")
    print("%-8s %-16s %-42s %s" % ("GRUPO", "CAMPO", "SIMVAR", "VALOR"))
    print("-" * 100)

    missing = []
    for group_name, spec in ALL_GROUPS:
        reader.register(spec)
        for field, simvar, unit in spec:
            value = reader.read(field)
            if value is None:
                shown = "-- INDISPONIVEL --"
                missing.append((group_name, field, simvar.decode()))
            else:
                shown = str(rnd(value))
            print("%-8s %-16s %-42s %s" % (group_name, field, simvar.decode(), shown))

    print("\n" + "-" * 100)
    if missing:
        print("Indisponiveis neste build (%d):" % len(missing))
        for group_name, field, simvar in missing:
            print("  [%s] %s  <- %s" % (group_name, field, simvar))
        print("\nEssas saem do contrato de payload ou trocam de fonte.")
    else:
        print("Todas as variaveis responderam.")

    print("\nAinda por verificar em voo, mesmo entre as que responderam:")
    print("  vis_m        em ceu claro marca ~135100 m (teto do sim). Confirme o piso com nevoeiro.")
    print("  precip_rate  compare o valor entre 'clear' e 'heavy rain' antes de confiar nele.")
    print("  deice_*      novos (STRUCTURAL/WINDSHIELD DEICE SWITCH) - confirme que ligar")
    print("               o anti-ice em voo realmente vira 'state_deice_estrutural'/")
    print("               'state_deice_parabrisa' no console.")
    print("  pitch/bank   sinal invertido: nariz em cima da negativo. Normalize no servidor.")
    print("  surface_type 0=concreto 1=grama 2=agua 4=asfalto 8=neve 9=gelo 12=terra 14=cascalho 21=areia")
    print("  surface_cond 0=normal 1=molhada 2=gelo 3=neve")


# --------------------------------------------------------------------------
# Modo record
# --------------------------------------------------------------------------

class Recorder:
    def __init__(self, reader, outdir, callsign):
        self.reader = reader
        self.callsign = callsign
        stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
        self.dir = os.path.join(outdir, "%s_%s" % (stamp, callsign or "SEMCALL"))
        os.makedirs(self.dir, exist_ok=True)

        self.f_samples = open(os.path.join(self.dir, "samples.csv"), "w", newline="", encoding="utf-8")
        self.f_env = open(os.path.join(self.dir, "env.csv"), "w", newline="", encoding="utf-8")
        self.f_events = open(os.path.join(self.dir, "events.csv"), "w", newline="", encoding="utf-8")

        cols_a = [f for f, _, _ in GROUP_A]
        cols_e = [f for f, _, _ in GROUP_E]
        self.w_samples = csv.DictWriter(
            self.f_samples,
            fieldnames=["t"] + cols_a + ["g_min", "g_max", "g_rms", "acc_y_rms", "b_n"] + cols_e,
        )
        self.w_env = csv.DictWriter(self.f_env, fieldnames=["t"] + [f for f, _, _ in GROUP_C])
        self.w_events = csv.DictWriter(self.f_events, fieldnames=["t", "type", "lat", "lon", "alt_ft", "data"])
        for writer in (self.w_samples, self.w_env, self.w_events):
            writer.writeheader()

        self.state = {}
        self.samples = 0
        self.events = 0
        self.started = time.time()

        # Copia em memoria das mesmas linhas que vao pro CSV - e o que vira
        # o payload de upload ao final da gravacao (ver upload_capture()).
        # Guardar os dicts nativos aqui (nao a versao ja serializada em CSV,
        # que teria "data" como string) e o que deixa o JSON final aninhar
        # "data" como objeto de verdade em vez de string escapada duas vezes.
        self.samples_list = []
        self.env_list = []
        self.events_list = []

    def event(self, kind, payload=None, pos=None):
        pos = pos or {}
        payload = payload or {}
        row = {
            "t": now_iso(), "type": kind,
            "lat": rnd(pos.get("lat"), 6), "lon": rnd(pos.get("lon"), 6),
            "alt_ft": rnd(pos.get("alt_ft"), 1),
        }
        self.events_list.append(dict(row, data=payload))
        self.w_events.writerow(dict(row, data=json.dumps(payload, ensure_ascii=False)))
        self.f_events.flush()
        self.events += 1
        print("  [evento] %s %s" % (kind, json.dumps(payload, ensure_ascii=False)))

    def check_state(self, pos):
        """Grupo D: qualquer mudanca de estado vira evento."""
        current = self.reader.read_group(GROUP_D)
        for field, value in current.items():
            if value is None:
                continue
            previous = self.state.get(field)
            if previous is None:
                self.state[field] = value
                continue
            if value != previous:
                self.state[field] = value
                self.event("state_" + field, {"de": rnd(previous), "para": rnd(value)}, pos)

    def touchdown(self, pos):
        data = {f: rnd(self.reader.read(f)) for f, _, _ in TOUCHDOWN}
        self.event("touchdown", data, pos)

    def close(self, summary):
        with open(os.path.join(self.dir, "session.json"), "w", encoding="utf-8") as handle:
            json.dump(summary, handle, ensure_ascii=False, indent=2)
        for handle in (self.f_samples, self.f_env, self.f_events):
            handle.close()


def do_record(reader, args):
    for _, spec in ALL_GROUPS:
        reader.register(spec)

    ident = {f: rnd(v) for f, v in reader.read_group(IDENT).items()}
    ident["aircraft_icao_raw"] = ident.get("aircraft_icao")
    ident["aircraft_icao"] = guess_icao(ident.get("aircraft_icao"), ident.get("aircraft_title"))
    print("\nAeronave: %s | matricula: %s | tipo: %s" % (
        ident.get("aircraft_title"), ident.get("tail_number"), ident.get("aircraft_icao")))

    if args.callsign:
        print("Callsign: %s" % args.callsign)
    tail = (ident.get("tail_number") or "").strip()
    if not tail:
        print("AVISO: matricula vazia. Configure o ATC ID no simulador (CC-KBA, N208KB...)")
    elif args.callsign and tail.upper() == args.callsign.upper():
        print("AVISO: o ATC ID esta igual ao callsign (%s)." % tail)
        print("       ATC ID e a MATRICULA da aeronave (N104KT, CC-KBA).")
        print("       O callsign do voo vai no ATC FLIGHT NUMBER, nao na matricula.")

    rec = Recorder(reader, args.dir, args.callsign)
    print("Gravando em: %s" % rec.dir)
    print("Ctrl+C encerra.\n")

    # Mesmo criterio de "enviar ou nao" pros dois POSTs (inicio e fim) - de
    # proposito, pra nao ficar meio-fiado: ou os dois disparam, ou nenhum
    # dos dois (so gravacao local, como sempre foi). Ver AcarsIngestaoController.
    faltando = [name for name, val in (
        ("--pilot-cid", args.pilot_cid), ("--tipo", args.tipo),
        ("--origem", args.origem), ("--destino", args.destino), ("--server", args.server),
    ) if not val]
    token = args.token or os.environ.get("KATABATIC_ACARS_TOKEN", "")
    enviar = not faltando and bool(token)

    if faltando:
        print("Sem envio ao servidor (faltou %s) - so gravacao local mesmo.\n" % ", ".join(faltando))
    elif not token:
        print("Sem envio ao servidor: falta o token (--token ou variavel KATABATIC_ACARS_TOKEN).\n")

    rec.event("session_start", {"ident": ident, "callsign": args.callsign})

    pinger = None
    if enviar:
        anunciar_inicio(args.server, token, {
            "pilot_cid": args.pilot_cid,
            "aeronave_reg": tail,
            "started_at": datetime.fromtimestamp(rec.started, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        })
        # Heartbeat de posicao (ACARS fase 3) - mesmo criterio "enviar" do
        # inicio/fechamento; --pos-interval 0 desliga so o heartbeat sem
        # desligar o resto (ver PositionPinger e README, "Backend: posicao
        # em tempo real (ACARS fase 3)").
        if args.pos_interval > 0:
            pinger = PositionPinger(args.server, token, args.pilot_cid, tail, args.pos_interval)
            pinger.start()
            print("Heartbeat de posicao a cada %.0fs: %s/api/acars/v1/voos/posicao\n" % (
                args.pos_interval, args.server.rstrip("/")))

    stop = {"flag": False}

    def on_sigint(signum, frame):
        stop["flag"] = True

    signal.signal(signal.SIGINT, on_sigint)

    buffer_b = {"g": [], "acc_y": []}
    clock = time.monotonic()
    next_a = clock + 1.0
    next_env = clock + 10.0
    was_on_ground = None
    warned_rate = False
    last_td = 0.0
    last_to = 0.0
    # Helicoptero legitimamente faz varios toque/decolagem curtos (hover-
    # taxi, pouso e decolagem em sequencia perto do solo) que NAO sao um
    # "quique" de asa fixa - a janela de debounce abaixo so faz sentido
    # pra aviao. Pedido em conversa: "vamos receber voos de helicoptero,
    # precisamos preparar a plataforma" - ver --categoria/BOUNCE_WINDOW_S.
    is_helicoptero = (args.categoria == "helicoptero")
    next_state = clock + 2.0   # grupo D so a cada 2 s, para sobrar tempo ao grupo B
    late = 0

    while not stop["flag"]:
        tick = time.monotonic()

        # Grupo B a 5 Hz
        g_value = reader.read("g")
        acc_y = reader.read("acc_y")
        if g_value is not None:
            buffer_b["g"].append(g_value)
        if acc_y is not None:
            buffer_b["acc_y"].append(acc_y)

        # Grupo A + E a 1 Hz
        if tick >= next_a:
            next_a += 1.0
            if tick - next_a > 0.5:      # atrasou muito: realinha em vez de acumular
                next_a = tick + 1.0
                late += 1
            row = {"t": now_iso()}
            sample_a = reader.read_group(GROUP_A)
            row.update({k: rnd(v, 6 if k in ("lat", "lon") else 2) for k, v in sample_a.items()})
            row.update({k: rnd(v, 3) for k, v in reader.read_group(GROUP_E).items()})

            gs = buffer_b["g"]
            ays = buffer_b["acc_y"]
            row["g_min"] = rnd(min(gs), 3) if gs else None
            row["g_max"] = rnd(max(gs), 3) if gs else None
            row["g_rms"] = rnd(math.sqrt(sum(x * x for x in gs) / len(gs)), 3) if gs else None
            row["acc_y_rms"] = rnd(statistics.pstdev(ays), 3) if len(ays) > 1 else None
            row["b_n"] = len(ays)        # menos de 3 = janela pobre, desconfie do rms
            buffer_b = {"g": [], "acc_y": []}

            self_pos = {"lat": sample_a.get("lat"), "lon": sample_a.get("lon"), "alt_ft": sample_a.get("alt_ft")}
            if pinger is not None:
                pinger.update(
                    lat=sample_a.get("lat"), lon=sample_a.get("lon"), alt_ft=sample_a.get("alt_ft"),
                    hdg_true=sample_a.get("hdg_true"), gs_kt=sample_a.get("gs_kt"),
                    ias_kt=sample_a.get("ias_kt"), vs_fpm=sample_a.get("vs_fpm"),
                    on_ground=sample_a.get("on_ground"),
                )
            rec.w_samples.writerow(row)
            rec.samples_list.append(dict(row))
            rec.samples += 1
            if rec.samples % 10 == 0:
                rec.f_samples.flush()

            # toque no solo
            on_ground = sample_a.get("on_ground")
            if on_ground is not None:
                if was_on_ground is False and on_ground:
                    desde = tick - max(last_td, last_to)
                    if is_helicoptero:
                        # sem debounce: cada toque e um touchdown de verdade
                        # (hover-taxi pousa/decola varias vezes em segundos,
                        # nao e um quique de pista)
                        rec.touchdown(self_pos)
                    elif desde < 12.0:
                        # perto demais de um toque OU de uma decolagem: e quique
                        rec.event("bounce", {"desde_s": round(desde, 1)}, self_pos)
                    else:
                        rec.touchdown(self_pos)
                    last_td = tick
                elif was_on_ground and not on_ground:
                    # subida logo apos o toque e quique, nao decolagem (so
                    # aplica pra aviao - ver is_helicoptero acima)
                    if is_helicoptero or tick - last_td >= 12.0:
                        rec.event("takeoff", {"ias_kt": rnd(sample_a.get("ias_kt"))}, self_pos)
                        last_to = tick
                was_on_ground = bool(on_ground)

            # integridade
            rate = row.get("sim_rate")
            if rate is not None and abs(rate - 1.0) > 0.01 and not warned_rate:
                rec.event("sim_rate_change", {"sim_rate": rate}, self_pos)
                warned_rate = True
            elif rate is not None and abs(rate - 1.0) <= 0.01:
                warned_rate = False
            if row.get("slew"):
                rec.event("slew_detected", {}, self_pos)

            if tick >= next_state:
                next_state = tick + 2.0
                rec.check_state(self_pos)

            sys.stdout.write("\r  %s | %6.0f ft | %5.0f kt | %6.0f fpm | G %4.2f | %d amostras   " % (
                "SOLO" if on_ground else "VOO",
                sample_a.get("alt_ft") or 0, sample_a.get("ias_kt") or 0,
                sample_a.get("vs_fpm") or 0, row.get("g_max") or 0, rec.samples))
            sys.stdout.flush()

        # Grupo C a cada 10 s
        if tick >= next_env:
            next_env += 10.0
            if tick - next_env > 5.0:
                next_env = tick + 10.0
            env_row = {"t": now_iso()}
            env_row.update({k: rnd(v, 3) for k, v in reader.read_group(GROUP_C).items()})
            rec.w_env.writerow(env_row)
            rec.env_list.append(dict(env_row))
            rec.f_env.flush()

            ice = env_row.get("ice_pct")
            if ice is not None and ice >= 1.0 and not rec.state.get("_icing"):
                rec.state["_icing"] = True
                rec.event("icing_onset", {"ice_pct": ice, "oat_c": env_row.get("oat_c")})
            elif ice is not None and ice < 0.5:
                rec.state["_icing"] = False

        elapsed = time.monotonic() - tick
        time.sleep(max(0.0, 0.2 - elapsed))

    if pinger is not None:
        print("\nParando o heartbeat de posicao...")
        pinger.stop()

    rec.event("session_end", {})
    duration = int(time.time() - rec.started)
    summary = {
        "callsign": args.callsign,
        "ident": ident,
        "started_at": datetime.fromtimestamp(rec.started, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "ended_at": now_iso(),
        "duration_s": duration,
        "samples": rec.samples,
        "events": rec.events,
        "unavailable_vars": sorted(reader.dead),
        "late_ticks": late,
        "schema": "1.0-csv",
    }
    rec.close(summary)

    print("\n\nEncerrado. %d amostras, %d eventos, %d min." % (rec.samples, rec.events, duration // 60))
    print("Arquivos em: %s" % rec.dir)
    if reader.dead:
        print("Variaveis que falharam: %s" % ", ".join(sorted(reader.dead)))

    codigo = os.path.basename(rec.dir)
    payload = {
        "schema": "kb-raw-1",   # NAO e o "1.0" do contrato completo - ver docstring do arquivo
        "codigo": codigo,
        "pilot_cid": args.pilot_cid,
        "callsign": args.callsign,
        "tipo_operacao": TIPO_LABELS.get((args.tipo or "").lower(), ""),
        "origem": (args.origem or "").upper(),
        "destino": (args.destino or "").upper(),
        "aeronave_reg": tail,
        "ident": ident,
        "started_at": summary["started_at"],
        "ended_at": summary["ended_at"],
        "duration_s": summary["duration_s"],
        "samples": rec.samples_list,
        "env": rec.env_list,
        "events": rec.events_list,
    }

    # Grava sempre, servidor respondendo ou nao - e a rede de seguranca
    # simplificada desta fatia (sem fila/retry automatico: se o POST
    # falhar, este arquivo fica pronto pra reenviar manualmente depois).
    payload_path = os.path.join(rec.dir, "upload_payload.json")
    with open(payload_path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)

    if not enviar:
        print("\nPayload pronto em: %s (pra reenviar manualmente depois, se quiser)." % payload_path)
        return

    upload_capture(payload, args.server, token, payload_path)


def _post_json(url, token, body_dict):
    """POST generico com Bearer, usado pelos dois endpoints do ACARS.

    Sem gzip, sem fila, sem retry automatico (simplificacoes documentadas
    no README) - quem chama decide o que fazer se der errado.
    """
    body = json.dumps(body_dict, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "Content-Type": "application/json",
        "Authorization": "Bearer %s" % token,
    })
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.status, json.loads(resp.read().decode("utf-8") or "{}")


def anunciar_inicio(server, token, payload):
    """POST .../voos/iniciar - so sinaliza "Em voo" pro Mapa ao vivo.

    Nao aborta a gravacao se falhar (impressao de aviso e segue voando) -
    o POST de fechamento ao final e o que importa de verdade; este e so
    uma cortesia pro Mapa ao vivo mostrar a aeronave voando em tempo real.
    """
    url = server.rstrip("/") + "/api/acars/v1/voos/iniciar"
    print("Avisando o servidor que a gravacao comecou: %s ..." % url)
    try:
        status, resposta = _post_json(url, token, payload)
        print("Aeronave marcada 'Em voo' no servidor (%d): %s" % (status, resposta))
    except urllib.error.HTTPError as exc:
        print("Servidor recusou o inicio (%d): %s" % (exc.code, exc.read().decode("utf-8", "ignore")))
        print("Segue gravando normalmente - so o Mapa ao vivo nao vai mostrar 'Em voo' em tempo real.")
    except urllib.error.URLError as exc:
        print("Nao conectou no servidor pra avisar o inicio: %s" % exc.reason)
        print("Segue gravando normalmente - so o Mapa ao vivo nao vai mostrar 'Em voo' em tempo real.")


class PositionPinger:
    """Heartbeat de posicao - POST curto pro Mapa ao vivo a cada N segundos
    (ACARS fase 3, ver AcarsIngestaoController::posicao() e README,
    "Backend: posicao em tempo real (ACARS fase 3)").

    Roda numa thread separada, de proposito: nunca pode atrasar o loop
    principal de captura (que tem seu proprio orcamento de tempo por
    tick, ver do_record()). So manda a amostra mais RECENTE que o loop
    principal ja calculou (update()) - sem fila, sem retry: se um ping
    falhar ou atrasar, o proximo (12s depois, por padrao) resolve
    sozinho. Mesma filosofia "radical na coleta, conservador no envio"
    do contrato completo, aplicada aqui a posicao em vez de telemetria
    inteira.
    """

    def __init__(self, server, token, pilot_cid, aeronave_reg, interval):
        self.url = server.rstrip("/") + "/api/acars/v1/voos/posicao"
        self.token = token
        self.pilot_cid = pilot_cid
        self.aeronave_reg = aeronave_reg
        self.interval = interval
        self._lock = threading.Lock()
        self._latest = None
        self._stop = threading.Event()
        self._last_error = None   # dedup de mensagem de erro, pra nao poluir o console a cada ping perdido
        self._thread = threading.Thread(target=self._loop, name="katabatic-pos-pinger", daemon=True)

    def start(self):
        if self.interval > 0:
            self._thread.start()

    def update(self, lat, lon, alt_ft, hdg_true, gs_kt, ias_kt, vs_fpm, on_ground):
        """Chamado pelo loop principal (1 Hz) a cada amostra do Grupo A -
        so guarda a leitura mais recente pra thread do pinger mandar."""
        with self._lock:
            self._latest = {
                "at": now_iso(), "lat": lat, "lon": lon, "alt_ft": alt_ft,
                "hdg_true": hdg_true, "gs_kt": gs_kt, "ias_kt": ias_kt,
                "vs_fpm": vs_fpm, "on_ground": on_ground,
            }

    def stop(self):
        self._stop.set()
        self._thread.join(timeout=2.0)

    def _loop(self):
        while not self._stop.wait(self.interval):
            with self._lock:
                sample = dict(self._latest) if self._latest else None
            if sample is None or sample.get("lat") is None or sample.get("lon") is None:
                continue   # ainda sem nenhuma amostra do Grupo A (bem no inicio da gravacao)
            payload = dict(sample, pilot_cid=self.pilot_cid, aeronave_reg=self.aeronave_reg)
            try:
                _post_json(self.url, self.token, payload)
                if self._last_error is not None:
                    print("\n  [posicao] servidor voltou a aceitar o heartbeat.")
                    self._last_error = None
            except urllib.error.HTTPError as exc:
                msg = "HTTP %d: %s" % (exc.code, exc.read().decode("utf-8", "ignore"))
                if msg != self._last_error:
                    print("\n  [posicao] servidor recusou o heartbeat (%s) - Mapa ao vivo fica sem posicao real ate resolver." % msg)
                    self._last_error = msg
            except urllib.error.URLError as exc:
                msg = "sem conexao (%s)" % exc.reason
                if msg != self._last_error:
                    print("\n  [posicao] nao conectou pro heartbeat (%s) - tentando de novo em %.0fs." % (msg, self.interval))
                    self._last_error = msg


def upload_capture(payload, server, token, payload_path):
    """Um unico POST ao final do voo - AcarsIngestaoController.

    Se falhar, o payload ja gravado em disco (payload_path) e a rede de
    seguranca - reenviar depois e so rodar um POST manual com esse
    arquivo (curl, Postman, o que for mais facil na hora).
    """
    url = server.rstrip("/") + "/api/acars/v1/voos"
    print("\nEnviando pro servidor: %s ..." % url)
    try:
        status, resposta = _post_json(url, token, payload)
        print("Enviado. Servidor respondeu %d: %s" % (status, resposta))
    except urllib.error.HTTPError as exc:
        detalhe = exc.read().decode("utf-8", "ignore")
        print("Servidor recusou (%d): %s" % (exc.code, detalhe))
        print("Payload continua em: %s (corrija e reenvie manualmente)." % payload_path)
    except urllib.error.URLError as exc:
        print("Nao conectou no servidor: %s" % exc.reason)
        print("Payload continua em: %s (reenvie quando o servidor estiver de pe)." % payload_path)


# --------------------------------------------------------------------------

TIPO_LABELS = {
    "carga": "Carga", "pesquisa": "Pesquisa",
    "pessoal": "Pessoal", "reposicionamento": "Reposicionamento",
    "medvec": "Medvec",
}


# --------------------------------------------------------------------------
# Modo rebuild - reconstroi upload_payload.json de uma gravacao existente
# --------------------------------------------------------------------------

# Campos que sao booleanos de verdade nos CSVs (todo o resto que nao for "t"
# vira numero quando der - ver valor_tipado()). Csv nao tem tipos: True/False
# viram o texto literal "True"/"False" na escrita (ver Recorder/rnd()), entao
# precisam de tratamento explicito na leitura - se cair no ramo numerico
# generico por engano, o valor vira string e o backend em PHP faz um cast
# (bool) errado nele (qualquer string nao-vazia diferente de "0" vira `true`).
CSV_BOOL_FIELDS = {"on_ground", "slew", "in_cloud"}


def parse_iso(texto):
    """Inverso de now_iso() - aceita o "Z" que now_iso() sempre grava."""
    if texto.endswith("Z"):
        texto = texto[:-1] + "+00:00"
    return datetime.fromisoformat(texto)


def format_iso(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z"


def valor_tipado(campo, bruto):
    """Devolve o tipo Python que o campo tinha antes de virar texto no CSV.

    "t" fica string (e um timestamp ISO, nao um numero). Campos em
    CSV_BOOL_FIELDS viram bool de verdade. O resto tenta virar numero (int
    quando o texto nao tem ponto/expoente, float caso contrario) e, se nao
    for numerico, fica como veio (defensivo - nao deveria acontecer com os
    CSVs que o proprio script grava).
    """
    if bruto is None or bruto == "":
        return None
    if campo == "t":
        return bruto
    if campo in CSV_BOOL_FIELDS:
        return bruto.strip().lower() in ("true", "1", "1.0", "yes")
    try:
        if "." in bruto or "e" in bruto.lower():
            return float(bruto)
        return int(bruto)
    except ValueError:
        return bruto


def ler_csv_tipado(caminho):
    """Le samples.csv/env.csv de volta pra lista de dicts tipados - mesmo
    formato que Recorder.samples_list/env_list tinham em memoria durante a
    gravacao original (ver upload_capture() em do_record())."""
    linhas = []
    with open(caminho, newline="", encoding="utf-8") as arquivo:
        leitor = csv.DictReader(arquivo)
        for row in leitor:
            linhas.append({campo: valor_tipado(campo, bruto) for campo, bruto in row.items()})
    return linhas


def ler_eventos(caminho):
    """Le events.csv de volta - a diferenca pra ler_csv_tipado() e a coluna
    "data", que foi gravada como JSON serializado dentro do CSV
    (Recorder.event()) e aqui volta a ser um dict de verdade, igual
    Recorder.events_list tinha em memoria."""
    linhas = []
    with open(caminho, newline="", encoding="utf-8") as arquivo:
        leitor = csv.DictReader(arquivo)
        for row in leitor:
            try:
                data = json.loads(row.get("data") or "{}")
            except ValueError:
                data = {}
            linhas.append({
                "t": row.get("t"),
                "type": row.get("type"),
                "lat": valor_tipado("lat", row.get("lat")),
                "lon": valor_tipado("lon", row.get("lon")),
                "alt_ft": valor_tipado("alt_ft", row.get("alt_ft")),
                "data": data,
            })
    return linhas


def rebuild_payload(pasta, args):
    """Reconstroi upload_payload.json de uma pasta de gravacao existente,
    sem tocar no simulador - ver docstring do modulo, item 3, pro porque
    disso existir (gravacao interrompida sem Ctrl+C)."""
    pasta = os.path.normpath(pasta)
    if not os.path.isdir(pasta):
        print("Pasta nao encontrada: %s" % pasta)
        sys.exit(1)

    caminho_samples = os.path.join(pasta, "samples.csv")
    caminho_env = os.path.join(pasta, "env.csv")
    caminho_events = os.path.join(pasta, "events.csv")
    caminho_payload = os.path.join(pasta, "upload_payload.json")

    if os.path.exists(caminho_payload) and not args.force:
        print("Ja existe upload_payload.json em %s - use --force pra sobrescrever." % pasta)
        sys.exit(1)

    if not os.path.exists(caminho_samples):
        print("Nao achei samples.csv em %s - nada pra reconstruir." % pasta)
        sys.exit(1)

    samples_list = ler_csv_tipado(caminho_samples)
    if not samples_list:
        print("samples.csv esta vazio - nada pra reconstruir (a gravacao deve ter caido antes do primeiro flush).")
        sys.exit(1)

    env_list = ler_csv_tipado(caminho_env) if os.path.exists(caminho_env) else []
    events_list = ler_eventos(caminho_events) if os.path.exists(caminho_events) else []

    # ident/callsign moram dentro do evento "session_start" (primeira linha
    # de events.csv, gravada logo no inicio de do_record()) - e o unico
    # lugar onde esses dois sobrevivem fora do session.json que nunca chegou
    # a ser escrito (ver docstring do modulo).
    todos_t = [r["t"] for r in (samples_list + env_list + events_list) if r.get("t")]

    ident = {}
    callsign_do_arquivo = None
    if events_list and events_list[0].get("type") == "session_start":
        dados_inicio = events_list[0].get("data") or {}
        ident = dados_inicio.get("ident") or {}
        callsign_do_arquivo = dados_inicio.get("callsign")
        started_dt = parse_iso(events_list[0]["t"])
    else:
        print("Aviso: sem evento 'session_start' utilizavel em events.csv - matricula/callsign nao recuperados automaticamente.")
        if todos_t:
            started_dt = min(parse_iso(t) for t in todos_t)
        else:
            casa_com_stamp = re.match(r"(\d{8}_\d{6})", os.path.basename(pasta))
            if not casa_com_stamp:
                print("Nao consegui descobrir o horario de inicio (sem timestamp utilizavel em nenhum CSV nem no nome da pasta).")
                sys.exit(1)
            started_dt = datetime.strptime(casa_com_stamp.group(1), "%Y%m%d_%H%M%S").replace(tzinfo=timezone.utc)
            print("Aviso: horario de inicio estimado pelo nome da pasta (pode estar alguns segundos adiantado do inicio real).")

    ended_dt = max([parse_iso(t) for t in todos_t] + [started_dt])
    duration_s = max(0, int((ended_dt - started_dt).total_seconds()))

    tail = (ident.get("tail_number") or "").strip()
    callsign = args.callsign or callsign_do_arquivo or ""
    if not tail:
        print("Aviso: matricula (ATC ID) nao recuperada - selecione a aeronave manualmente na hora de importar pelo site.")
    if not callsign:
        print("Aviso: callsign nao recuperado - informe com --callsign ou preencha na hora de importar.")

    codigo = os.path.basename(pasta.rstrip("/\\"))

    payload = {
        "schema": "kb-raw-1",
        "codigo": codigo,
        "pilot_cid": args.pilot_cid,
        "callsign": callsign,
        "tipo_operacao": TIPO_LABELS.get((args.tipo or "").lower(), ""),
        "origem": (args.origem or "").upper(),
        "destino": (args.destino or "").upper(),
        "aeronave_reg": tail,
        "ident": ident,
        "started_at": format_iso(started_dt),
        "ended_at": format_iso(ended_dt),
        "duration_s": duration_s,
        "samples": samples_list,
        "env": env_list,
        "events": events_list,
    }

    with open(caminho_payload, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)

    print("\nReconstruido: %s" % caminho_payload)
    print("%d amostras, %d leituras de ambiente, %d eventos, %d min de voo." % (
        len(samples_list), len(env_list), len(events_list), duration_s // 60))
    print("Importe esse arquivo em /novo-voo (modo 'Importar telemetria') pra publicar o voo.")

    if args.server:
        token = args.token or os.environ.get("KATABATIC_ACARS_TOKEN", "")
        if not token:
            print("\n--server informado mas sem token (--token ou KATABATIC_ACARS_TOKEN) - so o arquivo local foi gerado.")
        else:
            upload_capture(payload, args.server, token, caminho_payload)


def main():
    parser = argparse.ArgumentParser(description="Katabatic - captura de SimVars do MSFS 2024")
    parser.add_argument("--probe", action="store_true", help="le cada variavel uma vez e relata disponibilidade")
    parser.add_argument("--record", action="store_true", help="grava o voo em CSV")
    parser.add_argument("--callsign", default="", help="callsign do voo, ex.: KBT118")
    parser.add_argument("--dir", default="voos", help="pasta de saida (padrao: ./voos)")
    parser.add_argument("--pilot-cid", default="", help="CID VATSIM do piloto (backend precisa achar o Pilot por isso)")
    parser.add_argument("--tipo", default="", choices=[""] + sorted(TIPO_LABELS.keys()),
                         help="tipo de operacao: carga, pesquisa, pessoal, reposicionamento ou medvec (vazio = nao enviar)")
    parser.add_argument("--categoria", default="aviao", choices=["aviao", "helicoptero"],
                         help="categoria da aeronave (padrao: aviao) - so muda a deteccao de toque no "
                              "solo/decolagem: helicoptero nao usa a janela de debounce de 'quique' de "
                              "pista, ja que hover-taxi pousa/decola varias vezes em poucos segundos de "
                              "proposito, sem ser um quique de asa fixa")
    parser.add_argument("--origem", default="", help="ICAO de origem planejado, ex.: PAFA")
    parser.add_argument("--destino", default="", help="ICAO de destino planejado, ex.: PABT")
    parser.add_argument("--server", default="", help="URL base do backend Symfony, ex.: http://localhost:8080")
    parser.add_argument("--token", default="",
                         help="token do ACARS (senao, le da variavel de ambiente KATABATIC_ACARS_TOKEN)")
    parser.add_argument("--pos-interval", type=float, default=12.0,
                         help="segundos entre POSTs de posicao pro Mapa ao vivo (padrao: 12; 0 desliga o heartbeat sem desligar o fechamento)")
    parser.add_argument("--rebuild", metavar="PASTA", default="",
                         help="reconstroi upload_payload.json de uma gravacao existente (a partir de "
                              "samples.csv/env.csv/events.csv), sem precisar do simulador - ver "
                              "docstring do modulo, item 3. Aceita --callsign/--pilot-cid/--tipo/"
                              "--origem/--destino junto, todos opcionais; --server/--token tambem, "
                              "se quiser mandar pro backend na hora em vez de so gerar o arquivo")
    parser.add_argument("--force", action="store_true",
                         help="com --rebuild, sobrescreve upload_payload.json se ja existir na pasta")
    args = parser.parse_args()

    if args.rebuild:
        rebuild_payload(args.rebuild, args)
        return

    if not args.probe and not args.record:
        parser.print_help()
        return

    print("Conectando ao simulador...")
    try:
        sm = SimConnect()
    except Exception as exc:
        print("Nao conectou: %s" % exc)
        print("Confira: MSFS aberto, ja dentro do voo, e Python 64 bits.")
        return
    print("Conectado.")

    reader = VarReader(sm)
    try:
        if args.probe:
            do_probe(reader)
        else:
            do_record(reader, args)
    finally:
        try:
            sm.exit()
        except Exception:
            pass


if __name__ == "__main__":
    main()
