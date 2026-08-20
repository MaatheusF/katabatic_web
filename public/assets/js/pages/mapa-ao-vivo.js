/* ==========================================================================
   Katabatic — mapa-ao-vivo.js
   Mapa ao vivo (tela cheia): posição de toda a frota agora. Aeronaves "em
   voo" tem a posição simulada avançando ao longo do arco origem→destino,
   repetindo em loop a telemetria real gravada em flights.json (não existe
   feed ao vivo via ACARS ainda - ver comentário no MapaAoVivoController).
   Aeronaves paradas ficam fixas na base/estação atual. Coordenadas dos
   aeroportos vêm de airports.json (mesmo padrão de aeronave.js/voo.js).
   ========================================================================== */
(function () {
  'use strict';

  var FLYING_RAW = window.KATABATIC_MV_FLYING || [];
  var PARKED = window.KATABATIC_MV_PARKED || [];
  var AIRPORTS = {};
  var FLIGHTS_BY_ID = {};
  var MAP = null, MLAYER = null;
  var FLYING = [];

  /* ---------- geometria (mesmo helper de aeronave.js, com t contínuo) ---------- */
  function curveCtrl(a, b, bend) {
    var midLat = (a[0] + b[0]) / 2, midLon = (a[1] + b[1]) / 2;
    var dLat = b[0] - a[0], dLon = b[1] - a[1];
    var len = Math.sqrt(dLat * dLat + dLon * dLon) || 1;
    var perpLat = -dLon / len, perpLon = dLat / len;
    return [midLat + perpLat * bend, midLon + perpLon * bend];
  }
  function curveBend(a, b) {
    var dLat = b[0] - a[0], dLon = b[1] - a[1];
    return Math.sqrt(dLat * dLat + dLon * dLon) * 0.16;
  }
  function bezierAt(a, b, ctrl, t) {
    var lat = (1 - t) * (1 - t) * a[0] + 2 * (1 - t) * t * ctrl[0] + t * t * b[0];
    var lon = (1 - t) * (1 - t) * a[1] + 2 * (1 - t) * t * ctrl[1] + t * t * b[1];
    return [lat, lon];
  }
  function curvePoints(a, b, ctrl, n) {
    var pts = [];
    for (var i = 0; i <= n; i++) pts.push(bezierAt(a, b, ctrl, i / n));
    return pts;
  }
  function bearing(p1, p2) {
    var lat = p1[0] * Math.PI / 180;
    var dLon = (p2[1] - p1[1]) * Math.cos(lat);
    var dLat = p2[0] - p1[0];
    var deg = Math.atan2(dLon, dLat) * 180 / Math.PI;
    return (deg + 360) % 360;
  }

  /* ---------- markup dos marcadores ---------- */
  var PLANE_SVG = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L18 20L12 16L6 20Z"/></svg>';

  function flyingMarkerHtml(fa) {
    return '<div class="mv-marker">' +
      '<span class="mv-plane flying">' + PLANE_SVG + '</span>' +
      '<span class="mv-tag">' + fa.callsign + '</span>' +
      '</div>';
  }
  function parkedMarkerHtml(pa) {
    return '<div class="mv-marker">' +
      '<span class="mv-plane parked">' + PLANE_SVG + '</span>' +
      '<span class="mv-tag parked">' + pa.reg + '</span>' +
      '</div>';
  }

  function fmtAlt(v) { return v === null || v === undefined ? '—' : Math.round(v).toLocaleString('pt-BR') + ' ft'; }
  function fmtKt(v) { return v === null || v === undefined ? '—' : Math.round(v) + ' kt'; }

  function flyingPopup(fa) {
    var html = '<div class="mv-popup"><b>' + fa.data.callsign + ' · ' + fa.data.reg + '</b>' +
      fa.data.origem + ' → ' + fa.data.destino + '<span class="sub">' + fa.data.tipo + ' · ' + fa.data.modelo + '</span>' +
      '<div class="kv"><span>Altitude</span><b>' + fmtAlt(fa.lastAlt) + '</b><span>Vel. indicada</span><b>' + fmtKt(fa.lastIas) + '</b></div>';
    if (fa.data.flightId) html += '<br><a href="/voo?id=' + encodeURIComponent(fa.data.flightId) + '">Ver relatório real ↗</a>';
    html += '</div>';
    return html;
  }
  function parkedPopup(pa) {
    return '<div class="mv-popup"><b>' + pa.reg + '</b>' + pa.modelo +
      '<span class="sub">Base ' + pa.base + ' · em ' + pa.pos + '</span>' +
      '<div class="kv"><span>Status</span><b>' + pa.status + '</b></div>' +
      '<br><a href="/aeronave/' + encodeURIComponent(pa.reg) + '">Ver histórico ↗</a></div>';
  }

  /* ---------- construção dos marcadores ---------- */
  function buildFlying() {
    FLYING = FLYING_RAW.map(function (fa) {
      var a = AIRPORTS[fa.origem], b = AIRPORTS[fa.destino];
      if (!a || !b) return null;
      var aLL = [a.lat, a.lon], bLL = [b.lat, b.lon];
      var ctrl = curveCtrl(aLL, bLL, curveBend(aLL, bLL));
      L.polyline(curvePoints(aLL, bLL, ctrl, 32), { color: 'var(--accent)', weight: 1.6, opacity: .55, dashArray: '2 7' }).addTo(MLAYER);

      var marker = L.marker(aLL, {
        icon: L.divIcon({ className: '', html: flyingMarkerHtml(fa), iconSize: [130, 26], iconAnchor: [13, 13] }),
        zIndexOffset: 600
      }).addTo(MLAYER);

      var rec = FLIGHTS_BY_ID[fa.flightId] || null;
      var obj = { data: fa, F: rec, a: aLL, b: bLL, ctrl: ctrl, marker: marker, lastAlt: null, lastIas: null, lastGs: null };
      marker.on('click', function () { focusOn(aLL); marker.bindPopup(flyingPopup(obj)).openPopup(); });
      return obj;
    }).filter(Boolean);
  }

  function buildParked() {
    var perAirport = {};
    PARKED.forEach(function (pa) {
      var ap = AIRPORTS[pa.pos];
      if (!ap) return;
      var n = perAirport[pa.pos] || 0;
      perAirport[pa.pos] = n + 1;
      var ll = [ap.lat + n * 0.14, ap.lon + n * 0.16];
      pa._ll = ll;
      var marker = L.marker(ll, {
        icon: L.divIcon({ className: '', html: parkedMarkerHtml(pa), iconSize: [110, 24], iconAnchor: [10, 10] }),
        zIndexOffset: 200
      }).addTo(MLAYER);
      pa._marker = marker;
      marker.on('click', function () { focusOn(ll); marker.bindPopup(parkedPopup(pa)).openPopup(); });
    });
  }

  function buildAirportDots() {
    var active = {};
    FLYING_RAW.forEach(function (fa) { active[fa.origem] = true; active[fa.destino] = true; });
    PARKED.forEach(function (pa) { active[pa.pos] = true; active[pa.base] = true; });

    Object.keys(AIRPORTS).forEach(function (icao) {
      var ap = AIRPORTS[icao];
      L.circleMarker([ap.lat, ap.lon], { radius: 4, color: '#fff', weight: 1.5, fillColor: '#2C7CA5', fillOpacity: .85 })
        .addTo(MLAYER)
        .bindPopup('<div class="mv-popup"><b>' + icao + '</b>' + ap.name + '<span class="sub">' + ap.city + '</span></div>');
      if (active[icao]) {
        L.marker([ap.lat, ap.lon], {
          icon: L.divIcon({ className: '', html: '<span class="mv-airport-label">' + icao + '</span>', iconSize: null, iconAnchor: [-8, 6] }),
          interactive: false
        }).addTo(MLAYER);
      }
    });
  }

  function focusOn(ll) { if (MAP) MAP.flyTo(ll, Math.max(MAP.getZoom(), 6)); }

  /* ---------- painel lateral ---------- */
  function flyingRow(fa) {
    return '<div class="mv-row" data-focus="flying-' + fa.data.reg + '">' +
      '<i class="dot dot-fly"></i>' +
      '<div class="mv-row-body"><div class="mv-row-title">' + fa.data.callsign + '<span class="sub mono" style="font-weight:400;color:var(--muted)">' + fa.data.reg + '</span></div>' +
      '<div class="mv-row-sub">' + fa.data.origem + ' → ' + fa.data.destino + ' · ' + fa.data.tipo + '</div></div>' +
      '<div class="mv-row-metric" id="mv-metric-' + fa.data.reg.replace(/[^A-Za-z0-9]/g, '') + '"><b>—</b>—</div>' +
      '</div>';
  }
  function parkedRow(pa) {
    var dotCls = pa.statusTag === 'ok' ? 'ok' : (pa.statusTag === 'bad' ? 'bad' : '');
    return '<div class="mv-row" data-focus="parked-' + pa.reg + '">' +
      '<i class="dot dot-ground ' + dotCls + '"></i>' +
      '<div class="mv-row-body"><div class="mv-row-title">' + pa.reg + '</div>' +
      '<div class="mv-row-sub">' + pa.status + ' · ' + pa.pos + '</div></div>' +
      '<div class="mv-row-metric">' + pa.base + '</div>' +
      '</div>';
  }

  function renderPanel() {
    document.getElementById('mv-flying-list').innerHTML = FLYING.map(flyingRow).join('') || '<p class="mv-note">Nenhuma aeronave em voo agora.</p>';
    document.getElementById('mv-parked-list').innerHTML = PARKED.map(parkedRow).join('');
    document.getElementById('mv-count-flying').textContent = FLYING.length;
    document.getElementById('mv-count-parked').textContent = PARKED.length;
    document.getElementById('mv-fleet-count').textContent = (FLYING.length + PARKED.length) + ' aeronaves';

    document.getElementById('mv-flying-list').querySelectorAll('.mv-row').forEach(function (row, i) {
      row.addEventListener('click', function () {
        var fa = FLYING[i];
        if (fa) { focusOn(fa.marker.getLatLng()); fa.marker.fire('click'); }
      });
    });
    document.getElementById('mv-parked-list').querySelectorAll('.mv-row').forEach(function (row, i) {
      row.addEventListener('click', function () {
        var pa = PARKED[i];
        if (pa && pa._marker) { focusOn(pa._ll); pa._marker.fire('click'); }
      });
    });
  }

  function updateSideMetric(fa) {
    var el = document.getElementById('mv-metric-' + fa.data.reg.replace(/[^A-Za-z0-9]/g, ''));
    if (!el) return;
    el.innerHTML = '<b>' + fmtAlt(fa.lastAlt) + '</b>' + fmtKt(fa.lastIas);
  }

  /* ---------- loop de posição (repete a gravação real em loop) ---------- */
  function updateFlying(now) {
    FLYING.forEach(function (fa) {
      var dur = (fa.F && fa.F.dur) ? fa.F.dur : 300;
      var elapsed = (now / 1000) % dur;
      var t = elapsed / dur;
      var p1 = bezierAt(fa.a, fa.b, fa.ctrl, t);
      var p2 = bezierAt(fa.a, fa.b, fa.ctrl, Math.min(0.999, t + 0.004));
      var hdg = bearing(p1, p2);

      fa.marker.setLatLng(p1);
      var el = fa.marker.getElement();
      if (el) {
        var svg = el.querySelector('.mv-plane svg');
        if (svg) svg.style.transform = 'rotate(' + hdg.toFixed(0) + 'deg)';
      }

      if (fa.F && fa.F.prof && fa.F.prof.length) {
        var idx = Math.min(fa.F.prof.length - 1, Math.floor(elapsed));
        var row = fa.F.prof[idx];
        if (row) { fa.lastAlt = row[1]; fa.lastIas = row[2]; fa.lastGs = row[6]; }
      }
      updateSideMetric(fa);
    });
  }

  function updateClock(now) {
    var d = new Date(now);
    var el = document.getElementById('mv-clock');
    if (el) el.textContent = String(d.getUTCHours()).padStart(2, '0') + ':' + String(d.getUTCMinutes()).padStart(2, '0') + ':' + String(d.getUTCSeconds()).padStart(2, '0') + 'Z';
  }

  function tick() {
    var now = Date.now();
    updateClock(now);
    updateFlying(now);
  }

  /* ---------- mapa ---------- */
  function initMap() {
    if (!window.L) { document.getElementById('mv-map').innerHTML = '<p style="color:#5F7885;text-align:center;padding-top:140px;font-family:var(--font-mono);font-size:12px">Mapa indisponível</p>'; return; }
    var TL = { light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' };
    var th = function () { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; };
    MAP = L.map('mv-map', { scrollWheelZoom: false, minZoom: 2, maxZoom: 12 });
    var base = L.tileLayer(TL[th()], { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>', maxZoom: 12, detectRetina: true }).addTo(MAP);
    new MutationObserver(function () { base.setUrl(TL[th()]); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    MLAYER = L.layerGroup().addTo(MAP);

    buildAirportDots();
    buildParked();
    buildFlying();
    renderPanel();

    var bounds = [];
    FLYING.forEach(function (fa) { bounds.push(fa.a, fa.b); });
    PARKED.forEach(function (pa) { if (pa._ll) bounds.push(pa._ll); });
    if (bounds.length) MAP.fitBounds(L.latLngBounds(bounds).pad(0.35)); else MAP.setView([20, -50], 3);

    setTimeout(function () { MAP.invalidateSize(); }, 0);
    tick();
    setInterval(tick, 1000);
  }

  /* ---------- carregamento de dados ---------- */
  var airportsUrl = window.KATABATIC_AIRPORTS_URL || '/assets/data/airports.json';
  var flightsUrl = window.KATABATIC_FLIGHTS_URL || '/assets/data/flights.json';

  Promise.all([
    fetch(airportsUrl).then(function (r) { return r.json(); }),
    fetch(flightsUrl).then(function (r) { return r.json(); })
  ]).then(function (results) {
    AIRPORTS = results[0] || {};
    (results[1] || []).forEach(function (f) { FLIGHTS_BY_ID[f.id] = f; });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMap); else initMap();
  }).catch(function (err) {
    console.error('Katabatic: falha ao carregar dados do mapa ao vivo.', err);
    document.getElementById('mv-map').innerHTML = '<p style="color:#5F7885;text-align:center;padding-top:140px;font-family:var(--font-mono);font-size:12px">Não foi possível carregar os dados do mapa.</p>';
  });

  document.addEventListener('katabatic:langchange', function () {
    var el = document.getElementById('title');
    if (el) el.textContent = document.documentElement.lang === 'en' ? 'Live map' : 'Mapa ao vivo';
  });
})();
