/* ==========================================================================
   Katabatic — voo.js
   Relatorio de voo. Le a telemetria de window.KATABATIC_FLIGHTS_URL (JSON
   estatico por enquanto — vira endpoint por voo quando o backend existir)
   e desenha cabecalho, fases, mapa, graficos sincronizados, pouso,
   composicao do indice e eventos. Porte quase literal do JS do mockup
   original (katabatic-voo.html), so trocando o array `FLIGHTS` embutido
   por um fetch().
   ========================================================================== */
(function () {
  'use strict';

  function mmss(s) { return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
  function turbColor(v) { return v < 1.5 ? '#5AA9D6' : (v < 4 ? '#FF8A1F' : '#E4574A'); }
  function fmt(v, d) { return v === null || v === undefined ? '—' : (d ? v.toFixed(d) : Math.round(v)); }
  var PHC = { solo: '#7C8F98', subida: '#2F7D50', cruzeiro: '#2C7CA5', descida: '#B85400' };
  var SEVC = { ok: 'var(--ok)', info: 'var(--ice)', warn: 'var(--accent)', bad: 'var(--danger)', '': 'var(--muted)' };

  var FLIGHTS = [];
  var F = null;

  function renderHead() {
    document.getElementById('h-meta').textContent =
      'Yakutsk · ' + F.start.slice(8, 10) + '/' + F.start.slice(5, 7) + '/' + F.start.slice(0, 4) + ' ' + F.start.slice(11, 16) + 'Z · ' + F.wx;
    document.getElementById('h-call').textContent = F.id ? F.id.split('_').pop() : '—';
    var s = document.getElementById('score');
    s.textContent = F.score;
    var c = F.score >= 75 ? 'var(--danger)' : (F.score >= 50 ? 'var(--accent)' : 'var(--ice)');
    s.style.color = c;
    document.getElementById('score-lbl').textContent = F.score >= 75 ? 'severo' : (F.score >= 50 ? 'exigente' : 'rotina');

    var k = [['Duração', mmss(F.dur), ''], ['Tempo em voo', mmss(F.air_s), ''], ['Distância', F.dist.toFixed(1), ' nm'],
      ['GS média', F.gs_avg, ' kt'], ['Altitude máx', F.alt_max.toLocaleString('pt-BR'), ' ft'], ['IAS máx', F.ias_max, ' kt'],
      ['VS máx', '+' + F.vs_max, ' fpm'], ['VS mín', F.vs_min, ' fpm'], ['Pico de G', F.gmax.toFixed(2), ''],
      ['G mínimo', F.gmin.toFixed(2), ''], ['Vento médio', (F.windc >= 0 ? '+' : '') + F.windc, ' kt'], ['Combustível', F.fuel.toFixed(1), ' lb'],
      ['Tempo em nuvem', mmss(F.cloud_s), ''], ['Temp mín', F.oat_min.toFixed(1), ' °C'], ['Amplitude', (F.oat_max - F.oat_min).toFixed(1), ' °C'],
      ['Chuva máx', F.precip_max.toFixed(1), ' mm'], ['Gelo', F.ice.toFixed(2), ' %'], ['Excedências', F.exceed, '']];
    document.getElementById('kpis').innerHTML = k.map(function (x) {
      return '<div><b class="mono">' + x[1] + '<small>' + x[2] + '</small></b><span>' + x[0] + '</span></div>';
    }).join('');
  }

  function renderPhases() {
    var tot = F.dur || 1;
    document.getElementById('phases').innerHTML = F.phases.map(function (p) {
      var w = ((p[2] - p[1] + 1) / tot) * 100;
      return '<div style="width:' + w.toFixed(2) + '%;background:' + PHC[p[0]] + '" title="' + p[0] + ' ' + mmss(p[1]) + '–' + mmss(p[2]) + '">' +
        (w > 9 ? p[0] : '') + '</div>';
    }).join('');
    document.getElementById('ph-note').textContent = F.phases.length + ' segmentos · solo ' + mmss(F.ground_s) + ' · ar ' + mmss(F.air_s);
  }

  function renderParcels() {
    document.getElementById('parcels').innerHTML = F.parcels.map(function (p) {
      var c = p[1] >= 70 ? 'var(--danger)' : (p[1] >= 40 ? 'var(--accent)' : 'var(--ice)');
      return '<div class="parcel"><span class="nm">' + p[0] + '</span><span class="tr"><i style="width:' + p[1] + '%;background:' + c + '"></i></span>' +
        '<span class="vl mono" style="color:' + c + '">' + p[1] + '</span></div><div class="phint">' + p[2] + '</div>';
    }).join('');
  }

  function renderEvents() {
    document.getElementById('ev-count').textContent = F.events.length + ' registros';
    document.getElementById('events').innerHTML = F.events.map(function (e) {
      return '<div class="ev"><span class="dot" style="background:' + (SEVC[e[4]] || SEVC['']) + '"></span>' +
        '<span class="tm">' + mmss(e[0]) + '</span><span class="tx"><b>' + e[2] + '</b>' + (e[3] ? '<span>' + e[3] + '</span>' : '') + '</span></div>';
    }).join('');
  }

  function renderLanding() {
    var el = document.getElementById('land'), note = document.getElementById('land-note');
    if (!F.td) {
      note.textContent = '—';
      el.innerHTML = '<p class="obs">Nenhum toque registrado: a sessão foi encerrada com a aeronave em voo.</p>';
      return;
    }
    var vs = F.td.vs;
    var q = vs < 150 ? ['Suave', 'var(--ok)'] : (vs < 300 ? ['Normal', 'var(--ok)'] : (vs < 450 ? ['Firme', 'var(--accent)'] : ['Duro', 'var(--danger)']));
    note.textContent = q[0];
    el.innerHTML = '<div class="verdict" style="border-left-color:' + q[1] + '">Toque classificado como <b style="color:' + q[1] + '">' + q[0].toLowerCase() + '</b>' +
      (F.bounces ? ' · <b>' + F.bounces + ' quique' + (F.bounces > 1 ? 's' : '') + '</b> após o contato' : '') + '.</div>' +
      '<div class="land">' +
      '<div><span>Razão de toque</span><b style="color:' + q[1] + '">' + vs.toFixed(0) + ' fpm</b></div>' +
      '<div><span>Atitude</span><b>' + F.td.pitch.toFixed(1) + '°</b></div>' +
      '<div><span>Inclinação</span><b>' + F.td.bank.toFixed(1) + '°</b></div>' +
      '<div><span>Proa</span><b>' + F.td.hdg + '°</b></div>' +
      '</div>';
  }

  function renderObs() {
    var t = '';
    if (F.wx === 'Neve') t = 'Perna em condições árticas: <b>' + F.oat_min.toFixed(1) + ' °C</b> no nível mais alto, neve contínua e voo em nuvem em <b>' + F.imc + '% do tempo</b>.';
    else if (F.wx === 'Chuva') t = 'Perna sob precipitação, com <b>' + F.precip_max.toFixed(1) + ' mm</b> no pico e voo em nuvem em <b>' + F.imc + '% do tempo</b>.';
    else t = 'Perna em tempo bom, sem precipitação e sem entrada em nuvem. Referência de linha de base para o índice.';
    var extra = F.exceed ? '<p class="obs" style="margin-top:12px">Registradas <b>' + F.exceed + '</b> excedência(s) durante a perna — provocadas para validar a detecção automática.</p>' : '';
    var land = F.td && F.td.vs >= 450 ? '<p class="obs" style="margin-top:12px">Toque a <b>' + F.td.vs.toFixed(0) + ' fpm</b> classificado como pouso duro.</p>' : '';
    document.getElementById('obs').innerHTML = '<p class="obs">' + t + '</p>' + extra + land;

    exitEditMode();
    renderReportView();
  }

  function renderReportView() {
    var txt = F.pilot_report || '';
    var view = document.getElementById('report-view');
    var p = document.getElementById('report-view-text');
    if (txt) { view.classList.remove('empty'); p.textContent = txt; }
    else { view.classList.add('empty'); p.textContent = 'Nenhum relato registrado para este voo.'; }
  }

  function exitEditMode() {
    document.getElementById('report-view').style.display = '';
    document.getElementById('report-edit-wrap').style.display = 'none';
  }

  function updateReportCount() {
    var n = document.getElementById('pilot-obs').value.length;
    document.getElementById('report-count').textContent = n + ' caractere' + (n === 1 ? '' : 's');
  }

  document.getElementById('pilot-obs').addEventListener('input', updateReportCount);
  document.getElementById('report-edit').addEventListener('click', function () {
    document.getElementById('pilot-obs').value = F.pilot_report || '';
    document.getElementById('report-view').style.display = 'none';
    document.getElementById('report-edit-wrap').style.display = '';
    updateReportCount();
    document.getElementById('pilot-obs').focus();
  });
  document.getElementById('report-cancel').addEventListener('click', function () {
    exitEditMode();
  });
  document.getElementById('report-save').addEventListener('click', function () {
    F.pilot_report = document.getElementById('pilot-obs').value.trim();
    exitEditMode();
    renderReportView();
  });

  /* ---------- debrief ---------- */
  function renderCharts() {
    var W = 900, PL = 46, PR = 12, dur = F.dur;
    var x = function (s) { return PL + (s / dur) * (W - PL - PR); };
    function series(src, idx) { return src.map(function (r) { return [r[0], r[idx]]; }); }
    var CH = [
      { label: 'Altitude', unit: 'ft', h: 96, fill: true, lines: [{ data: series(F.prof, 1), color: 'var(--text)', w: 1.8 }] },
      { label: 'Velocidade', unit: 'kt', h: 82, lines: [{ data: series(F.prof, 2), color: 'var(--accent)', w: 1.6, name: 'IAS' }, { data: series(F.prof, 6), color: 'var(--ice)', w: 1.4, name: 'GS' }] },
      { label: 'Razão vertical', unit: 'fpm', h: 82, zero: true, lines: [{ data: series(F.prof, 3), color: 'var(--ice)', w: 1.5 }] },
      { label: 'Fator de carga', unit: 'G', h: 76, ref: 1, lines: [{ data: series(F.prof, 4), color: 'var(--danger)', w: 1.5 }] },
      { label: 'Turbulência', unit: 'rms', h: 76, lines: [{ data: series(F.prof, 5), color: 'var(--accent)', w: 1.4 }] },
      { label: 'Temperatura', unit: '°C', h: 82, lines: [{ data: series(F.env, 1), color: 'var(--text)', w: 1.8 }] },
      { label: 'Vento', unit: 'kt', h: 76, lines: [{ data: series(F.env, 5), color: 'var(--ice)', w: 1.6 }] }
    ];
    function build(c) {
      var all = []; c.lines.forEach(function (l) { l.data.forEach(function (p) { if (p[1] !== null) all.push(p[1]); }); });
      var lo = Math.min.apply(null, all), hi = Math.max.apply(null, all);
      if (c.zero) { var m = Math.max(Math.abs(lo), Math.abs(hi)) || 1; lo = -m; hi = m; }
      if (hi === lo) hi = lo + 1;
      var pad = (hi - lo) * 0.12; lo -= pad; hi += pad;
      var PT = 8, PB = 12, H = c.h;
      var y = function (v) { return PT + (1 - (v - lo) / (hi - lo)) * (H - PT - PB); };
      var g = '';
      F.env.forEach(function (e, i) {
        if (e[3]) {
          var x1 = x(e[0]), x2 = x(i + 1 < F.env.length ? F.env[i + 1][0] : dur);
          g += '<rect x="' + x1.toFixed(1) + '" y="' + PT + '" width="' + Math.max(1, x2 - x1).toFixed(1) + '" height="' + (H - PT - PB) + '" fill="var(--ice)" opacity=".10"/>';
        }
      });
      [hi - pad, lo + pad].forEach(function (v) {
        g += '<line x1="' + PL + '" y1="' + y(v).toFixed(1) + '" x2="' + (W - PR) + '" y2="' + y(v).toFixed(1) + '" stroke="var(--border)" stroke-width="1"/>' +
          '<text x="' + (PL - 7) + '" y="' + (y(v) + 3.5).toFixed(1) + '" text-anchor="end" font-family="var(--fm)" font-size="9" fill="var(--muted)">' +
          (Math.abs(v) >= 1000 ? Math.round(v / 100) * 100 : Math.round(v * 10) / 10) + '</text>';
      });
      if (c.ref !== undefined) g += '<line x1="' + PL + '" y1="' + y(c.ref).toFixed(1) + '" x2="' + (W - PR) + '" y2="' + y(c.ref).toFixed(1) + '" stroke="var(--muted)" stroke-width="1" stroke-dasharray="2 4"/>';
      if (c.zero) g += '<line x1="' + PL + '" y1="' + y(0).toFixed(1) + '" x2="' + (W - PR) + '" y2="' + y(0).toFixed(1) + '" stroke="var(--border-strong)" stroke-width="1"/>';
      F.events.forEach(function (e) {
        if (e[1].indexOf('session') === 0) return;
        g += '<line x1="' + x(e[0]).toFixed(1) + '" y1="' + PT + '" x2="' + x(e[0]).toFixed(1) + '" y2="' + (H - PB) + '" stroke="' + (SEVC[e[4]] || 'var(--muted)') + '" stroke-width="1" stroke-dasharray="3 3" opacity=".45"/>';
      });
      c.lines.forEach(function (l) {
        var pts = l.data.filter(function (p) { return p[1] !== null; }).map(function (p) { return x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1); }).join(' ');
        if (c.fill && l === c.lines[0]) g += '<polygon points="' + x(0).toFixed(1) + ',' + (H - PB) + ' ' + pts + ' ' + x(dur).toFixed(1) + ',' + (H - PB) + '" fill="var(--accent)" opacity=".08"/>';
        g += '<polyline fill="none" stroke="' + l.color + '" stroke-width="' + l.w + '" stroke-linejoin="round" points="' + pts + '"/>';
      });
      g += '<line class="cross" x1="0" y1="' + PT + '" x2="0" y2="' + (H - PB) + '" stroke="var(--accent)" stroke-width="1" opacity="0"/>';
      var names = c.lines.filter(function (l) { return l.name; }).map(function (l) { return '<span style="color:' + l.color + '">' + l.name + '</span>'; }).join(' ');
      return '<div class="chart"><div class="chart-lb"><b>' + c.label + '</b><span>' + c.unit + '</span>' + (names ? '<em>' + names + '</em>' : '') + '</div>' +
        '<svg viewBox="0 0 ' + W + ' ' + c.h + '" style="width:100%;height:' + c.h + 'px;display:block">' + g + '</svg></div>';
    }
    var box = document.getElementById('charts');
    box.innerHTML = CH.map(build).join('') + '<div class="chart-x" id="chart-x"></div>';
    var xs = '', step = dur > 420 ? 120 : 60;
    for (var t = 0; t <= dur; t += step) xs += '<span style="left:' + ((x(t) / W) * 100).toFixed(2) + '%">' + mmss(t) + '</span>';
    document.getElementById('chart-x').innerHTML = xs;

    var out = document.getElementById('readout');
    function at(list, sec) { var b = list[0]; for (var i = 0; i < list.length; i++) { if (list[i][0] <= sec) b = list[i]; } return b; }
    function render(sec) {
      var p = at(F.prof, sec), e = at(F.env, sec), tr = at(F.track, sec);
      out.innerHTML = '<div><span>tempo</span><b>' + mmss(sec) + '</b></div>' +
        '<div><span>alt</span><b>' + fmt(p[1]) + '</b></div><div><span>ias</span><b>' + fmt(p[2]) + '</b></div>' +
        '<div><span>gs</span><b>' + fmt(p[6]) + '</b></div><div><span>vs</span><b>' + fmt(p[3]) + '</b></div>' +
        '<div><span>g</span><b>' + fmt(p[4], 2) + '</b></div><div><span>turb</span><b>' + fmt(p[5], 2) + '</b></div>' +
        '<div><span>solo</span><b>' + (p[7] ? 'sim' : 'não') + '</b></div>' +
        '<div><span>oat</span><b>' + fmt(e[1], 1) + '</b></div><div><span>vento</span><b>' + fmt(e[6]) + '/' + fmt(e[5]) + '</b></div>' +
        '<div><span>chuva</span><b>' + fmt(e[2], 1) + '</b></div><div><span>gelo</span><b>' + fmt(e[4], 2) + '</b></div>';
      var px = x(sec);
      box.querySelectorAll('.cross').forEach(function (l) { l.setAttribute('x1', px); l.setAttribute('x2', px); l.setAttribute('opacity', '.9'); });
      if (window.__cursor && tr) window.__cursor(tr[1], tr[2]);
    }
    box.onmousemove = function (ev) {
      var r = box.getBoundingClientRect();
      var sx = ((ev.clientX - r.left) / r.width) * W;
      render(Math.max(0, Math.min(dur, Math.round(((sx - PL) / (W - PL - PR)) * dur))));
    };
    box.onmouseleave = function () {
      box.querySelectorAll('.cross').forEach(function (l) { l.setAttribute('opacity', '0'); });
      if (window.__cursorOff) window.__cursorOff();
      render(0);
    };
    render(0);
  }

  /* ---------- mapa ---------- */
  var MAP = null, MLAYER = null, MCUR = null, MODE = 'turb';
  function drawMap() {
    if (!MAP) return;
    MLAYER.clearLayers();
    var pts = F.track.map(function (p) { return [p[1], p[2]]; });
    function altColor(a) { var f = a / F.alt_max; return f < .33 ? '#2C7CA5' : (f < .66 ? '#7BA05B' : '#B85400'); }
    function wxColor(e) { return e[2] > 10 ? '#E4574A' : (e[2] > 0.5 ? '#FF8A1F' : (e[3] ? '#A78BFA' : '#5AA9D6')); }
    function envAt(sec) { var b = F.env[0]; for (var i = 0; i < F.env.length; i++) { if (F.env[i][0] <= sec) b = F.env[i]; } return b; }
    for (var i = 0; i < F.track.length - 1; i++) {
      var c = MODE === 'turb' ? turbColor(F.prof[i] ? F.prof[i][5] : 0) : (MODE === 'alt' ? altColor(F.prof[i] ? F.prof[i][1] : 0) : wxColor(envAt(F.track[i][0])));
      L.polyline([[F.track[i][1], F.track[i][2]], [F.track[i + 1][1], F.track[i + 1][2]]],
        { color: c, weight: MODE === 'wx' ? 4.5 : 3.4, opacity: .95 }).addTo(MLAYER);
    }
    if (MODE === 'wx') {
      F.env.forEach(function (e) {
        var tp = null; for (var i = 0; i < F.track.length; i++) { if (F.track[i][0] <= e[0]) tp = F.track[i]; }
        if (!tp) return;
        L.circleMarker([tp[1], tp[2]], { radius: e[3] ? 7 : 5, color: '#fff', weight: 1.5, fillColor: wxColor(e), fillOpacity: .9 })
          .addTo(MLAYER).bindPopup('<b>' + mmss(e[0]) + '</b><br><span>' + e[1].toFixed(1) + ' °C · ' +
            (e[2] > 0.05 ? e[2].toFixed(1) + ' mm' : 'sem precipitação') + '<br>vento ' + Math.round(e[6]) + '/' + e[5].toFixed(0) + ' kt' +
            (e[3] ? ' · em nuvem' : '') + (e[4] > 0 ? '<br>gelo ' + e[4].toFixed(2) + '%' : '') + '</span>');
      });
    }
    L.circleMarker(pts[0], { radius: 6, color: '#fff', weight: 2, fillColor: '#2F7D50', fillOpacity: 1 }).addTo(MLAYER).bindPopup('Início');
    L.circleMarker(pts[pts.length - 1], { radius: 6, color: '#fff', weight: 2, fillColor: '#B33B2C', fillOpacity: 1 }).addTo(MLAYER).bindPopup('Fim');
    if (F.td) L.circleMarker([F.td.lat, F.td.lon], { radius: 8, color: '#fff', weight: 2, fillColor: '#B85400', fillOpacity: 1 })
      .addTo(MLAYER).bindPopup('Toque · ' + F.td.vs.toFixed(0) + ' fpm');
    MAP.fitBounds(L.latLngBounds(pts).pad(0.15));
    document.getElementById('map-note').textContent =
      MODE === 'turb' ? 'cor = turbulência medida' : (MODE === 'alt' ? 'cor = altitude' : 'cor = clima no momento da passagem');
    document.getElementById('map-count').textContent = F.track.length + ' pontos a 1 Hz · clima a cada 10 s';
    document.getElementById('legend').innerHTML =
      MODE === 'turb' ? '<span><i style="background:#5AA9D6"></i>calmo</span><span><i style="background:#FF8A1F"></i>moderado</span><span><i style="background:#E4574A"></i>severo</span>' :
        MODE === 'alt' ? '<span><i style="background:#2C7CA5"></i>baixo</span><span><i style="background:#7BA05B"></i>médio</span><span><i style="background:#B85400"></i>alto</span>' :
          '<span><i style="background:#5AA9D6"></i>seco</span><span><i style="background:#A78BFA"></i>em nuvem</span><span><i style="background:#FF8A1F"></i>precipitação</span><span><i style="background:#E4574A"></i>intensa</span>';
  }

  function initMap() {
    if (!window.L) { document.getElementById('map').innerHTML = '<p style="color:#5F7885;text-align:center;padding-top:140px;font-family:var(--fm);font-size:12px">Mapa indisponível</p>'; return; }
    var TL = { light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' };
    var th = function () { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; };
    MAP = L.map('map', { scrollWheelZoom: false, minZoom: 3, maxZoom: 14 });
    var base = L.tileLayer(TL[th()], { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>', maxZoom: 14, detectRetina: true }).addTo(MAP);
    new MutationObserver(function () { base.setUrl(TL[th()]); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    MLAYER = L.layerGroup().addTo(MAP);
    window.__cursor = function (lat, lon) {
      if (!MCUR) MCUR = L.circleMarker([lat, lon], { radius: 7, color: '#fff', weight: 2, fillColor: '#FF8A1F', fillOpacity: 1 }).addTo(MAP);
      else { MCUR.setLatLng([lat, lon]); MCUR.setStyle({ opacity: 1, fillOpacity: 1 }); }
    };
    window.__cursorOff = function () { if (MCUR) MCUR.setStyle({ opacity: 0, fillOpacity: 0 }); };
    document.querySelectorAll('.lb').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.lb').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on'); MODE = b.dataset.layer; drawMap();
      });
    });
    drawMap();
    setTimeout(function () { MAP.invalidateSize(); }, 0);
  }

  function renderAll() {
    renderHead(); renderPhases(); renderParcels(); renderEvents(); renderLanding(); renderObs(); renderCharts();
    if (MCUR) { MAP.removeLayer(MCUR); MCUR = null; }
    drawMap();
  }

  function boot(flights) {
    FLIGHTS = flights;
    // Vindo do Logbook (/voo?id=...), abre o voo pedido; sem id (ou id
    // que nao existe mais na telemetria), cai no primeiro da lista.
    var wantedId = new URLSearchParams(window.location.search).get('id');
    var initialIndex = wantedId ? FLIGHTS.findIndex(function (f) { return f.id === wantedId; }) : -1;
    if (initialIndex < 0) initialIndex = 0;
    F = FLIGHTS[initialIndex];

    // Tema (#theme) e cuidado pelo theme-toggle.js compartilhado, carregado
    // antes deste script em app_base.html.twig - nao duplicar o listener aqui.
    var order = FLIGHTS.map(function (f, i) { return i; }).sort(function (a, b) { return FLIGHTS[a].start < FLIGHTS[b].start ? -1 : 1; });
    var pos = order.indexOf(initialIndex);
    function go(delta) {
      pos = Math.max(0, Math.min(order.length - 1, pos + delta));
      F = FLIGHTS[order[pos]];
      renderAll();
      document.getElementById('prevf').disabled = pos === 0;
      document.getElementById('nextf').disabled = pos === order.length - 1;
    }
    document.getElementById('prevf').addEventListener('click', function () { go(-1); });
    document.getElementById('nextf').addEventListener('click', function () { go(1); });
    document.getElementById('nextf').disabled = pos === order.length - 1;
    document.getElementById('prevf').disabled = pos === 0;

    renderAll();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMap); else initMap();
  }

  var flightsUrl = window.KATABATIC_FLIGHTS_URL || '/assets/data/flights.json';
  fetch(flightsUrl)
    .then(function (r) { return r.json(); })
    .then(boot)
    .catch(function (err) {
      console.error('Katabatic: falha ao carregar telemetria do voo.', err);
      var meta = document.getElementById('h-meta');
      if (meta) meta.textContent = 'Não foi possível carregar a telemetria deste voo.';
    });
})();
