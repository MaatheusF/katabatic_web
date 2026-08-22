/* Katabatic — Home institucional: mapa (Leaflet) e alternancia PT/EN.
   Dados de rede (NET) e dicionario (I18N) ainda sao mock — entram via API
   quando o backend estiver ligado (ver docs/mockups-originais). */

/* Helper padrao pra texto dinamico gerado por este script (popups,
   stamp do radar, prompt da chave OWM etc.) - o data-i18n/data-i18n-attr
   do lang-toggle.js so cobre HTML/atributos ja presentes no DOM no load,
   entao qualquer coisa montada em JS precisa ler o dicionario na mao. */
function tr(key, ptFallback) {
  var en = window.KATABATIC_I18N_EN || {};
  return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
}

/* ---------- mapa ----------
   Base: OpenStreetMap servido pela CARTO (light_all / dark_all), sem chave de API.
   Radar: RainViewer, API publica, tambem sem chave. */
var NET = {
  north: {
    center: [66.2, -150.5], zoom: 5,
    base: { icao: 'PAFA', name: 'Fairbanks', ll: [64.8151, -147.8564] },
    stations: [
      { icao: 'PABT', name: 'Bettles', ll: [66.9139, -151.5290], wx: 'calm', diff: 34 },
      { icao: 'PFYU', name: 'Fort Yukon', ll: [66.5715, -145.2503], wx: 'gust', diff: 58 },
      { icao: 'PAKP', name: 'Anaktuvuk Pass', ll: [68.1336, -151.7430], wx: 'ice', diff: 71 },
      { icao: 'PASC', name: 'Deadhorse', ll: [70.1947, -148.4652], wx: 'storm', diff: 82 },
      { icao: 'PAOT', name: 'Kotzebue', ll: [66.8847, -162.5985], wx: 'fog', diff: 63 }
    ],
    flight: { call: 'KBT118', reg: 'N208KB', ll: [65.90, -149.65], hdg: 340, info: 'FL095 · 148 kt' }
  },
  south: {
    center: [-51.5, -70.5], zoom: 5,
    base: { icao: 'SCCI', name: 'Punta Arenas', ll: [-53.0026, -70.8546] },
    stations: [
      { icao: 'SCNT', name: 'Puerto Natales', ll: [-51.6715, -72.5283], wx: 'gust', diff: 78 },
      { icao: 'SCGZ', name: 'Puerto Williams', ll: [-54.9311, -67.6262], wx: 'storm', diff: 85 },
      { icao: 'SCFM', name: 'Porvenir', ll: [-53.2537, -70.3193], wx: 'calm', diff: 29 },
      { icao: 'SCBA', name: 'Balmaceda', ll: [-45.9161, -71.6895], wx: 'fog', diff: 52 }
    ],
    flight: { call: 'KBT412', reg: 'CC-KBA', ll: [-52.34, -71.70], hdg: 331, info: 'FL085 · 132 kt' }
  }
};

function katabaticRenderMapUnavailable(mapEl) {
  mapEl.innerHTML =
    '<p style="color:#5F7885;font-family:var(--font-mono);font-size:12px;text-align:center;padding-top:140px">' +
    tr('map.err.noLeaflet', 'Mapa indisponível — sem conexão com o CDN do Leaflet.') + '</p>';
}

