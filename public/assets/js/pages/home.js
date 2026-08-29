/* Katabatic — Home institucional: mapa (Leaflet) e alternancia PT/EN.
   Dicionario (I18N) continua mock/estatico. A malha de bases/estacoes
   (NET) tambem continua decorativa (coordenadas reais, mas sem dado ao
   vivo nenhum) - o que MUDOU e a aeronave em si: nao existe mais um
   "net.flight" inventado por painel, quem desenha agora e o bloco
   "posicoes ao vivo" mais abaixo, que busca de verdade em
   window.KATABATIC_HOME_POSICOES_URL (ver HomeController::mapaPosicoes(),
   mesma fonte que /mapa-ao-vivo usa, so que publica e so com ping real -
   ver docblock de liveFlightsPublicos()). */

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
   Radar: RainViewer, API publica, tambem sem chave.

   **Atualizado: 6 bases** (era só north/south, PAFA/SCCI) - mesma
   mudança que os outros lugares que citavam só essas duas já passaram
   (ver PortalController::bases()). Coordenadas de base/estação são
   aproximadas (uso decorativo, não é carta de navegação de verdade). */
var NET = {
  pafa: {
    center: [66.2, -150.5], zoom: 5,
    base: { icao: 'PAFA', name: 'Fairbanks', ll: [64.8151, -147.8564] },
    stations: [
      { icao: 'PABT', name: 'Bettles', ll: [66.9139, -151.5290], wx: 'calm', diff: 34 },
      { icao: 'PFYU', name: 'Fort Yukon', ll: [66.5715, -145.2503], wx: 'gust', diff: 58 },
      { icao: 'PAKP', name: 'Anaktuvuk Pass', ll: [68.1336, -151.7430], wx: 'ice', diff: 71 },
      { icao: 'PASC', name: 'Deadhorse', ll: [70.1947, -148.4652], wx: 'storm', diff: 82 },
      { icao: 'PAOT', name: 'Kotzebue', ll: [66.8847, -162.5985], wx: 'fog', diff: 63 }
    ]
  },
  scci: {
    center: [-51.5, -70.5], zoom: 5,
    base: { icao: 'SCCI', name: 'Punta Arenas', ll: [-53.0026, -70.8546] },
    stations: [
      { icao: 'SCNT', name: 'Puerto Natales', ll: [-51.6715, -72.5283], wx: 'gust', diff: 78 },
      { icao: 'SCGZ', name: 'Puerto Williams', ll: [-54.9311, -67.6262], wx: 'storm', diff: 85 },
      { icao: 'SCFM', name: 'Porvenir', ll: [-53.2537, -70.3193], wx: 'calm', diff: 29 },
      { icao: 'SCBA', name: 'Balmaceda', ll: [-45.9161, -71.6895], wx: 'fog', diff: 52 }
    ]
  },
  sllp: {
    center: [-17.5, -68.3], zoom: 6,
    base: { icao: 'SLLP', name: 'La Paz', ll: [-16.5133, -68.1925] },
    stations: [
      { icao: 'SLCN', name: 'Charaña', ll: [-17.5964, -69.4419], wx: 'gust', diff: 74 },
      { icao: 'SLVA', name: 'Sica Sica', ll: [-17.3667, -67.7333], wx: 'calm', diff: 41 },
      { icao: 'SLUY', name: 'Uyuni', ll: [-20.4488, -66.8258], wx: 'ice', diff: 66 }
    ]
  },
  vnkt: {
    center: [27.9, 85.1], zoom: 7,
    base: { icao: 'VNKT', name: 'Catmandu', ll: [27.6966, 85.3591] },
    stations: [
      { icao: 'VNLK', name: 'Lukla', ll: [27.6869, 86.7297], wx: 'fog', diff: 88 },
      { icao: 'VNJS', name: 'Jomsom', ll: [28.7806, 83.7256], wx: 'gust', diff: 76 },
      { icao: 'VNPK', name: 'Pokhara', ll: [28.2009, 83.9822], wx: 'calm', diff: 38 }
    ]
  },
  wajw: {
    center: [-3.9, 138.3], zoom: 7,
    base: { icao: 'WAJW', name: 'Wamena', ll: [-4.1025, 138.9575] },
    stations: [
      { icao: 'WAJB', name: 'Bokondini', ll: [-3.7167, 138.6667], wx: 'storm', diff: 79 },
      { icao: 'WAJM', name: 'Mulia', ll: [-3.6742, 137.9922], wx: 'fog', diff: 81 }
    ]
  },
  vqpr: {
    center: [27.35, 90.2], zoom: 7,
    base: { icao: 'VQPR', name: 'Paro', ll: [27.4032, 89.4245] },
    stations: [
      { icao: 'VQBT', name: 'Bumthang', ll: [27.5495, 90.7423], wx: 'calm', diff: 47 },
      { icao: 'VQTY', name: 'Trashigang', ll: [27.2856, 91.5061], wx: 'gust', diff: 69 },
      { icao: 'VQGP', name: 'Gelephu', ll: [26.8664, 90.4875], wx: 'fog', diff: 55 }
    ]
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

  /* **Atualizado: CARTO exige API key mesmo no plano gratuito** (mesma
     correção já aplicada em MapaAoVivoController/mapa-ao-vivo.js e em
     VooController::index() pro mapa do relatório de voo) - sem isso o
     tile vem com uma marca d'água "API KEY REQUIRED" por cima de tudo.
     window.KATABATIC_CARTO_API_KEY vem do env CARTO_API_KEY via Twig. */
  var CARTO_KEY = encodeURIComponent(window.KATABATIC_CARTO_API_KEY || '');
  var TILES = {
    light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png?key=' + CARTO_KEY,
    dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png?key=' + CARTO_KEY
  };
  var ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>';

  var map = L.map('map', {
    zoomControl: true, scrollWheelZoom: false, attributionControl: true,
    minZoom: 3, maxZoom: 15
  }).setView(NET.pafa.center, NET.pafa.zoom);

  function currentTheme() { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

  var base = L.tileLayer(TILES[currentTheme()], { attribution: ATTR, maxZoom: 15, detectRetina: true }).addTo(map);
  var overlay = L.layerGroup().addTo(map);
  var liveOverlay = L.layerGroup().addTo(map);

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
  // `categoria` (`'Helicoptero'`/`'Aviao'`, vindo de HomeController::
  // liveFlightsPublicos() → TipoAeronaveRepository::findCategoriasPorNome())
  // escolhe a forma do ícone; a rotação (hdg) já vem embutida no HTML aqui
  // (diferente de mapa-ao-vivo.js, que rotaciona via DOM depois de montar
  // o marcador) porque plane() é chamado de novo a cada atualização de
  // posição pra reconstruir o ícone inteiro.
  var HELI_PATH = '<rect x="3" y="5.2" width="18" height="1.6" rx="0.8"/><ellipse cx="12" cy="11" rx="2.8" ry="4.5"/><rect x="11.1" y="15" width="1.8" height="6.5" rx="0.9"/><rect x="9.3" y="20.3" width="5.4" height="1.4" rx="0.7"/>';
  var PLANE_PATH = '<path d="M12 2l7 18-7-4-7z"/>';
  function plane(hdg, categoria) {
    var inner = 'Helicoptero' === categoria ? HELI_PATH : PLANE_PATH;
    return L.divIcon({
      className: 'mk-ac',
      html: '<svg viewBox="0 0 24 24" style="transform:rotate(' + hdg + 'deg)" fill="currentColor">' + inner + '</svg>',
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

    map.flyTo(net.center, net.zoom, { duration: .8 });
  }

  draw('pafa');

  var paneSelect = document.getElementById('map-pane');
  if (paneSelect) {
    paneSelect.addEventListener('change', function () {
      draw(paneSelect.value);
    });
  }

  /* ---------- posições ao vivo (dado real) ----------
     Aeronaves realmente "Em voo" com ping real (ACARS) — ver
     HomeController::mapaPosicoes()/liveFlightsPublicos(). Desenhadas
     numa camada própria (liveOverlay), independente do painel
     selecionado acima: o seletor de base só reenquadra a câmera
     (map.flyTo em draw()), nunca filtra quem aparece — mesmo espírito
     do mapa interno (/mapa-ao-vivo), que também mostra a frota inteira
     de uma vez. Sem replay simulado aqui (ver docblock do backend):
     sem ping, a aeronave simplesmente não aparece. */
  var POLL_MS = 15000;
  var liveMarkers = {};

  function flyingPopup(fa) {
    var alt = (fa.altFt === null || fa.altFt === undefined) ? '—' : Math.round(fa.altFt).toLocaleString('pt-BR') + ' ft';
    var gs = (fa.gsKt === null || fa.gsKt === undefined) ? '—' : Math.round(fa.gsKt) + ' kt';
    return '<b>' + fa.callsign + '</b> ' + fa.reg + '<br><span>' + fa.origem + ' &rarr; ' + fa.destino + ' &middot; ' + alt + ' &middot; ' + gs + '</span>';
  }

  function renderLiveCount(n) {
    var el = document.getElementById('map-live-count');
    if (!el) return;
    if (n === 0) {
      el.textContent = tr('map.live.none', 'nenhum voo ao vivo agora');
    } else if (n === 1) {
      el.textContent = tr('map.live.one', '1 em voo agora');
    } else {
      el.textContent = n + ' ' + tr('map.live.many', 'em voo agora');
    }
  }

  function renderFoot(list) {
    var footEl = document.getElementById('map-foot-flight');
    if (!footEl) return;
    if (!list.length) {
      footEl.innerHTML = tr('map.foot.none', 'Nenhuma aeronave transmitindo posição agora.');
      return;
    }
    var fa = list[0];
    footEl.innerHTML = '<b>' + fa.callsign + '</b> ' + fa.origem + ' &rarr; ' + fa.destino +
      (list.length > 1 ? ' &middot; +' + (list.length - 1) + ' ' + tr('map.foot.more', 'outra(s)') : '');
  }

  var lastLiveList = [];
  document.addEventListener('katabatic:langchange', function () {
    renderLiveCount(lastLiveList.length);
    renderFoot(lastLiveList);
  });

  function applyLivePositions(list) {
    lastLiveList = list || [];
    renderLiveCount(lastLiveList.length);
    renderFoot(lastLiveList);

    var seen = {};
    lastLiveList.forEach(function (fa) {
      seen[fa.reg] = true;
      var ll = [fa.lat, fa.lon];
      var existing = liveMarkers[fa.reg];
      if (existing) {
        existing.setLatLng(ll);
        existing.setIcon(plane(fa.hdgTrue || 0, fa.categoria));
        existing.setPopupContent(flyingPopup(fa));
      } else {
        var marker = L.marker(ll, { icon: plane(fa.hdgTrue || 0, fa.categoria) }).bindPopup(flyingPopup(fa));
        marker.addTo(liveOverlay);
        liveMarkers[fa.reg] = marker;
      }
    });

    Object.keys(liveMarkers).forEach(function (reg) {
      if (!seen[reg]) {
        liveOverlay.removeLayer(liveMarkers[reg]);
        delete liveMarkers[reg];
      }
    });
  }

  var posicoesUrl = window.KATABATIC_HOME_POSICOES_URL || '/mapa-inicio/posicoes';
  function pollLivePositions() {
    fetch(posicoesUrl).then(function (r) { return r.ok ? r.json() : []; })
      .then(applyLivePositions)
      .catch(function () { /* melhor esforço - mantém a última lista conhecida */ });
  }
  pollLivePositions();
  setInterval(pollLivePositions, POLL_MS);

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

  var radar = null;

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
    'hero.lead': 'We fly cargo and people between gravel, ice and wind — from the Alaskan interior to the Himalaya, by way of the Andes, Patagonia and the Papua highlands. And we fly into the storms everyone else deviates around.',
    'hero.cta1': 'See the operation', 'hero.cta2': 'Fly with us',
    'board.pafa': 'Fairbanks, Alaska', 'board.scci': 'Punta Arenas, Chile',
    'board.sllp': 'La Paz, Bolivia', 'board.vnkt': 'Kathmandu, Nepal',
    'board.wajw': 'Wamena, New Guinea', 'board.vqpr': 'Paro, Bhutan',
    'board.metarHint': 'Most recent METAR',
    'board.metarUnavailable': 'METAR unavailable',
    'map.eyebrow': 'Network', 'map.title': 'Where we are right now',
    'map.lead': 'Six bases spread across the planet. Aircraft appear here while the ACARS is transmitting.',
    'map.live.none': 'no aircraft airborne now', 'map.live.one': '1 airborne now', 'map.live.many': 'airborne now',
    'map.foot.none': 'No aircraft transmitting position right now.', 'map.foot.more': 'more',
    'map.radar': 'Radar', 'map.model': 'Model',
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
    'about.eyebrow': 'The company',
    'about.title': 'Founded to reach what the airlines skip',
    'about.p1': 'Katabatic started in 2026 from a simple observation: <strong>almost every virtual airline flies between capital cities.</strong> We do the opposite — short strips, gravel, packed snow, downslope wind and low ceilings.',
    'about.p2': 'We run six bases at the extremes of the planet — from the Alaskan interior to the Himalaya, by way of the Andes, Patagonia and the Papua highlands. PAFA and SCCI, the original two, still swap with each hemisphere\'s winter and reposition part of the fleet between them; the four newer ones fly their own hard season year-round, with no hemisphere swap to pause on.',
    'about.p3': 'Alongside transport we keep a line of <strong>weather research flights</strong>: legs planned to cross convective systems with instrumentation recording what the aircraft went through — turbulence, icing, gusts and visibility, second by second.',
    'about.noteTitle': 'New here?',
    'about.noteBody': 'Katabatic is a virtual airline. We do not move real cargo or people: every flight happens in Microsoft Flight Simulator 2024, with air traffic control provided by real people on the VATSIM network. The routes, the weather and the aircraft are real — the operation is simulated.',
    'fig.flights': 'Flights in the last 90 days', 'fig.vatsim': 'Flown on the VATSIM network',
    'fig.fleet': 'Aircraft in the fleet', 'fig.bases': 'Operating bases',
    'bases.eyebrow': 'Bases', 'bases.title': 'Six bases at the extremes of the planet',
    'bases.lead': 'Each base has its own locally registered fleet, its mission profile and its hard season — some swap hemispheres every season, others run their own difficulty year-round.',
    'bases.pafa.tag': 'KBT North · N-registered',
    'bases.pafa.title': 'Fairbanks — interior and Arctic',
    'bases.pafa.body': 'The gateway to the Arctic. Supply runs to camps with no road access leave from here, along with the research legs above the Circle. The interior adds what the coast lacks: mountain passes, valley approaches and cold that degrades performance before anything else does.',
    'bases.stations': 'Forward stations',
    'bases.scci.tag': 'KBT South · CC-registered',
    'bases.scci.title': 'Punta Arenas — Magallanes and Patagonia',
    'bases.scci.body': 'Support for research stations, fjord crossings and technical crew transport. This is where the katabatic wind behind our name really shows up: gusts rolling down the range that rewrite the approach in the last 500 feet.',
    'bases.sllp.tag': 'KBT Andes · CP-registered',
    'bases.sllp.title': 'La Paz — the roof of the Altiplano',
    'bases.sllp.body': 'Altitude here isn\'t scenery — it\'s the operation. At almost 4,100 m, thin air shortens the effective runway and rewrites V-speeds on nearly every landing. Serves the Bolivian Altiplano with the Illimani always on the horizon, where the performance margin starts out thin.',
    'bases.vnkt.tag': 'KBT Himalaya · 9N-registered',
    'bases.vnkt.title': 'Kathmandu — gateway to the Himalaya',
    'bases.vnkt.body': 'The real hub for the region\'s high-altitude strips: Lukla, Jomsom and Pokhara operate out of here, never the other way around. Valley approaches with no go-around option on most neighboring strips make weather judgment weigh as much as flying skill.',
    'bases.wajw.tag': 'KBT Papua · PK-registered',
    'bases.wajw.title': 'Wamena — the walled-in valley',
    'bases.wajw.body': 'The Baliem Valley sits boxed in by peaks over 4,000 m — no road in from the outside, only air. Visual approach is the rule, not the exception, and weather closes in fast enough to become the flight\'s deciding factor, not just background noise.',
    'bases.vqpr.tag': 'KBT Himalaya · Bhutan · A5-registered',
    'bases.vqpr.title': 'Paro — the tightest approach in the world',
    'bases.vqpr.body': 'The Paro valley is ringed by peaks up to 5,500 m, and no instrument approach covers it — it\'s a visual maneuver between slopes, with the wind setting the final course turn by turn. One of the most respected operations in the sim, for good reason.',
    'bases.field': 'Airfield', 'bases.dest': 'Regular destinations', 'bases.leg': 'Average leg', 'bases.based': 'Based aircraft',
    'cap.pafa': 'N208KB at Bettles',
    'fleet.eyebrow': 'Fleet', 'fleet.title': 'One fleet, six flags',
    'fleet.lead': 'The types we actually fly — one row per model, drawn straight from the fleet roster. All turboprop, all able to work short unpaved strips.',
    'ops.eyebrow': 'Recent operations', 'ops.title': 'Every flight becomes a report',
    'ops.lead': 'Position, attitude, forces and conditions recorded every second by our ACARS. The difficulty index on the right comes from telemetry — not from the pilot’s opinion.',
    'ops.more': 'See the full record',
    'ops.empty': 'No flight with recorded telemetry yet — as soon as the first ACARS session closes, it shows up here.',
    'op.research': 'Research', 'op.cargo': 'Cargo', 'op.crew': 'Crew transport', 'op.ferry': 'Repositioning', 'op.medevac': 'Medevac',
    'crew.eyebrow': 'Crew', 'crew.title': 'Entry by assessment',
    'crew.lead': 'Katabatic does not recruit in bulk. Three assessment flights under your own callsign, judged by the same data that produces the public reports — nobody is approved by eye, and nobody is rejected by opinion.',
    'crew.cta1': 'Start assessment', 'crew.cta2': 'Operations manual',
    'crew.c1': '<strong>Active VATSIM account.</strong> The assessment and every flight happen on the network.',
    'crew.c2': '<strong>Three legs with the ACARS.</strong> At any of our six bases, your choice.',
    'crew.c3': '<strong>No slew, no time acceleration.</strong> Detected automatically; invalidates the flight.',
    'crew.c4': '<strong>Touchdown within -400 fpm</strong> and no exceedance of the aircraft G limit.',
    'crew.c5': '<strong>A written mission report.</strong> What the sensors miss, you tell us.',
    'foot.contact': 'Contact', 'foot.privacy': 'Privacy',
    'foot.disclaimer': 'Katabatic is a virtual airline, run as a hobby in Microsoft Flight Simulator 2024 and on the VATSIM network. It is not a real airline, sells no tickets and carries no cargo or passengers. Airfields, registrations and routes are used in a simulation context only.'
};
