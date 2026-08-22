/* ==========================================================================
   Katabatic — aeronave.js
   Historico de aeronave: filtra as pernas (window.KATABATIC_AC_LEGS,
   injetado pelo AeronaveController) por periodo/tipo/data e desenha
   todas no mapa, junto com uma lista ao lado. Cada perna com telemetria
   ACARS gravada (f.track, ver AeronaveController::trackPoints()) desenha
   o trajeto REAL voado - nao uma linha reta/curva estimada; so cai pro
   fallback de curva entre origem/destino (coordenadas de airports.json)
   quando a perna nao tem gravacao (historico so narrativo). Ver
   endpoints() pra essa decisao. Pernas mais recentes ficam com cor/
   espessura mais forte, mais antigas mais apagadas (ver drawMap()).

   f.track (injetado por LEGS) ja vem decimado a 180 pontos por perna
   (ver AeronaveController::TRACK_MAX_PONTOS) pro carregamento inicial
   nao pesar. O toggle "Mostrar todas as posicoes" (#ac-fullres) busca,
   sob demanda, o trajeto SEM decimar de toda perna com telemetria via
   GET /aeronave/{reg}/trajetos-completos (rota app_aeronave_
   trajetos_completos) e guarda em FULL_TRACKS; trackFor() decide, por
   perna, se usa esse cache (quando o toggle ta ligado e a perna tem
   entrada nele) ou o f.track decimado (padrao) - ver trackFor()/
   endpoints() e o listener de #ac-fullres mais abaixo.
   ========================================================================== */