function katabaticInitMap() {
  var mapEl = document.getElementById('map');
  if (!mapEl) return;

  if (!window.L) {
    katabaticRenderMapUnavailable(mapEl);
    document.addEventListener('katabatic:langchange', function () {
      if (!window.L) katabaticRenderMapUnavailable(mapEl);
    });
    return;
  }

  var TILES = {
    light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
  };
  var ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>';

  var map = L.map('map', {
    zoomControl: true, scrollWheelZoom: false, attributionControl: true,
    minZoom: 3, maxZoom: 15
  }).setView(NET.north.center, NET.north.zoom);

  function currentTheme() { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

  var base = L.tileLayer(TILES[currentTheme()], { attribution: ATTR, maxZoom: 15, detectRetina: true }).addTo(map);
  var overlay = L.layerGroup().addTo(map);
  var radar = null;

  var WX = { calm: '#8FA3AD', gust: '#FF8A1F', ice: '#5AA9D6', fog: '#A78BFA', storm: '#E4574A' };
  var WX_LABEL_PT = { calm: 'CALMO', gust: 'RAJADA', ice: 'GELO', fog: 'NEBLINA', storm: 'TEMPESTADE' };
  function wxLabel(key) {
    var pt = WX_LABEL_PT[key] || WX_LABEL_PT.calm;
    var dictKey = 'map.wx.' + key;
    return tr(dictKey, pt);
  }

  function dot(cls, size, color) {
    var style = color ? ' style="background:' + color + ';box-shadow:0 0 0 3px ' + color + '33"' : '';
    return L.divIcon({ className: '', html: '<span class="' + cls + '"' + style + '></span>', iconSize: [size, size], iconAnchor: [size / 2, size / 2] });
  }

  /* Rota em circulo maximo: em latitude alta a linha reta do Mercator
     erra a trajetoria real, entao interpola de verdade. */
  function greatCircle(a, b, n) {
    var R = Math.PI / 180, D = 180 / Math.PI;
    var la1 = a[0] * R, lo1 = a[1] * R, la2 = b[0] * R, lo2 = b[1] * R;
    var d = 2 * Math.asin(Math.sqrt(
      Math.pow(Math.sin((la1 - la2) / 2), 2) +
      Math.cos(la1) * Math.cos(la2) * Math.pow(Math.sin((lo1 - lo2) / 2), 2)
    ));
    if (!d) return [a, b];
    var pts = [];
    for (var i = 0; i <= n; i++) {
      var f = i / n;
      var A = Math.sin((1 - f) * d) / Math.sin(d);
      var B = Math.sin(f * d) / Math.sin(d);
      var x = A * Math.cos(la1) * Math.cos(lo1) + B * Math.cos(la2) * Math.cos(lo2);
      var y = A * Math.cos(la1) * Math.sin(lo1) + B * Math.cos(la2) * Math.sin(lo2);
      var z = A * Math.sin(la1) + B * Math.sin(la2);
      pts.push([Math.atan2(z, Math.sqrt(x * x + y * y)) * D, Math.atan2(y, x) * D]);
    }
    return pts;
  }
  function plane(hdg) {
    return L.divIcon({
      className: 'mk-ac',
      html: '<svg viewBox="0 0 24 24" style="transform:rotate(' + hdg + 'deg)"><path d="M12 2l7 18-7-4-7 4z" fill="currentColor"/></svg>',
      iconSize: [22, 22], iconAnchor: [11, 11]
    });
  }

  function draw(key) {
    var net = NET[key];
    overlay.clearLayers();

    L.marker(net.base.ll, { icon: dot('mk-base', 14) })
      .bindPopup('<b>' + net.base.icao + '</b> ' + net.base.name + '<br><span>BASE</span>')
      .addTo(overlay);

    net.stations.forEach(function (st) {
      var color = WX[st.wx] || WX.calm;
      L.polyline(greatCircle(net.base.ll, st.ll, 48), {
        color: color, weight: 1.4, opacity: .55
      }).addTo(overlay);
      L.marker(st.ll, { icon: dot('mk-stn', 10, color) })
        .bindPopup('<b>' + st.icao + '</b> ' + st.name +
          '<br><span>' + wxLabel(st.wx) + ' &middot; ' + tr('map.popup.diff', 'dificuldade prevista') + ' ' + st.diff + '</span>')
        .addTo(overlay);
    });

    L.polyline([net.base.ll, net.flight.ll], { color: '#FF8A1F', weight: 1.5, opacity: .8, dashArray: '5 6' }).addTo(overlay);
    L.marker(net.flight.ll, { icon: plane(net.flight.hdg) })
      .bindPopup('<b>' + net.flight.call + '</b> ' + net.flight.reg + '<br><span>' + net.flight.info + '</span>')
      .addTo(overlay);

    map.flyTo(net.center, net.zoom, { duration: .8 });
  }

  draw('north');

  document.querySelectorAll('.map-tabs button[data-pane]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.map-tabs button[data-pane]').forEach(function (b) {
        b.setAttribute('aria-pressed', String(b === btn));
      });
      draw(btn.dataset.pane);
    });
  });

  /* tema: troca o tile sem recriar o mapa */
  new MutationObserver(function () {
    base.setUrl(TILES[currentTheme()]);
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  /* radar de precipitacao */
  var radarBtn = document.getElementById('radar-btn');
  var stamp = document.getElementById('radar-stamp');
  var RADAR_MAX_ZOOM = 8;
  var radarTime = '';

  /* stampState guarda so o "tipo" da mensagem exibida no rodape do mapa;
     o texto de verdade e montado por renderStamp() a partir do idioma
     atual, pra poder ser remontado quando o usuario troca de idioma sem
     precisar recalcular o resto do estado do radar/modelo. */
  var stampState = null; // null | 'hidden' | 'time' | 'unavailable' | 'model'

  function renderStamp() {
    if (!stamp) return;
    if (stampState === 'hidden') {
      stamp.textContent = tr('map.radar.hiddenAbove', 'radar oculto acima do zoom') + ' ' + RADAR_MAX_ZOOM;
    } else if (stampState === 'time') {
      stamp.textContent = radarTime;
    } else if (stampState === 'unavailable') {
      stamp.textContent = tr('map.radar.unavailable', 'radar indisponivel');
    } else if (stampState === 'model') {
      stamp.textContent = tr('map.model.updates', 'modelo · atualiza a cada 3h');
    } else {
      stamp.textContent = '';
    }
  }
  document.addEventListener('katabatic:langchange', renderStamp);

  function syncRadar() {
    if (!radar) return;
    var tooClose = map.getZoom() > RADAR_MAX_ZOOM;
    if (tooClose && map.hasLayer(radar)) {
      map.removeLayer(radar);
      stampState = 'hidden';
      renderStamp();
    } else if (!tooClose && !map.hasLayer(radar)) {
      map.addLayer(radar);
      stampState = 'time';
      renderStamp();
    }
  }
  map.on('zoomend', syncRadar);

  radarBtn.addEventListener('click', function () {
    if (radar) {
      if (map.hasLayer(radar)) map.removeLayer(radar);
      radar = null; radarTime = '';
      radarBtn.setAttribute('aria-pressed', 'false');
      stampState = null;
      renderStamp();
      return;
    }
    if (owm) { toggleOff(owm, owmBtn); owm = null; }
    fetch('https://api.rainviewer.com/public/weather-maps.json')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var frames = (data.radar && data.radar.past) || [];
        if (!frames.length) throw new Error('sem quadros');
        var last = frames[frames.length - 1];
        radar = L.tileLayer(data.host + last.path + '/256/{z}/{x}/{y}/4/1_1.png', {
          opacity: .65, maxZoom: 15, maxNativeZoom: 7, zIndex: 350,
          attribution: '<a href="https://www.rainviewer.com/">RainViewer</a>'
        }).addTo(map);
        radarBtn.setAttribute('aria-pressed', 'true');
        radarTime = 'radar ' + new Date(last.time * 1000).toISOString().slice(11, 16) + 'Z';
        stampState = 'time';
        renderStamp();
        syncRadar();
      })
      .catch(function () {
        stampState = 'unavailable';
        renderStamp();
      });
  });

  /* camada de modelo (OpenWeather Weather Maps 1.0)
     Zoom nativo 0-12, gratuito, exige atribuicao visivel.
     A chave e pedida uma vez por sessao — trocar por campo de configuracao real depois. */
  var owmBtn = document.getElementById('owm-btn');
  var owm = null;
  var OWM_KEY = '';

  function toggleOff(layer, btn) {
    if (layer && map.hasLayer(layer)) map.removeLayer(layer);
    btn.setAttribute('aria-pressed', 'false');
  }

  owmBtn.addEventListener('click', function () {
    if (owm) {
      toggleOff(owm, owmBtn);
      owm = null;
      stampState = null;
      renderStamp();
      return;
    }
    if (!OWM_KEY) {
      OWM_KEY = (window.prompt(tr('map.owm.prompt', 'Chave da API OpenWeather (grátis em openweathermap.org/appid):')) || '').trim();
      if (!OWM_KEY) return;
    }
    if (radar) { toggleOff(radar, radarBtn); radar = null; }

    owm = L.tileLayer(
      'https://tile.openweathermap.org/map/precipitation_new/{z}/{x}/{y}.png?appid=' + OWM_KEY,
      { opacity: .6, maxZoom: 15, maxNativeZoom: 12, zIndex: 350, attribution: 'Weather data &copy; <a href="https://openweathermap.org">OpenWeather</a>' }
    ).addTo(map);
    owmBtn.setAttribute('aria-pressed', 'true');
    stampState = 'model';
    renderStamp();
  });

  setTimeout(function () { map.invalidateSize(); }, 0);
  window.addEventListener('resize', function () { map.invalidateSize(); });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', katabaticInitMap);
} else {
  katabaticInitMap();
}

