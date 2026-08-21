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

  // Le o dicionario EN da pagina (window.KATABATIC_I18N_EN, definido no
  // page_javascripts de voo/index.html.twig) e devolve o texto em ingles
  // se o idioma atual for EN e a chave existir, senao cai no PT literal
  // que ja estava embutido no JS. `window.katabaticLang` vem do
  // lang-toggle.js (carregado por ultimo em base.html.twig) - pode ainda
  // nao existir na primeira renderizacao sincrona desta pagina, dai o
  // fallback pro PT tambem nesse caso.
  function tr(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  var FLIGHTS = [];
  var F = null;

  function renderHead() {
    // `.rt` (origem/destino) e o local no meta vinham fixos como
    // "UEEE"/"Yakutsk" no HTML/JS - os 3 voos de teste gravados sao
    // mesmo todos UEEE→UEEE (voo local perto de Yakutsk, ver README),
    // entao isso nunca dava errado ate agora, mas era coincidencia: o
    // texto nao vinha de F.orig/F.dest de verdade. Sem uma tabela de
    // nome-de-cidade pra ICAOs de fora da rede KBT (UEEE nao esta em
    // airports.json de proposito - telemetria real fica geograficamente
    // separada da rede mock), o local agora mostra o proprio ICAO em vez
    // de inventar "Yakutsk" fixo, que ficaria errado no primeiro voo
    // gravado em outro lugar.
    var routeEl = document.getElementById('h-route');
    if (routeEl) routeEl.innerHTML = (F.orig || '—') + '<i></i>' + (F.dest || '—');
    document.getElementById('h-meta').textContent =
      (F.orig || '—') + ' · ' + F.start.slice(8, 10) + '/' + F.start.slice(5, 7) + '/' + F.start.slice(0, 4) + ' ' + F.start.slice(11, 16) + 'Z · ' + F.wx;
    document.getElementById('h-call').textContent = F.id ? F.id.split('_').pop() : '—';
    var s = document.getElementById('score');
    s.textContent = F.score;
    var c = F.score >= 75 ? 'var(--danger)' : (F.score >= 50 ? 'var(--accent)' : 'var(--ice)');
    s.style.color = c;
    document.getElementById('score-lbl').textContent = F.score >= 75 ? tr('voo.score.severe', 'severo') : (F.score >= 50 ? tr('voo.score.demanding', 'exigente') : tr('voo.score.routine', 'rotina'));

    var k = [[tr('voo.kpi.duration', 'Duração'), mmss(F.dur), ''], [tr('voo.kpi.airtime', 'Tempo em voo'), mmss(F.air_s), ''], [tr('voo.kpi.distance', 'Distância'), F.dist.toFixed(1), ' nm'],
      [tr('voo.kpi.gsavg', 'GS média'), F.gs_avg, ' kt'], [tr('voo.kpi.altmax', 'Altitude máx'), F.alt_max.toLocaleString('pt-BR'), ' ft'], [tr('voo.kpi.iasmax', 'IAS máx'), F.ias_max, ' kt'],
      [tr('voo.kpi.vsmax', 'VS máx'), '+' + F.vs_max, ' fpm'], [tr('voo.kpi.vsmin', 'VS mín'), F.vs_min, ' fpm'], [tr('voo.kpi.gmax', 'Pico de G'), F.gmax.toFixed(2), ''],
      [tr('voo.kpi.gmin', 'G mínimo'), F.gmin.toFixed(2), ''], [tr('voo.kpi.wind', 'Vento méd. / rajada'), (F.windc >= 0 ? '+' : '') + F.windc + ' / ' + F.wind_max.toFixed(1), ' kt'],
      [tr('voo.fuel', 'Combustível'), F.fuel.toFixed(1), ' lb'], [tr('voo.kpi.cloudtime', 'Tempo em nuvem'), mmss(F.cloud_s), ''], [tr('voo.kpi.tempmin', 'Temp mín'), F.oat_min.toFixed(1), ' °C'],
      [tr('voo.kpi.temprange', 'Amplitude'), (F.oat_max - F.oat_min).toFixed(1), ' °C'], [tr('voo.kpi.precipmax', 'Chuva máx'), F.precip_max.toFixed(1), ' mm'], [tr('voo.kpi.icing', 'Gelo'), F.ice.toFixed(2), ' %'],
      [tr('voo.kpi.exceedances', 'Excedências'), F.exceed, '']];
    document.getElementById('kpis').innerHTML = k.map(function (x) {
      return '<div><b class="mono">' + x[1] + '<small>' + x[2] + '</small></b><span>' + x[0] + '</span></div>';
    }).join('');
  }

  function renderPhases() {
    var tot = F.dur || 1;
    // `p[0]` (solo/subida/cruzeiro/descida) tambem indexa PHC (cor) - so o
    // ROTULO exibido (texto do bloco/title) passa pelo dicionario de
    // idioma via PHL, o valor de dados em si (p[0]) nunca muda.
    var PHL = { solo: tr('voo.phase.ground', 'solo'), subida: tr('voo.phase.climb', 'subida'), cruzeiro: tr('voo.phase.cruise', 'cruzeiro'), descida: tr('voo.phase.descent', 'descida') };
    document.getElementById('phases').innerHTML = F.phases.map(function (p) {
      var w = ((p[2] - p[1] + 1) / tot) * 100;
      return '<div style="width:' + w.toFixed(2) + '%;background:' + PHC[p[0]] + '" title="' + PHL[p[0]] + ' ' + mmss(p[1]) + '–' + mmss(p[2]) + '">' +
        (w > 9 ? PHL[p[0]] : '') + '</div>';
    }).join('');
    document.getElementById('ph-note').textContent = F.phases.length + ' ' + tr('voo.phases.segments', 'segmentos') + ' · ' + PHL.solo + ' ' + mmss(F.ground_s) + ' · ' + tr('voo.phases.air', 'ar') + ' ' + mmss(F.air_s);
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
      el.innerHTML = '<p class="obs">' + tr('voo.landing.empty', 'Nenhum toque registrado: a sessão foi encerrada com a aeronave em voo.') + '</p>';
      return;
    }
    var vs = F.td.vs;
    var q = vs < 150 ? [tr('voo.landing.smooth', 'Suave'), 'var(--ok)'] : (vs < 300 ? [tr('voo.landing.normal', 'Normal'), 'var(--ok)'] : (vs < 450 ? [tr('voo.landing.firm', 'Firme'), 'var(--accent)'] : [tr('voo.landing.hard', 'Duro'), 'var(--danger)']));
    note.textContent = q[0];
    el.innerHTML = '<div class="verdict" style="border-left-color:' + q[1] + '">' + tr('voo.landing.classifiedas', 'Toque classificado como') + ' <b style="color:' + q[1] + '">' + q[0].toLowerCase() + '</b>' +
      (F.bounces ? ' · <b>' + F.bounces + ' ' + tr('voo.landing.bounce', 'quique') + (F.bounces > 1 ? 's' : '') + '</b> ' + tr('voo.landing.aftercontact', 'após o contato') : '') + '.</div>' +
      '<div class="land">' +
      '<div><span>' + tr('voo.landing.touchdownrate', 'Razão de toque') + '</span><b style="color:' + q[1] + '">' + vs.toFixed(0) + ' fpm</b></div>' +
      '<div><span>' + tr('voo.landing.pitch', 'Atitude') + '</span><b>' + F.td.pitch.toFixed(1) + '°</b></div>' +
      '<div><span>' + tr('voo.landing.bank', 'Inclinação') + '</span><b>' + F.td.bank.toFixed(1) + '°</b></div>' +
      '<div><span>' + tr('voo.landing.heading', 'Proa') + '</span><b>' + F.td.hdg + '°</b></div>' +
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
    else { view.classList.add('empty'); p.textContent = tr('voo.report.empty', 'Nenhum relato registrado para este voo.'); }
  }

  function exitEditMode() {
    document.getElementById('report-view').style.display = '';
    document.getElementById('report-edit-wrap').style.display = 'none';
  }

  function updateReportCount() {
    var n = document.getElementById('pilot-obs').value.length;
    document.getElementById('report-count').textContent = window.katabaticLang && window.katabaticLang() === 'en' ?
      n + ' character' + (n === 1 ? '' : 's') : n + ' caractere' + (n === 1 ? '' : 's');
  }

  document.getElementById('pilot-obs').addEventListener('input', updateReportCount);
  document.getElementById('report-edit').addEventListener('click', function () {
    document.getElementById('pilot-obs').value = F.pilot_report || '';
    document.getElementById('report-view').style.display = 'none';
    document.getElementById('report-edit-wrap').style.display = '';
    document.getElementById('report-error').style.display = 'none';
    updateReportCount();
    document.getElementById('pilot-obs').focus();
  });
  document.getElementById('report-cancel').addEventListener('click', function () {
    exitEditMode();
  });
  var reportSaveBtn = document.getElementById('report-save');
  var reportSaveLabel = reportSaveBtn.textContent;
  var reportErrorEl = document.getElementById('report-error');

  reportSaveBtn.addEventListener('click', function () {
    // Ate esta fatia de backend, "Salvar relato" so mudava F.pilot_report
    // em memoria (perdia no reload) - agora e um POST de verdade em
    // /voo/{codigo}/relato (ver VooController::relato()), so pra voos com
    // telemetria gravada (F.id e o codigo/flightId - ver flights.json).
    var novoRelato = document.getElementById('pilot-obs').value.trim();
    reportErrorEl.style.display = 'none';
    reportSaveBtn.disabled = true;
    reportSaveBtn.textContent = tr('voo.report.saving', 'Salvando…');

    fetch('/voo/' + encodeURIComponent(F.id) + '/relato', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ relato: novoRelato })
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (result) {
        reportSaveBtn.disabled = false;
        reportSaveBtn.textContent = reportSaveLabel;
        if (!result.ok) {
          reportErrorEl.textContent = (result.body && result.body.error) || tr('voo.report.error', 'Não foi possível salvar — tente de novo.');
          reportErrorEl.style.display = '';
          return;
        }
        F.pilot_report = result.body.pilot_report;
        exitEditMode();
        renderReportView();
      })
      .catch(function () {
        reportSaveBtn.disabled = false;
        reportSaveBtn.textContent = reportSaveLabel;
        reportErrorEl.textContent = tr('voo.report.error', 'Não foi possível salvar — verifique sua conexão e tente de novo.');
        reportErrorEl.style.display = '';
      });
  });

  /* ---------- debrief ---------- */
  function renderCharts() {
    var W = 900, PL = 46, PR = 12, dur = F.dur;
    var x = function (s) { return PL + (s / dur) * (W - PL - PR); };
    function series(src, idx) { return src.map(function (r) { return [r[0], r[idx]]; }); }
    var CH = [
      { label: tr('voo.altitude', 'Altitude'), unit: 'ft', h: 96, fill: true, lines: [{ data: series(F.prof, 1), color: 'var(--text)', w: 1.8 }] },
      { label: tr('voo.chart.speed', 'Velocidade'), unit: 'kt', h: 82, lines: [{ data: series(F.prof, 2), color: 'var(--accent)', w: 1.6, name: 'IAS' }, { data: series(F.prof, 6), color: 'var(--ice)', w: 1.4, name: 'GS' }, { data: series(F.prof, 8), color: 'var(--ok)', w: 1.2, name: 'TAS' }] },
      { label: tr('voo.chart.vspeed', 'Razão vertical'), unit: 'fpm', h: 82, zero: true, lines: [{ data: series(F.prof, 3), color: 'var(--ice)', w: 1.5 }] },
      { label: tr('voo.chart.loadfactor', 'Fator de carga'), unit: 'G', h: 76, ref: 1, lines: [{ data: series(F.prof, 4), color: 'var(--danger)', w: 1.5 }] },
      { label: tr('voo.turbulence', 'Turbulência'), unit: 'rms', h: 76, lines: [{ data: series(F.prof, 5), color: 'var(--accent)', w: 1.4 }] },
      { label: tr('voo.chart.temperature', 'Temperatura'), unit: '°C', h: 82, lines: [{ data: series(F.env, 1), color: 'var(--text)', w: 1.8 }] },
      { label: tr('voo.chart.wind', 'Vento'), unit: 'kt', h: 76, lines: [{ data: series(F.env, 5), color: 'var(--ice)', w: 1.6 }] },
      { label: tr('voo.fuel', 'Combustível'), unit: 'lb', h: 76, lines: [{ data: series(F.env, 7), color: 'var(--accent)', w: 1.6 }] }
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
      // `trk` (linha de F.track) - deliberadamente NAO se chama `tr` aqui:
      // esse nome ja e o helper de traducao (function tr(), topo do
      // arquivo) e as duas coisas convivem neste mesmo escopo (as chamadas
      // tr('voo.readout...') logo abaixo). Antes da traducao esta variavel
      // se chamava `tr` sem problema; um rename automatizado por regex
      // (L(...) -> tr(...), pra nao colidir com o `L` global do Leaflet)
      // acabou colidindo com ESSE `tr` local pre-existente, quebrando o
      // hover do grafico inteiro (TypeError: tr is not a function).
      var p = at(F.prof, sec), e = at(F.env, sec), trk = at(F.track, sec);
      out.innerHTML = '<div><span>' + tr('voo.readout.time', 'tempo') + '</span><b>' + mmss(sec) + '</b></div>' +
        '<div><span>alt</span><b>' + fmt(p[1]) + '</b></div><div><span>ias</span><b>' + fmt(p[2]) + '</b></div>' +
        '<div><span>gs</span><b>' + fmt(p[6]) + '</b></div><div><span>tas</span><b>' + fmt(p[8]) + '</b></div>' +
        '<div><span>vs</span><b>' + fmt(p[3]) + '</b></div>' +
        '<div><span>g</span><b>' + fmt(p[4], 2) + '</b></div><div><span>turb</span><b>' + fmt(p[5], 2) + '</b></div>' +
        '<div><span>' + tr('voo.readout.onground', 'solo') + '</span><b>' + (p[7] ? tr('voo.readout.yes', 'sim') : tr('voo.readout.no', 'não')) + '</b></div>' +
        '<div><span>oat</span><b>' + fmt(e[1], 1) + '</b></div><div><span>' + tr('voo.readout.wind', 'vento') + '</span><b>' + fmt(e[6]) + '/' + fmt(e[5]) + '</b></div>' +
        '<div><span>' + tr('voo.readout.precip', 'chuva') + '</span><b>' + fmt(e[2], 1) + '</b></div><div><span>' + tr('voo.readout.icing', 'gelo') + '</span><b>' + fmt(e[4], 2) + '</b></div>' +
        '<div><span>' + tr('voo.readout.fuel', 'combustível') + '</span><b>' + fmt(e[7]) + '</b></div>';
      var px = x(sec);
      box.querySelectorAll('.cross').forEach(function (l) { l.setAttribute('x1', px); l.setAttribute('x2', px); l.setAttribute('opacity', '.9'); });
      if (window.__cursor && trk) window.__cursor(trk[1], trk[2]);
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
            (e[2] > 0.05 ? e[2].toFixed(1) + ' mm' : tr('voo.map.noprecip', 'sem precipitação')) + '<br>' + tr('voo.readout.wind', 'vento') + ' ' + Math.round(e[6]) + '/' + e[5].toFixed(0) + ' kt' +
            (e[3] ? ' · ' + tr('voo.legend.incloud', 'em nuvem') : '') + (e[4] > 0 ? '<br>' + tr('voo.readout.icing', 'gelo') + ' ' + e[4].toFixed(2) + '%' : '') + '</span>');
      });
    }
    L.circleMarker(pts[0], { radius: 6, color: '#fff', weight: 2, fillColor: '#2F7D50', fillOpacity: 1 }).addTo(MLAYER).bindPopup(tr('voo.map.start', 'Início'));
    L.circleMarker(pts[pts.length - 1], { radius: 6, color: '#fff', weight: 2, fillColor: '#B33B2C', fillOpacity: 1 }).addTo(MLAYER).bindPopup(tr('voo.map.end', 'Fim'));
    if (F.td) L.circleMarker([F.td.lat, F.td.lon], { radius: 8, color: '#fff', weight: 2, fillColor: '#B85400', fillOpacity: 1 })
      .addTo(MLAYER).bindPopup(tr('voo.map.touchdown', 'Toque') + ' · ' + F.td.vs.toFixed(0) + ' fpm');
    MAP.fitBounds(L.latLngBounds(pts).pad(0.15));
    document.getElementById('map-note').textContent =
      MODE === 'turb' ? tr('voo.map.note.turb', 'cor = turbulência medida') : (MODE === 'alt' ? tr('voo.map.note.alt', 'cor = altitude') : tr('voo.map.note.wx', 'cor = clima no momento da passagem'));
    document.getElementById('map-count').textContent = F.track.length + ' pontos a 1 Hz · clima a cada 10 s';
    document.getElementById('legend').innerHTML =
      MODE === 'turb' ? '<span><i style="background:#5AA9D6"></i>' + tr('voo.legend.calm', 'calmo') + '</span><span><i style="background:#FF8A1F"></i>' + tr('voo.legend.moderate', 'moderado') + '</span><span><i style="background:#E4574A"></i>' + tr('voo.legend.severe', 'severo') + '</span>' :
        MODE === 'alt' ? '<span><i style="background:#2C7CA5"></i>' + tr('voo.legend.low', 'baixo') + '</span><span><i style="background:#7BA05B"></i>' + tr('voo.legend.medium', 'médio') + '</span><span><i style="background:#B85400"></i>' + tr('voo.legend.high', 'alto') + '</span>' :
          '<span><i style="background:#5AA9D6"></i>' + tr('voo.legend.dry', 'seco') + '</span><span><i style="background:#A78BFA"></i>' + tr('voo.legend.incloud', 'em nuvem') + '</span><span><i style="background:#FF8A1F"></i>' + tr('voo.legend.precip', 'precipitação') + '</span><span><i style="background:#E4574A"></i>' + tr('voo.legend.heavy', 'intensa') + '</span>';
  }

  function renderMapUnavailable() {
    var el = document.getElementById('map');
    if (el) el.innerHTML = '<p style="color:#5F7885;text-align:center;padding-top:140px;font-family:var(--fm);font-size:12px">' + tr('voo.map.unavailable', 'Mapa indisponível') + '</p>';
  }

  function initMap() {
    if (!window.L) { renderMapUnavailable(); return; }
    var TL = { light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' };
    var th = function () { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; };
    MAP = L.map('map', { scrollWheelZoom: true, minZoom: 3, maxZoom: 14 });
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
      if (meta) meta.textContent = tr('voo.error.load', 'Não foi possível carregar a telemetria deste voo.');
    });

  // voo.js nao tinha nenhum tratamento de 'katabatic:langchange' - toda a
  // tela e montada dinamicamente a partir da telemetria (KPIs, fases,
  // graficos, leitura ao passar o mouse, card de pouso, mapa), entao
  // trocar o idioma depois do carregamento inicial precisa re-chamar as
  // funcoes de render que tem texto traduzivel. Deliberadamente NAO
  // chama renderObs()/exitEditMode() aqui: exitEditMode() fecharia o
  // formulario de relato do piloto (e descartaria o rascunho digitado)
  // so por causa da troca de idioma, o que seria uma perda de dados
  // desnecessaria - so o texto do estado vazio (renderReportView) e do
  // contador precisa ficar em dia.
  document.addEventListener('katabatic:langchange', function () {
    if (!F) return;
    renderHead();
    renderPhases();
    renderLanding();
    renderReportView();
    renderCharts();
    updateReportCount();
    if (MAP) drawMap(); else if (document.getElementById('map')) renderMapUnavailable();
  });
})();
