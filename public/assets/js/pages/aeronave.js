/* ==========================================================================
   Katabatic — aeronave.js
   Historico de aeronave: filtra as pernas (window.KATABATIC_AC_LEGS,
   injetado pelo AeronaveController) por periodo/tipo/data e desenha
   todas no mapa (traçado + origem/destino + label de cada perna),
   junto com uma lista ao lado. Coordenadas dos aeroportos vem de
   airports.json (fetch, mesmo padrao de voo.js pra telemetria).
   ========================================================================== */
(function () {
  'use strict';

  var LEGS = window.KATABATIC_AC_LEGS || [];
  var AIRPORTS = {};
  var state = { periodo: 30, tipo: 'Todos', de: '', ate: '', labels: true };

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
    return '<tr data-leg-idx="' + idx + '" tabindex="0" title="' + tr('aeronave.leg.highlight.title', 'Destacar esta perna no mapa') + '">' +
      '<td class="mono">' + ddmmyyyy(f.data) + '</td>' +
      '<td class="mono">' + callsignHtml + '<span class="sub">' + f.tipo + '</span></td>' +
      '<td class="route-cell">' + f.origem + ' → ' + f.destino + '<span class="sub">' + f.hora + '</span></td>' +
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

  function curvePoints(a, b, bend) {
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

  function drawMap(legs) {
    if (!MAP) return;
    MLAYER.clearLayers();

    var known = legs.filter(function (f) { return AIRPORTS[f.origem] && AIRPORTS[f.destino]; });
    if (!known.length) {
      document.getElementById('ac-map-count').textContent = tr('aeronave.map.noknownlegs', 'Sem pernas com aeroporto reconhecido nesse período.');
      return;
    }

    // Conta quantas vezes cada rota (sem direcao) aparece, pra "abrir em
    // leque" pernas repetidas em vez de empilhar uma linha por cima da
    // outra - cada ocorrencia pega um arco diferente.
    var routeTotal = {}, routeSeen = {};
    known.forEach(function (f) { var k = routeKey(f.origem, f.destino); routeTotal[k] = (routeTotal[k] || 0) + 1; });

    var airportVisits = {};
    var bounds = [];
    var n = known.length;

    known.forEach(function (f, i) {
      var a = AIRPORTS[f.origem], b = AIRPORTS[f.destino];
      var aLL = [a.lat, a.lon], bLL = [b.lat, b.lon];
      airportVisits[f.origem] = (airportVisits[f.origem] || 0) + 1;
      airportVisits[f.destino] = (airportVisits[f.destino] || 0) + 1;

      var k = routeKey(f.origem, f.destino);
      var seen = routeSeen[k] || 0; routeSeen[k] = seen + 1;
      var total = routeTotal[k];
      var offsetIdx = seen - (total - 1) / 2;
      var bend = total > 1 ? offsetIdx * BEND_STEP : 0;

      var curve = curvePoints(aLL, bLL, bend);
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
      var line = L.polyline(curve.pts, { color: 'var(--accent)', weight: weight, opacity: opacity })
        .addTo(MLAYER);
      line.bindPopup(legPopup(f));
      line.on('click', function () { highlightLegRow(f); });
      line.on('mouseover', function () { line.setStyle({ weight: weight + 2 }); });
      line.on('mouseout', function () { line.setStyle({ weight: weight }); });
      curve.pts.forEach(function (p) { bounds.push(p); });

      if (state.labels) {
        L.marker(curve.mid, {
          icon: L.divIcon({ className: '', html: '<span class="leg-label">' + f.callsign + '</span>', iconSize: null }),
          interactive: false
        }).addTo(MLAYER);
      }
    });

    Object.keys(airportVisits).forEach(function (icao) {
      var ap = AIRPORTS[icao];
      if (!ap) return;
      var visits = airportVisits[icao];
      L.circleMarker([ap.lat, ap.lon], {
        radius: 5 + Math.min(6, visits),
        color: '#fff', weight: 2, fillColor: '#2C7CA5', fillOpacity: .95
      }).addTo(MLAYER).bindPopup(
        '<div class="ac-popup"><b>' + icao + '</b>' + ap.name + '<span class="sub">' + ap.city + ' · ' + visits + ' ' + visitNoun(visits) + '</span></div>'
      );
      L.marker([ap.lat, ap.lon], {
        icon: L.divIcon({ className: '', html: '<span class="airport-label">' + icao + '</span>', iconSize: null, iconAnchor: [-8, 6] }),
        interactive: false
      }).addTo(MLAYER);
    });

    if (bounds.length) MAP.fitBounds(L.latLngBounds(bounds).pad(0.18));
    document.getElementById('ac-map-count').textContent = known.length + ' ' + legNoun(known.length) + ' · ' + Object.keys(airportVisits).length + ' ' + tr('aeronave.airports.noun', 'aeroportos');
  }

  function legPopup(f) {
    var html = '<div class="ac-popup"><b>' + f.callsign + ' · ' + ddmmyyyy(f.data) + '</b>' +
      f.origem + ' → ' + f.destino + '<span class="sub">' + f.hora + ' · ' + f.tempo + ' · ' + f.tipo + '</span>';
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

  document.getElementById('ac-legs-body').addEventListener('click', function (e) {
    // Clicar no nome do voo (link) abre o relatorio real - deixa a
    // navegacao nativa do <a> acontecer, sem tambem focar o mapa.
    if (e.target.closest('a.leg-callsign-link')) return;
    // `rowEl`, nao `tr` - ver o comentario em highlightLegRow() acima
    // sobre por que esse nome fica reservado pro helper de traducao.
    var rowEl = e.target.closest('tr[data-leg-idx]');
    if (!rowEl) return;
    var f = LEGS[+rowEl.dataset.legIdx];
    if (f && AIRPORTS[f.origem] && AIRPORTS[f.destino] && MAP) {
      highlightLegRow(f);
      MAP.fitBounds(L.latLngBounds([[AIRPORTS[f.origem].lat, AIRPORTS[f.origem].lon], [AIRPORTS[f.destino].lat, AIRPORTS[f.destino].lon]]).pad(0.4));
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