(function () {
  'use strict';

  var LEGS = window.KATABATIC_AC_LEGS || [];
  var AIRPORTS = {};
  var FULL_TRACKS = null; // {codigo: [[lat,lon],...]} - carregado sob demanda, ver listener de #ac-fullres
  var state = { periodo: 30, tipo: 'Todos', de: '', ate: '', labels: true, showAcidentadas: true, fullRes: false };

  // Traducao de texto construido em JS (nao capturado pelo data-i18n do
  // lang-toggle.js, que so le innerHTML no load) - ver comentario no topo
  // do arquivo de template sobre a arquitetura de popups/paineis de mapa.
  function tr(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  function legNoun(n) { return tr('aeronave.leg.noun', 'perna') + (n === 1 ? '' : 's'); }
  function visitNoun(n) { return tr('aeronave.airport.visit.noun', 'pouso/decolagem') + (n === 1 ? '' : 's'); }

  function pad2(n) { return String(n).padStart(2, '0'); }
  function ddmmyyyy(iso) { return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4); }

  /* ---------- filtro ---------- */
  function filteredLegs() {
    var now = window.KATABATIC_NOW ? new Date(window.KATABATIC_NOW) : new Date();
    return LEGS.filter(function (f) {
      if (!state.showAcidentadas && f.acidentado) return false;
      if (state.tipo !== 'Todos' && f.tipo !== state.tipo) return false;
      if (state.de || state.ate) {
        if (state.de && f.data < state.de) return false;
        if (state.ate && f.data > state.ate) return false;
      } else if (state.periodo > 0) {
        var days = (now - new Date(f.data + 'T00:00:00Z')) / 86400000;
        if (days > state.periodo) return false;
      }
      return true;
    });
  }

  /* ---------- resumo ---------- */
  function renderSummary(legs) {
    var airports = {};
    var totalMin = 0;
    legs.forEach(function (f) {
      airports[f.origem] = true;
      airports[f.destino] = true;
      totalMin += f.tempoMin || 0;
    });
    document.getElementById('sum-legs').textContent = legs.length;
    document.getElementById('sum-airports').textContent = Object.keys(airports).length;
    document.getElementById('sum-hours').textContent = (totalMin / 60).toFixed(1).replace('.', ',');
    var oldest = legs.length ? legs[legs.length - 1].data : null;
    document.getElementById('sum-span').textContent = oldest ? ddmmyyyy(oldest) : '—';
  }

  /* ---------- lista de pernas ---------- */
  function legRow(f, idx) {
    // A linha inteira so foca a rota no mapa (ver listener em #ac-legs-body)
    // - abrir o relatorio de voo de verdade e uma acao a parte, so no nome
    // do voo (callsign), que vira um link proprio dentro da celula.
    var callsignHtml = f.flightId
      ? '<a class="leg-callsign-link" href="/voo?id=' + encodeURIComponent(f.flightId) + '" title="' + tr('aeronave.leg.report.title', 'Abrir relatório de voo real') + '">' + f.callsign + '</a>'
      : f.callsign;
    // f.acidentado vem de Voo::isAcidentado() (ver AeronaveController::
    // legViewModel()) - so pra pernas com telemetria (flightId), ja que
    // so essas passam por VooController::marcarAcidentado(). A linha
    // ganha uma classe (opacidade/tom, ver aeronave.css) e um selo
    // pequeno ao lado do callsign, alem do filtro em filteredLegs().
    var crashTag = f.acidentado ? ' <span class="tag tag-bad leg-crash-tag">' + tr('aeronave.leg.crashed', 'Acidentado') + '</span>' : '';
    // f.destinoReal vem de Voo::$destinoReal (ver AeronaveController::
    // legViewModel()) - null na grande maioria das pernas (pousou onde
    // o plano dizia); preenchido so quando AcarsIngestaoController::
    // ingerir() detectou pouso alternativo. Rota continua mostrando o
    // destino DECLARADO (f.destino) - o pouso real vira uma nota
    // separada, nao substitui a rota planejada.
    var divTag = f.destinoReal ? ' <span class="tag tag-warn leg-diversion-tag">' + tr('aeronave.leg.diversion.badge', 'Pouso alt.') + '</span>' : '';
    var divNote = f.destinoReal ? '<span class="sub route-diversion">' + tr('aeronave.leg.diversion', 'pousou em') + ' ' + f.destinoReal + '</span>' : '';
    return '<tr data-leg-idx="' + idx + '" tabindex="0"' + (f.acidentado ? ' class="leg-crashed"' : '') + ' title="' + tr('aeronave.leg.highlight.title', 'Destacar esta perna no mapa') + '">' +
      '<td class="mono">' + ddmmyyyy(f.data) + '</td>' +
      '<td class="mono">' + callsignHtml + crashTag + divTag + '<span class="sub">' + f.tipo + '</span></td>' +
      '<td class="route-cell">' + f.origem + ' → ' + f.destino + '<span class="sub">' + f.hora + '</span>' + divNote + '</td>' +
      '<td class="num mono">' + f.tempo + '</td>' +
      '</tr>';
  }

  function renderLegsList(legs) {
    var body = document.getElementById('ac-legs-body');
    body.innerHTML = legs.map(legRow).join('');
    document.getElementById('ac-legs-empty').style.display = legs.length ? 'none' : 'block';
    document.getElementById('ac-legs-count').textContent = legs.length + ' ' + legNoun(legs.length);
  }

  /* ---------- mapa ---------- */
  var MAP = null, MLAYER = null;
  var BEND_STEP = 0.14; // graus de "arco" entre pernas repetidas na mesma rota
  var LOOP_R = 0.05; // graus - raio do laco desenhado quando origem === destino (ver curvePoints)

  function curvePoints(a, b, bend) {
    // Voo que saiu e voltou pro mesmo aeroporto (circuito/pouso-e-decolagem
    // de teste, comum no primeiro voo de uma aeronave nova) - a curva
    // bezier abaixo degenera num UNICO ponto quando origem e destino tem a
    // mesma coordenada (dLat/dLon = 0, entao o vetor perpendicular tambem
    // zera e ctrl cai em cima de a/b): a "rota" virava uma linha de
    // comprimento zero, invisivel no mapa - so o marcador do aeroporto
    // aparecia, o que parecia (e era) um bug. Em vez de uma linha entre
    // dois pontos iguais, desenha um laco de raio fixo ao redor do
    // aeroporto; `bend` (mesmo parametro que abre repeticoes da mesma rota
    // em leque, ver drawMap) vira o angulo inicial do laco, pra varios
    // circuitos na mesma base nao ficarem exatamente um em cima do outro.
    if (a[0] === b[0] && a[1] === b[1]) {
      var latCorr = Math.cos(a[0] * Math.PI / 180) || 1; // graus de longitude "encolhem" perto dos polos
      var startDeg = bend * 300;
      var pts = [], n = 48;
      for (var i = 0; i <= n; i++) {
        var ang = (startDeg + (i / n) * 360) * Math.PI / 180;
        pts.push([a[0] + LOOP_R * Math.sin(ang), a[1] + (LOOP_R / latCorr) * Math.cos(ang)]);
      }
      return { pts: pts, mid: pts[Math.round(n * 0.375)] };
    }

    var midLat = (a[0] + b[0]) / 2, midLon = (a[1] + b[1]) / 2;
    var dLat = b[0] - a[0], dLon = b[1] - a[1];
    var len = Math.sqrt(dLat * dLat + dLon * dLon) || 1;
    var perpLat = -dLon / len, perpLon = dLat / len;
    var ctrl = [midLat + perpLat * bend, midLon + perpLon * bend];
    var pts = [], n = 24;
    for (var i = 0; i <= n; i++) {
      var t = i / n;
      var lat = (1 - t) * (1 - t) * a[0] + 2 * (1 - t) * t * ctrl[0] + t * t * b[0];
      var lon = (1 - t) * (1 - t) * a[1] + 2 * (1 - t) * t * ctrl[1] + t * t * b[1];
      pts.push([lat, lon]);
    }
    return { pts: pts, mid: pts[Math.round(n / 2)] };
  }

  function routeKey(a, b) { return [a, b].sort().join('|'); }

  // Trajeto a usar pra uma perna: o completo (FULL_TRACKS[f.flightId]) so
  // quando o toggle "Mostrar todas as posicoes" ta ligado E esse voo ja
  // foi carregado nesse cache - senao cai no f.track decimado (padrao,
  // sempre disponivel de cara). Um voo sem telemetria nao tem entrada em
  // nenhum dos dois - endpoints() cai pro fallback de airports.json.
  function trackFor(f) {
    if (state.fullRes && FULL_TRACKS && f.flightId && FULL_TRACKS[f.flightId]) {
      return FULL_TRACKS[f.flightId];
    }
    return f.track || null;
  }

  // Pontos [lat,lon] de inicio/fim de uma perna, pra desenhar o traçado e
  // os marcadores de aeroporto. Prioriza o trajeto real gravado pelo ACARS
  // (trackFor(f), ver AeronaveController::trackPoints()) - so cai pro par
  // de aeroportos fixos do airports.json quando a perna nao tem telemetria
  // gravada (historico so narrativo, ver Voo::hasTelemetria()). `real:
  // true` faz drawMap() desenhar `track` direto em vez de uma curva
  // estimada - e funciona mesmo pra ICAOs que nao estao no airports.json
  // curado, ja que as coordenadas vem da gravacao, nao do catalogo fixo.
  function endpoints(f) {
    var track = trackFor(f);
    if (track && track.length >= 2) {
      return { start: track[0], end: track[track.length - 1], real: true, track: track };
    }
    if (AIRPORTS[f.origem] && AIRPORTS[f.destino]) {
      return {
        start: [AIRPORTS[f.origem].lat, AIRPORTS[f.origem].lon],
        end: [AIRPORTS[f.destino].lat, AIRPORTS[f.destino].lon],
        real: false
      };
    }
    return null;
  }

  // Marcador de aeroporto usa a primeira coordenada vista pra aquele ICAO
  // (de um trajeto real ou do airports.json) - pousos reais repetidos no
  // mesmo aeroporto variam um pouco de posicao exata no patio, a diferenca
  // e pequena demais pra importar visualmente. So soma as visitas.
  function registerAirportPoint(map, icao, pt) {
    if (!map[icao]) map[icao] = { lat: pt[0], lon: pt[1], visits: 0 };
    map[icao].visits++;
  }

  function drawMap(legs) {
    if (!MAP) return;
    MLAYER.clearLayers();

    var known = [];
    legs.forEach(function (f) {
      var ep = endpoints(f);
      if (ep) known.push({ f: f, ep: ep });
    });
    if (!known.length) {
      document.getElementById('ac-map-count').textContent = tr('aeronave.map.noknownlegs', 'Sem pernas com aeroporto reconhecido nesse período.');
      return;
    }

    // Conta quantas vezes cada rota (sem direcao) aparece, pra "abrir em
    // leque" pernas repetidas em vez de empilhar uma linha por cima da
    // outra - cada ocorrencia pega um arco diferente. So vale pro
    // fallback estimado (curvePoints) - um trajeto real ja tem forma
    // propria, nao precisa de leque.
    var routeTotal = {}, routeSeen = {};
    known.forEach(function (x) { var k = routeKey(x.f.origem, x.f.destino); routeTotal[k] = (routeTotal[k] || 0) + 1; });

    var airportPoints = {};
    var bounds = [];
    var n = known.length;

    known.forEach(function (x, i) {
      var f = x.f, ep = x.ep;
      registerAirportPoint(airportPoints, f.origem, ep.start);
      // f.destinoReal (pouso alternativo, ver legRow()) e a identidade
      // de verdade do ponto onde o trajeto termina - registrar o
      // marcador sob f.destino nesse caso rotularia o pino com o ICAO
      // errado (o aeroporto declarado, nao o que esta de fato naquela
      // coordenada).
      registerAirportPoint(airportPoints, f.destinoReal || f.destino, ep.end);

      var k = routeKey(f.origem, f.destino);
      var seen = routeSeen[k] || 0; routeSeen[k] = seen + 1;
      var total = routeTotal[k];
      var offsetIdx = seen - (total - 1) / 2;
      var bend = total > 1 ? offsetIdx * BEND_STEP : 0;

      var pts, mid;
      if (ep.real) {
        // Trajeto gravado de verdade - desenha exatamente essas
        // coordenadas (decimadas ou completas conforme o toggle
        // "Mostrar todas as posicoes", ver trackFor()), sem curva estimada.
        pts = ep.track;
        mid = pts[Math.floor(pts.length / 2)];
      } else {
        var curve = curvePoints(ep.start, ep.end, bend);
        pts = curve.pts;
        mid = curve.mid;
      }

      // `known` preserva a ordem de LEGS (mais recente primeiro - ver
      // AeronaveController::aircraftLegs, offsets crescentes), entao o
      // indice `i` ja e a posicao cronologica dentro do periodo filtrado:
      // i=0 e sempre a perna mais recente exibida, i=n-1 a mais antiga.
      // recencia (1 = mais recente, 0 = mais antiga) controla opacidade
      // E espessura, pra o traçado mais recente ficar visivelmente mais
      // forte/grosso que os antigos, nao so um pouco mais opaco.
      var recencia = n > 1 ? 1 - i / (n - 1) : 1;
      var opacity = 0.28 + 0.72 * recencia;
      var weight = 2 + 2.5 * recencia;
      var line = L.polyline(pts, { color: 'var(--accent)', weight: weight, opacity: opacity })
        .addTo(MLAYER);
      line.bindPopup(legPopup(f, ep.real));
      line.on('click', function () { highlightLegRow(f); });
      line.on('mouseover', function () { line.setStyle({ weight: weight + 2 }); });
      line.on('mouseout', function () { line.setStyle({ weight: weight }); });
      pts.forEach(function (p) { bounds.push(p); });

      if (state.labels) {
        L.marker(mid, {
          icon: L.divIcon({ className: '', html: '<span class="leg-label">' + f.callsign + '</span>', iconSize: null }),
          interactive: false
        }).addTo(MLAYER);
      }
    });

    Object.keys(airportPoints).forEach(function (icao) {
      var pt = airportPoints[icao];
      var known2 = AIRPORTS[icao]; // so pra nome/cidade no popup, quando o ICAO estiver no catalogo (AeroportoController::catalogo())
      // postoAvancadoDe vem do catalogo (App\Entity\Aeroporto, ver
      // AeroportoRepository::findAllAsCatalogArray()) - so rotulo, nota
      // extra no popup quando o admin marcou esse ICAO como posto
      // avancado de uma das duas bases.
      var postoNote = known2 && known2.postoAvancadoDe
        ? '<span class="sub">' + tr('aeronave.airport.posto', 'Posto avançado de') + ' ' + known2.postoAvancadoDe + '</span>'
        : '';
      // icaoOficial idem, ver aeroporto.js - so aparece se esse ICAO
      // (quase sempre posto avancado, unico jeito de um codigo sem ICAO
      // oficial acabar no catalogo pequeno que este mapa consome) veio
      // do import global sem ICAO real (pistas de bush flying nas
      // regioes de missao).
      var localNote = known2 && known2.icaoOficial === false
        ? '<span class="sub">' + tr('aeronave.airport.local', 'Código local — não é ICAO oficial') + '</span>'
        : '';
      L.circleMarker([pt.lat, pt.lon], {
        radius: 5 + Math.min(6, pt.visits),
        color: '#fff', weight: 2, fillColor: '#2C7CA5', fillOpacity: .95
      }).addTo(MLAYER).bindPopup(
        '<div class="ac-popup"><b>' + icao + '</b>' + (known2 ? known2.name : '') +
        '<span class="sub">' + (known2 ? known2.city + ' · ' : '') + pt.visits + ' ' + visitNoun(pt.visits) + '</span>' + postoNote + localNote + '</div>'
      );
      L.marker([pt.lat, pt.lon], {
        icon: L.divIcon({ className: '', html: '<span class="airport-label">' + icao + '</span>', iconSize: null, iconAnchor: [-8, 6] }),
        interactive: false
      }).addTo(MLAYER);
    });

    if (bounds.length) MAP.fitBounds(L.latLngBounds(bounds).pad(0.18));
    document.getElementById('ac-map-count').textContent = known.length + ' ' + legNoun(known.length) + ' · ' + Object.keys(airportPoints).length + ' ' + tr('aeronave.airports.noun', 'aeroportos');
  }

  function legPopup(f, real) {
    // Perna de circuito (origem === destino, ver curvePoints()) fica
    // estranho como "PAFA → PAFA" - mostra o nome do aeroporto uma vez so.
    var rota = f.origem === f.destino ? tr('aeronave.leg.circuit', 'Circuito em') + ' ' + f.origem : f.origem + ' → ' + f.destino;
    // Deixa claro quando o traçado no mapa e uma estimativa (sem
    // telemetria gravada) e nao o trajeto real voado.
    var nota = false === real ? ' · ' + tr('aeronave.leg.estimated', 'traçado estimado') : '';
    // f.destinoReal (pouso alternativo, ver legRow()) - a rota acima
    // continua mostrando o destino DECLARADO, essa nota deixa claro que
    // o pouso de verdade foi em outro lugar.
    var divNota = f.destinoReal ? '<br>' + tr('aeronave.leg.diversion', 'pousou em') + ' ' + f.destinoReal : '';
    var html = '<div class="ac-popup"><b>' + f.callsign + ' · ' + ddmmyyyy(f.data) + '</b>' +
      rota + '<span class="sub">' + f.hora + ' · ' + f.tempo + ' · ' + f.tipo + nota + divNota + '</span>';
    if (f.flightId) html += '<br><a href="/voo?id=' + encodeURIComponent(f.flightId) + '">' + tr('aeronave.leg.viewreport', 'Ver relatório real ↗') + '</a>';
    html += '</div>';
    return html;
  }

  function highlightLegRow(f) {
    // Parametro chamado `rowEl`, nao `tr` - apesar de "tr" ser o nome
    // natural pra uma <tr> aqui, colidiria com o helper de traducao
    // (function tr(), topo do arquivo) se algum dia este callback
    // precisar chamar tr('chave', 'fallback') - ver o bug identico ja
    // corrigido em voo.js (TypeError: tr is not a function).
    document.querySelectorAll('#ac-legs-body tr.hl').forEach(function (rowEl) { rowEl.classList.remove('hl'); });
    var idx = LEGS.indexOf(f);
    var row = document.querySelector('#ac-legs-body tr[data-leg-idx="' + idx + '"]');
    if (row) { row.classList.add('hl'); row.scrollIntoView({ block: 'nearest' }); }
  }

  function initMap() {
    if (!window.L) { document.getElementById('ac-map').innerHTML = '<p style="color:#5F7885;text-align:center;padding-top:140px;font-family:var(--fm);font-size:12px">' + tr('aeronave.map.unavailable', 'Mapa indisponível') + '</p>'; return; }
    var TL = { light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' };
    var th = function () { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; };
    MAP = L.map('ac-map', { scrollWheelZoom: true, minZoom: 2, maxZoom: 13 });
    var base = L.tileLayer(TL[th()], { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>', maxZoom: 13, detectRetina: true }).addTo(MAP);
    new MutationObserver(function () { base.setUrl(TL[th()]); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    MLAYER = L.layerGroup().addTo(MAP);
    render();
    setTimeout(function () { MAP.invalidateSize(); }, 0);
  }

  /* ---------- render geral ---------- */
  function render() {
    var legs = filteredLegs();
    renderSummary(legs);
    renderLegsList(legs);
    drawMap(legs);
  }

  /* ---------- eventos ---------- */
  document.querySelectorAll('#tipo-chips .chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      document.querySelectorAll('#tipo-chips .chip').forEach(function (c) { c.classList.remove('on'); });
      chip.classList.add('on');
      state.tipo = chip.dataset.tipo;
      render();
    });
  });
  var periodoEl = document.getElementById('periodo');
  periodoEl.addEventListener('change', function (e) { state.periodo = +e.target.value; render(); });
  var deEl = document.getElementById('ac-de'), ateEl = document.getElementById('ac-ate');
  deEl.addEventListener('change', function (e) { state.de = e.target.value; render(); });
  ateEl.addEventListener('change', function (e) { state.ate = e.target.value; render(); });
  document.getElementById('ac-date-clear').addEventListener('click', function () {
    state.de = ''; state.ate = ''; deEl.value = ''; ateEl.value = '';
    render();
  });
  document.getElementById('ac-labels').addEventListener('change', function (e) {
    state.labels = e.target.checked;
    drawMap(filteredLegs());
  });
  document.getElementById('ac-show-acidentadas').addEventListener('change', function (e) {
    state.showAcidentadas = e.target.checked;
    render();
  });

  // Toggle "Mostrar todas as posicoes" - FULL_TRACKS so e buscado na
  // primeira vez que o piloto liga o toggle (fica em cache depois,
  // reusado em desligar/religar sem refetch). Desligar nunca precisa de
  // rede - so volta pro f.track ja decimado que ja ta em mao.
  var fullResEl = document.getElementById('ac-fullres');
  var fullResStatusEl = document.getElementById('ac-fullres-status');
  var trajetosCompletosUrl = window.KATABATIC_TRAJETOS_COMPLETOS_URL;
  fullResEl.addEventListener('change', function (e) {
    var checked = e.target.checked;
    if (!checked || FULL_TRACKS) {
      // Desligando, ou religando com o cache ja carregado - nao precisa
      // de rede, so troca o estado e redesenha.
      state.fullRes = checked;
      if (fullResStatusEl) fullResStatusEl.textContent = '';
      render();
      return;
    }
    fullResEl.disabled = true;
    if (fullResStatusEl) fullResStatusEl.textContent = tr('aeronave.fullres.loading', 'Carregando…');
    fetch(trajetosCompletosUrl)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        FULL_TRACKS = data;
        state.fullRes = true;
        fullResEl.disabled = false;
        if (fullResStatusEl) fullResStatusEl.textContent = '';
        render();
      })
      .catch(function (err) {
        console.error('Katabatic: falha ao carregar trajetos completos.', err);
        fullResEl.checked = false;
        fullResEl.disabled = false;
        if (fullResStatusEl) fullResStatusEl.textContent = tr('aeronave.fullres.error', 'Falha ao carregar.');
      });
  });

  document.getElementById('ac-legs-body').addEventListener('click', function (e) {
    // Clicar no nome do voo (link) abre o relatorio real - deixa a
    // navegacao nativa do <a> acontecer, sem tambem focar o mapa.
    if (e.target.closest('a.leg-callsign-link')) return;
    // `rowEl`, nao `tr` - ver o comentario em highlightLegRow() acima
    // sobre por que esse nome fica reservado pro helper de traducao.
    var rowEl = e.target.closest('tr[data-leg-idx]');
    if (!rowEl) return;
    var f = LEGS[+rowEl.dataset.legIdx];
    var ep = f && endpoints(f);
    if (ep && MAP) {
      highlightLegRow(f);
      var box;
      if (ep.real) {
        // Trajeto real - o bounds e o traçado gravado inteiro (decimado ou
        // completo, ver trackFor()), nao so os dois extremos (senao um
        // circuito real ficaria cortado).
        box = ep.track;
      } else if (f.origem === f.destino) {
        // Perna de circuito estimada (origem === destino) da um bounds
        // de tamanho zero se usar so o ponto - .pad(0.4) de um
        // retangulo zerado continua zerado, entao o fitBounds
        // centralizaria no maxZoom em vez de mostrar o laco inteiro
        // (ver curvePoints()). Usa o mesmo raio do laco pra abrir um
        // retangulo de verdade nesse caso.
        box = [[ep.start[0] - LOOP_R, ep.start[1] - LOOP_R], [ep.start[0] + LOOP_R, ep.start[1] + LOOP_R]];
      } else {
        box = [ep.start, ep.end];
      }
      MAP.fitBounds(L.latLngBounds(box).pad(0.4));
    }
  });
  document.getElementById('ac-legs-body').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    if (e.target.closest('a.leg-callsign-link')) return; // Enter no link ja navega nativamente
    var rowEl = e.target.closest('tr[data-leg-idx]');
    if (!rowEl) return;
    e.preventDefault();
    rowEl.click();
  });

  var airportsUrl = window.KATABATIC_AIRPORTS_URL || '/assets/data/airports.json';
  fetch(airportsUrl)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      AIRPORTS = data;
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMap); else initMap();
    })
    .catch(function (err) {
      console.error('Katabatic: falha ao carregar coordenadas dos aeroportos.', err);
      render();
    });

  // Popups do mapa, lista de pernas e legendas de contagem sao todos
  // reconstruidos por render() - reusa-la aqui garante que o idioma novo
  // seja aplicado em tudo isso de uma vez, sem duplicar logica.
  document.addEventListener('katabatic:langchange', function () {
    render();
  });
})();