/* ---------- idioma ----------
   Termos de aviacao NAO sao traduzidos: ICAO, callsign, kt, nm, ft, fpm,
   ACARS, VATSIM, METAR e unidades permanecem iguais nos dois idiomas.
   O botao .lang, a persistencia e a aplicacao da traducao (data-i18n)
   sao genericos agora - ver lang-toggle.js, carregado depois deste
   script (por ultimo em base.html.twig) e que le este dicionario. */
window.KATABATIC_I18N_EN = {
    'nav.company': 'The company', 'nav.map': 'Map', 'nav.bases': 'Bases',
    'nav.fleet': 'Fleet', 'nav.ops': 'Operations', 'nav.pilot': 'Pilot area',
    'hero.badge': 'Virtual airline · MSFS 2024 · VATSIM',
    'hero.lead': 'We fly cargo and people between gravel, ice and wind — from the Alaskan interior to southern Patagonia. And we fly into the storms everyone else deviates around.',
    'hero.cta1': 'See the operation', 'hero.cta2': 'Fly with us',
    'board.pafa': 'Fairbanks, Alaska', 'board.scci': 'Punta Arenas, Chile',
    'board.wind': 'Wind', 'board.temp': 'Temp', 'board.vis': 'Visib.', 'board.ceil': 'Ceiling',
    'map.eyebrow': 'Network', 'map.title': 'Where we are right now',
    'map.lead': 'Two independent networks, 13,000 km apart. Aircraft appear here while the ACARS is transmitting.',
    'map.live': '2 airborne', 'map.radar': 'Radar', 'map.model': 'Model',
    'map.err.noLeaflet': 'Map unavailable — no connection to the Leaflet CDN.',
    'map.wx.calm': 'CALM', 'map.wx.gust': 'GUSTS', 'map.wx.ice': 'ICING', 'map.wx.fog': 'FOG', 'map.wx.storm': 'STORM',
    'map.popup.diff': 'expected difficulty',
    'map.radar.hiddenAbove': 'radar hidden above zoom',
    'map.radar.unavailable': 'radar unavailable',
    'map.model.updates': 'model · updates every 3h',
    'map.owm.prompt': 'OpenWeather API key (free at openweathermap.org/appid):',
    'lg.net': 'Network', 'lg.base': 'Base', 'lg.stn': 'Forward station',
    'lg.wx': 'Conditions at destination', 'lg.calm': 'Calm', 'lg.gust': 'Gusts',
    'lg.ice': 'Icing', 'lg.fog': 'Fog', 'lg.storm': 'Storm',
    'map.note': 'Base map from OpenStreetMap via CARTO. Radar (RainViewer) depends on ground stations — works well in Alaska, barely exists in Patagonia. Model (OpenWeather) covers the whole globe, including where radar does not reach. Detailed per-flight history comes from our own pipeline.',
    'about.eyebrow': 'The company',
    'about.title': 'Founded to reach what the airlines skip',
    'about.p1': 'Katabatic started in 2026 from a simple observation: <strong>almost every virtual airline flies between capital cities.</strong> We do the opposite — short strips, gravel, packed snow, downslope wind and low ceilings.',
    'about.p2': 'We run two operations in opposite hemispheres. When winter bites in Alaska, the hard season starts in Patagonia, and the fleet repositions. The company never leaves the difficult season.',
    'about.p3': 'Alongside transport we keep a line of <strong>weather research flights</strong>: legs planned to cross convective systems with instrumentation recording what the aircraft went through — turbulence, icing, gusts and visibility, second by second.',
    'about.noteTitle': 'New here?',
    'about.noteBody': 'Katabatic is a virtual airline. We do not move real cargo or people: every flight happens in Microsoft Flight Simulator 2024, with air traffic control provided by real people on the VATSIM network. The routes, the weather and the aircraft are real — the operation is simulated.',
    'fig.flights': 'Flights in the last 90 days', 'fig.vatsim': 'Flown on the VATSIM network',
    'fig.fleet': 'Aircraft in the fleet', 'fig.bases': 'Operating bases',
    'bases.eyebrow': 'Bases', 'bases.title': 'Two ends of the continent',
    'bases.lead': 'Each base has its own locally registered fleet, its mission profile and its hard season. Between them, 13,000 km and one repositioning flight per season.',
    'bases.north.tag': 'KBT North · N-registered',
    'bases.north.title': 'Fairbanks — interior and Arctic',
    'bases.north.body': 'The gateway to the Arctic. Supply runs to camps with no road access leave from here, along with the research legs above the Circle. The interior adds what the coast lacks: mountain passes, valley approaches and cold that degrades performance before anything else does.',
    'bases.stations': 'Forward stations',
    'bases.south.tag': 'KBT South · CC-registered',
    'bases.south.title': 'Punta Arenas — Magallanes and Patagonia',
    'bases.south.body': 'Support for research stations, fjord crossings and technical crew transport. This is where the katabatic wind behind our name really shows up: gusts rolling down the range that rewrite the approach in the last 500 feet.',
    'bases.field': 'Airfield', 'bases.dest': 'Regular destinations', 'bases.leg': 'Average leg', 'bases.based': 'Based aircraft',
    'cap.north': 'N208KB at Bettles',
    'fleet.eyebrow': 'Fleet', 'fleet.title': 'Six aircraft, two flags',
    'fleet.lead': 'Chilean registry in the south, US registry in the north. All turboprop, all able to work short unpaved strips.',
    'ops.eyebrow': 'Recent operations', 'ops.title': 'Every flight becomes a report',
    'ops.lead': 'Position, attitude, forces and conditions recorded every second by our ACARS. The difficulty index on the right comes from telemetry — not from the pilot’s opinion.',
    'ops.more': 'See the full record',
    'op.research': 'Research', 'op.cargo': 'Cargo', 'op.crew': 'Crew transport', 'op.ferry': 'Repositioning',
    'crew.eyebrow': 'Crew', 'crew.title': 'Entry by assessment',
    'crew.lead': 'Katabatic does not recruit in bulk. Three assessment flights under your own callsign, judged by the same data that produces the public reports — nobody is approved by eye, and nobody is rejected by opinion.',
    'crew.cta1': 'Start assessment', 'crew.cta2': 'Operations manual',
    'crew.c1': '<strong>Active VATSIM account.</strong> The assessment and every flight happen on the network.',
    'crew.c2': '<strong>Three legs with the ACARS.</strong> One at each base and one of your choosing.',
    'crew.c3': '<strong>No slew, no time acceleration.</strong> Detected automatically; invalidates the flight.',
    'crew.c4': '<strong>Touchdown within -400 fpm</strong> and no exceedance of the aircraft G limit.',
    'crew.c5': '<strong>A written mission report.</strong> What the sensors miss, you tell us.',
    'foot.contact': 'Contact', 'foot.privacy': 'Privacy',
    'foot.disclaimer': 'Katabatic is a virtual airline, run as a hobby in Microsoft Flight Simulator 2024 and on the VATSIM network. It is not a real airline, sells no tickets and carries no cargo or passengers. Airfields, registrations and routes are used in a simulation context only.'
};
