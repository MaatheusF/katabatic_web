/**
 * Área científica de um voo de Pesquisa — mapa com a rota voada, replay
 * das capturas de mapa/radar armazenadas (uma imagem georreferenciada
 * por captura, sobreposta ao mapa base — em dois modos alternáveis pelo
 * botão .pc-imgmode: "por leitura", só a imagem da captura selecionada
 * na timeline, ou "mosaico contínuo", todas as imagens de uma vez, cada
 * uma na sua posição real) e vento animado por altitude (leaflet-
 * velocity, alimentado pela grade de vento salva em cada captura).
 * Narrativa/destaques/estatísticas/indicadores de telemetria do
 * relatório já vêm prontos do servidor (Twig) — este arquivo cuida do
 * mapa E dos gráficos de evolução (vento/rajada, temperatura,
 * precipitação) montados a partir de `pesquisa.amostras`, com os
 * pontos de máximo/mínimo de cada um marcados no mapa como eventos.
 *
 * Dados vêm embutidos pelo template (ver pesquisa_cientifica/index.html.twig):
 * window.KATABATIC_PESQUISA = { amostras, resumo, capturas, relatorio }
 * window.KATABATIC_TRACK = [[t_s, lat, lon], ...]
 * window.KATABATIC_TELEMETRIA = telemetria REAL da aeronave (MSFS, via
 *   TelemetryDeriver) ou null — usada aqui só pra marcar no mapa os
 *   eventos de gelo real (`events` tipo `icing_onset`, correlacionados
 *   com TRACK por tempo); os indicadores numéricos já vêm prontos do
 *   servidor (relatorio.indicadoresTelemetria).
 * window.KATABATIC_CARTO_API_KEY, window.KATABATIC_VOO_STARTED_AT
 */
(function () {
  var PESQUISA = window.KATABATIC_PESQUISA || null;
  var TRACK = window.KATABATIC_TRACK || [];
  var TELEMETRIA = window.KATABATIC_TELEMETRIA || null;
  if (!PESQUISA || !document.getElementById('pc-map')) return;

  var MAP, ROUTE_LAYER, CAPTURE_MARKERS = L.layerGroup(), IMAGE_LAYERS = L.layerGroup(), WIND_LAYERS = L.layerGroup(), EVENTOS_LAYER = L.layerGroup(), LEGENDA_VENTO = null;
  var CAPTURAS = (PESQUISA.capturas || []).slice().sort(function (a, b) { return new Date(a.em) - new Date(b.em); });
  var AMOSTRAS = (PESQUISA.amostras || []).slice().sort(function (a, b) { return new Date(a.em) - new Date(b.em); });
  var SELECIONADA = null;
  // 'captura' = mostra só a imagem composta da leitura selecionada na
  // timeline (padrão, comportamento de sempre). 'mosaico' = mostra a
  // imagem de TODAS as leituras ao mesmo tempo, cada uma na sua posição
  // geográfica real — pedido em conversa: "dá pra fazer os dois com um
  // botão pra alternar entre o mosaico contínuo e por leituras?".
  var MODO_MAPA = 'captura';

  // Teto da escala de cor/intensidade do vento animado, em m/s — era um
  // valor FIXO (40 m/s ≈ 78 kt), que dava bom contraste no dia a dia mas
  // ACHATAVA qualquer vento acima disso na mesma cor mais quente da
  // escala (jato forte de verdade, >80 kt, ficava indistinguível de
  // 78 kt) — pedido em conversa: "em uma condição extrema os ventos vão
  // passar de 80kt, precisamos aumentar o máximo possível para atender
  // qualquer condição e não ficar limitado no indicador". Agora é
  // ADAPTATIVO: recalculado a cada atualizarVento() a partir do maior
  // vento REALMENTE presente nos dados exibidos no momento (+15% de
  // folga, pra o pico não cair bem na cor mais quente da escala), com
  // piso (mantém contraste legível em dia calmo) e teto (só um limite de
  // sanidade — o jato mais forte já registrado na atmosfera terrestre
  // gira em torno de 115 m/s/~225 kt, então 120 m/s cobre qualquer
  // condição real sem depender de um valor arbitrário pequeno).
  var VENTO_MAX_MS_PISO = 20;
  var VENTO_MAX_MS_TETO = 120;
  var VENTO_MAX_MS = 40; // recalculado em atualizarVento() antes de cada uso; valor inicial só de fallback.
  var MS_TO_KT = 1 / 0.514444;
  // Mesma paleta que o leaflet-velocity usa por padrão internamente pra
  // colorir as partículas (0 a VENTO_MAX_MS) — replicada aqui só pra
  // desenhar a legenda com as MESMAS cores que aparecem no mapa.
  var VENTO_CORES = ['rgb(36,104,180)', 'rgb(60,157,194)', 'rgb(128,205,193)', 'rgb(151,218,168)', 'rgb(198,231,181)', 'rgb(238,247,217)', 'rgb(255,238,159)', 'rgb(252,217,125)', 'rgb(255,182,100)', 'rgb(252,150,75)', 'rgb(250,112,52)', 'rgb(245,64,32)', 'rgb(237,45,28)', 'rgb(220,24,32)', 'rgb(180,0,35)'];

  function initMap() {
    MAP = L.map('pc-map', { zoomControl: true });

    // Panes próprios pra precipitação e vento terem uma ordem de
    // empilhamento FIXA entre si, independente de qual dos dois foi
    // ligado/atualizado por último — antes a imagem de precipitação
    // usava um zIndex numérico direto no L.imageOverlay (z-index
    // positivo sempre vence um canvas sem z-index explícito, como o do
    // leaflet-velocity), então a precipitação sempre cobria o vento por
    // cima, mesmo tendo sido pedido pra não sobrepor. Pedido em
    // conversa: "ele está sobrepondo o vento". overlayPane padrão do
    // Leaflet fica em z-index 400 — precipitação um pouco abaixo,
    // vento acima, pra o vento animado sempre ficar visível por cima
    // do mapa de fundo.
    MAP.createPane('pcPrecipPane');
    MAP.getPane('pcPrecipPane').style.zIndex = 350;
    MAP.createPane('pcVentoPane');
    MAP.getPane('pcVentoPane').style.zIndex = 450;
    // Marcadores de evento (máx/mín dos gráficos, gelo real) sempre por
    // cima de tudo — nunca escondidos atrás do vento/precipitação.
    MAP.createPane('pcEventosPane');
    MAP.getPane('pcEventosPane').style.zIndex = 460;

    var cartoKey = encodeURIComponent(window.KATABATIC_CARTO_API_KEY || '');
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png?key=' + cartoKey, {
      attribution: '&copy; OpenStreetMap &copy; CARTO',
      maxZoom: 12,
      detectRetina: true
    }).addTo(MAP);

    if (TRACK.length > 1) {
      var latlngs = TRACK.map(function (p) { return [p[1], p[2]]; }).filter(function (p) { return isFinite(p[0]) && isFinite(p[1]); });
      if (latlngs.length > 1) {
        ROUTE_LAYER = L.polyline(latlngs, { color: '#5aa9ff', weight: 3, opacity: .85 }).addTo(MAP);
        MAP.fitBounds(ROUTE_LAYER.getBounds(), { padding: [24, 24] });
      }
    }
    if (!ROUTE_LAYER && CAPTURAS.length) {
      MAP.setView([CAPTURAS[0].lat, CAPTURAS[0].lon], 7);
    } else if (!ROUTE_LAYER) {
      MAP.setView([0, 0], 2);
    }

    CAPTURE_MARKERS.addTo(MAP);
    IMAGE_LAYERS.addTo(MAP);
    WIND_LAYERS.addTo(MAP);
    EVENTOS_LAYER.addTo(MAP);
    CAPTURAS.forEach(function (c, idx) {
      var marker = L.circleMarker([c.lat, c.lon], { radius: 5, color: '#ff8c00', weight: 2, fillColor: '#ff8c00', fillOpacity: .6 });
      marker.on('click', function () { selecionar(idx); });
      marker.bindTooltip(new Date(c.em).toUTCString());
      marker.addTo(CAPTURE_MARKERS);
    });

    MAP.on('click', onMapClickVento);
  }

  /* Nível de pressão a usar pra UMA captura específica: com "seguir
     altitude" ligado, cada captura usa o nível mais próximo da PRÓPRIA
     altitude registrada (a aeronave pode ter voado em altitudes
     diferentes ao longo da missão); senão, todas usam o mesmo nível
     escolhido no seletor. Compartilhado entre o vento animado e o
     clique no mapa, pra nunca mostrarem níveis diferentes um do outro. */
  function nivelParaCaptura(c, nivelGlobal) {
    var niveis = c.vento && c.vento.niveisHpa;
    if (document.getElementById('pc-follow-alt').checked && c.altFt != null && niveis && niveis.length) {
      return String(nivelMaisProximo(niveis, hpaDeAltitude(c.altFt)));
    }
    return nivelGlobal;
  }

  /* Capturas cujo vento/imagem deve aparecer no mapa agora: só a
     selecionada no modo "por leitura", todas no "mosaico contínuo" —
     pedido em conversa: o mosaico contínuo tem que exibir tudo de uma
     vez, vento incluso (antes só a imagem respeitava o modo; o vento
     animado sempre mostrava uma única leitura, mesmo no mosaico). */
  function capturasVisiveis() {
    if ('mosaico' === MODO_MAPA) return CAPTURAS;
    return SELECIONADA ? [SELECIONADA] : [];
  }

  /* Clique no mapa, com o vento animado ligado, mostra num popup a
     velocidade/direção do vento do PONTO DE GRADE MAIS PRÓXIMO do clique,
     procurando em TODAS as capturas visíveis no momento (uma só no modo
     "por leitura", todas no mosaico) — não uma interpolação pixel-a-
     pixel, dado que já vem calculado e validado do servidor
     (OpenMeteoClient::gradeVento()), então não há matemática de
     direção/convenção pra reproduzir e arriscar errar aqui no front.
     Pedido em conversa: "poder clicar no mapa e exibir um label com a
     velocidade relativa do vento". */
  function onMapClickVento(e) {
    if (!document.getElementById('pc-vento-toggle').checked) return;
    var nivelGlobal = document.getElementById('pc-nivel').value;

    var melhor = null, melhorDist = Infinity, melhorNivel = null;
    capturasVisiveis().forEach(function (c) {
      if (!c.vento) return;
      var nivel = nivelParaCaptura(c, nivelGlobal);
      if (!nivel) return;
      var achado = pontoVentoMaisProximo(c.vento, nivel, e.latlng.lat, e.latlng.lng);
      if (achado && achado.dist < melhorDist) { melhorDist = achado.dist; melhor = achado.valor; melhorNivel = nivel; }
    });
    if (!melhor) return;

    L.popup({ maxWidth: 220 })
      .setLatLng(e.latlng)
      .setContent(
        '<b>Vento · ' + melhorNivel + ' hPa</b><br>'
        + melhor.speedKt.toFixed(1) + ' kt · ' + melhor.dirDeg + '°'
        + '<br><span style="opacity:.65;font-size:11px">ponto de grade mais próximo</span>'
      )
      .openOn(MAP);
  }

  /**
   * @return {{valor: {speedKt:number, dirDeg:number}, dist: number}|null}
   */
  function pontoVentoMaisProximo(vento, nivel, lat, lon) {
    var pontos = (vento.pontos || []).filter(function (p) { return p.niveis && p.niveis[nivel]; });
    if (!pontos.length) return null;

    var melhor = null, melhorDist = Infinity;
    pontos.forEach(function (p) {
      var d = Math.pow(p.lat - lat, 2) + Math.pow(p.lon - lon, 2);
      if (d < melhorDist) { melhorDist = d; melhor = p; }
    });

    return melhor ? { valor: melhor.niveis[nivel], dist: melhorDist } : null;
  }

  /* ---------- timeline de miniaturas ---------- */
  function buildTimeline() {
    var box = document.getElementById('pc-timeline');
    var empty = document.getElementById('pc-timeline-empty');
    if (!CAPTURAS.length) { if (empty) empty.hidden = false; return; }
    if (empty) empty.hidden = true;

    CAPTURAS.forEach(function (c, idx) {
      var el = document.createElement('div');
      el.className = 'pc-thumb';
      el.dataset.idx = String(idx);
      var img = (c.mapa && c.mapa.imagemComposta) ? c.mapa.imagemComposta : null;
      if (img) {
        var i = document.createElement('img');
        i.src = img;
        i.alt = '';
        el.appendChild(i);
      } else {
        var ph = document.createElement('div');
        ph.className = 'pc-thumb-noimg';
        ph.textContent = 'sem imagem';
        ph.title = 'Esta captura não tem imagem de mapa composta. Provável causa: a extensão GD do PHP não está habilitada no servidor (necessária para compor os tiles em JPEG).';
        el.appendChild(ph);
      }
      var t = document.createElement('span');
      t.className = 't';
      var d = new Date(c.em);
      t.textContent = d.getUTCHours().toString().padStart(2, '0') + ':' + d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
      el.appendChild(t);
      el.addEventListener('click', function () { selecionar(idx); MAP.panTo([c.lat, c.lon]); });
      box.appendChild(el);
    });
  }

  /* ---------- seleção de captura ---------- */
  function selecionar(idx) {
    if (idx < 0 || idx >= CAPTURAS.length) return;
    SELECIONADA = CAPTURAS[idx];

    document.querySelectorAll('.pc-thumb').forEach(function (el) {
      el.classList.toggle('on', el.dataset.idx === String(idx));
    });

    atualizarImagemMapa();
    atualizarSeletorNivel();
    atualizarVento();
  }

  /* Imagem de mapa/radar da captura — prefere a versão em resolução
     maior feita pro overlay do mapa grande (imagemMapa); cai pra
     imagemComposta (a miniatura) só em capturas antigas gravadas antes
     dessa mudança, que não têm o campo novo. */
  function imagemMapaDe(c) {
    return (c.mapa && (c.mapa.imagemMapa || c.mapa.imagemComposta)) || null;
  }

  function atualizarImagemMapa() {
    IMAGE_LAYERS.clearLayers();
    // Precipitação e vento animado sobrepostos ao mesmo tempo dificultam
    // ler qualquer um dos dois — pedido em conversa: "precisamos poder
    // alterar entre o mapa de precipitação e vento". Toggle próprio,
    // independente do de vento.
    if (!document.getElementById('pc-precip-toggle').checked) return;

    if ('mosaico' === MODO_MAPA) {
      // Cada captura mostra só a FATIA da própria imagem mais perto
      // dela do que das capturas vizinhas na timeline (partição tipo
      // Voronoi simples, usando só os 2 vizinhos imediatos, eixo
      // dominante lat OU lon) — antes cada captura desenhava a grade
      // INTEIRA configurada (ex.: 11×11 tiles com raio 5), e como a
      // aeronave normalmente não anda o raio inteiro entre uma captura
      // e a próxima, as imagens ficavam quase totalmente sobrepostas,
      // uma jogada em cima da outra. Pedido em conversa: "construir os
      // tiles de acordo com o avanço da aeronave".
      CAPTURAS.forEach(function (c, idx) {
        var img = imagemMapaDe(c);
        if (!img) return;
        var b = boundsFromManifest(c.mapa);
        if (!b) return;
        var overlay = L.imageOverlay(img, b, { opacity: .85, pane: 'pcPrecipPane' }).addTo(IMAGE_LAYERS);
        var el = overlay.getElement();
        if (el) el.style.clipPath = clipMosaico(c, CAPTURAS[idx - 1], CAPTURAS[idx + 1], b);
      });
      return;
    }

    if (!SELECIONADA) return;
    var img = imagemMapaDe(SELECIONADA);
    if (!img) return;
    var bounds = boundsFromManifest(SELECIONADA.mapa);
    if (bounds) L.imageOverlay(img, bounds, { opacity: .85, pane: 'pcPrecipPane' }).addTo(IMAGE_LAYERS);
  }

  /* Troca entre os dois modos de exibição da imagem — só refaz o layout
     do mapa (fitBounds) quando ENTRA no mosaico, pra dar uma visão geral
     de tudo de uma vez; trocar a leitura selecionada dentro do modo
     "por leitura" nunca mexe no zoom/posição do mapa (evita o mapa ficar
     pulando a cada clique na timeline). */
  function alternarModoMapa(modo) {
    if (modo === MODO_MAPA) return;
    MODO_MAPA = modo;

    document.querySelectorAll('.pc-imgmode button').forEach(function (btn) {
      btn.setAttribute('aria-pressed', btn.dataset.modo === modo ? 'true' : 'false');
    });

    atualizarImagemMapa();
    atualizarVento();

    if ('mosaico' === modo) {
      // Os limites geográficos vêm só do manifesto (zoom/grade/centro),
      // não da imagem em si — antes exigia imagemMapaDe(c) pra contar a
      // captura, então sem imagem salva (toggle de precipitação
      // desligado, ou GD recém-habilitado sem imagem nas capturas
      // antigas) o mosaico não reenquadrava o mapa nenhuma, mesmo tendo
      // vento pra mostrar.
      var total = null;
      CAPTURAS.forEach(function (c) {
        if (!c.mapa) return;
        var b = boundsFromManifest(c.mapa);
        if (!b) return;
        total = total ? total.extend(b) : b;
      });
      if (total) MAP.fitBounds(total, { padding: [24, 24] });
    }
  }

  /* Converte o manifesto da captura (grade de tiles z/x/y, ver
     PesquisaMapaCaptador) nos limites geográficos exatos da imagem
     composta armazenada — mesma matemática de tile slippy-map que o
     servidor usou pra baixar os tiles, só que invertida (tile -> lat/lon). */
  function boundsFromManifest(manifesto) {
    var z = manifesto.zoom, grid = manifesto.grid, cx = manifesto.centroX, cy = manifesto.centroY;
    if (z == null || grid == null || cx == null || cy == null) return null;
    var offset = Math.floor(grid / 2);
    var xW = cx - offset, xE = xW + grid;
    var yN = cy - offset, yS = yN + grid;
    return L.latLngBounds(
      [tile2lat(yS, z), tile2lon(xW, z)],
      [tile2lat(yN, z), tile2lon(xE, z)]
    );
  }
  /* Recorte (CSS clip-path, em % da própria caixa da imagem — vale em
     qualquer zoom/pan do mapa, não precisa recalcular ao navegar) pra
     UMA captura no mosaico contínuo: corta o(s) lado(s) voltado(s) pro(s)
     vizinho(s) imediato(s) na timeline até a linha do meio entre os
     centros — resultado, cada captura só mostra a área mais perto dela
     do que do vizinho, em vez do grid inteiro se sobrepondo. */
  function clipMosaico(c, anterior, seguinte, bounds) {
    var insets = { top: 0, right: 0, bottom: 0, left: 0 };
    if (anterior) cortarRumoAoVizinho(insets, bounds, c.lat, c.lon, anterior.lat, anterior.lon);
    if (seguinte) cortarRumoAoVizinho(insets, bounds, c.lat, c.lon, seguinte.lat, seguinte.lon);
    return 'inset(' + insets.top.toFixed(2) + '% ' + insets.right.toFixed(2) + '% ' + insets.bottom.toFixed(2) + '% ' + insets.left.toFixed(2) + '%)';
  }

  function cortarRumoAoVizinho(insets, bounds, lat, lon, vizLat, vizLon) {
    var dLon = vizLon - lon, dLat = vizLat - lat;
    if (Math.abs(dLon) >= Math.abs(dLat)) {
      var west = bounds.getWest(), east = bounds.getEast();
      var frac = clamp01((((vizLon + lon) / 2) - west) / (east - west));
      if (dLon > 0) insets.right = Math.max(insets.right, (1 - frac) * 100);
      else insets.left = Math.max(insets.left, frac * 100);
    } else {
      var north = bounds.getNorth(), south = bounds.getSouth();
      var fracTop = clamp01((north - ((vizLat + lat) / 2)) / (north - south));
      if (dLat > 0) insets.top = Math.max(insets.top, fracTop * 100);
      else insets.bottom = Math.max(insets.bottom, (1 - fracTop) * 100);
    }
  }

  function clamp01(v) { return Math.max(0, Math.min(1, v)); }

  function tile2lon(x, z) { return x / Math.pow(2, z) * 360 - 180; }
  function tile2lat(y, z) {
    var n = Math.PI - 2 * Math.PI * y / Math.pow(2, z);
    return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
  }

  /* ---------- seletor de nível de pressão ---------- */
  function atualizarSeletorNivel() {
    var sel = document.getElementById('pc-nivel');
    var niveis = (SELECIONADA && SELECIONADA.vento && SELECIONADA.vento.niveisHpa) || [];
    sel.innerHTML = '';
    if (!niveis.length) { sel.disabled = true; return; }
    sel.disabled = false;
    niveis.forEach(function (n) {
      var opt = document.createElement('option');
      opt.value = String(n);
      opt.textContent = n + ' hPa';
      sel.appendChild(opt);
    });
    if (document.getElementById('pc-follow-alt').checked && SELECIONADA.altFt != null) {
      sel.value = String(nivelMaisProximo(niveis, hpaDeAltitude(SELECIONADA.altFt)));
    }
  }

  /* Atmosfera padrão (ISA) — aproximação, o bastante pra escolher o
     nível de pressão mais próximo da altitude real da aeronave, não
     pra navegação. */
  function hpaDeAltitude(altFt) {
    return 1013.25 * Math.pow(1 - altFt / 145366, 1 / 0.190284);
  }
  function nivelMaisProximo(niveis, alvoHpa) {
    return niveis.reduce(function (melhor, n) {
      return Math.abs(n - alvoHpa) < Math.abs(melhor - alvoHpa) ? n : melhor;
    }, niveis[0]);
  }

  /* Maior vento (m/s) realmente presente nas capturas/nível exibidos
     agora, com 15% de folga (senão o próprio pico cairia bem na última
     cor da escala, indistinguível de qualquer coisa acima dele) e
     limitado a [VENTO_MAX_MS_PISO, VENTO_MAX_MS_TETO]. */
  function ventoMaxObservadoMs(capturas, nivelGlobal) {
    var maxKt = 0;
    capturas.forEach(function (c) {
      if (!c.vento) return;
      var nivel = nivelParaCaptura(c, nivelGlobal);
      (c.vento.pontos || []).forEach(function (p) {
        var v = p.niveis && p.niveis[nivel];
        if (v && v.speedKt > maxKt) maxKt = v.speedKt;
      });
    });
    var maxMs = (maxKt / MS_TO_KT) * 1.15;
    return Math.min(VENTO_MAX_MS_TETO, Math.max(VENTO_MAX_MS_PISO, maxMs));
  }

  /* ---------- vento animado (leaflet-velocity) ---------- */
  /* No modo "mosaico contínuo" mostra o vento de TODAS as capturas
     visíveis de uma vez — uma camada leaflet-velocity por captura, cada
     uma só na sua própria grade/posição geográfica, igual já acontecia
     com as imagens. Antes o vento animado sempre mostrava só a leitura
     SELECIONADA, mesmo no mosaico, então virava "1 remendo de vento só"
     no meio do mapa em vez de cobrir a missão toda — pedido em conversa:
     "o mosaico contínuo não exibiu todos os tiles, apenas 1 é exibido". */
  function atualizarVento() {
    WIND_LAYERS.clearLayers();
    if (LEGENDA_VENTO) { MAP.removeControl(LEGENDA_VENTO); LEGENDA_VENTO = null; }
    if (!document.getElementById('pc-vento-toggle').checked) return;
    if (typeof L.velocityLayer !== 'function') return;

    var nivelGlobal = document.getElementById('pc-nivel').value;
    if (!nivelGlobal) return;

    var alvos = capturasVisiveis();
    // Escala de cor recalculada pro que está sendo exibido AGORA (ver
    // comentário de VENTO_MAX_MS_PISO/TETO acima) — assim uma condição
    // extrema nunca fica achatada na cor mais quente, e um dia calmo não
    // fica sem contraste nenhum por causa de uma escala grande demais.
    VENTO_MAX_MS = ventoMaxObservadoMs(alvos, nivelGlobal);

    var algumaCamada = false;
    alvos.forEach(function (c) {
      if (!c.vento) return;
      var nivel = nivelParaCaptura(c, nivelGlobal);
      var data = buildVelocityData(c.vento, nivel);
      if (!data) return;

      L.velocityLayer({
        // Só a captura selecionada mostra a caixa de "velocidade sob o
        // mouse" (displayValues) — com várias camadas ao mesmo tempo no
        // mosaico, cada uma criaria sua própria caixa, empilhando várias
        // umas por cima das outras no canto do mapa.
        displayValues: c === SELECIONADA,
        displayOptions: {
          velocityType: 'Vento', position: 'bottomleft',
          emptyString: 'passe o mouse sobre o mapa para ver o vento',
          speedUnit: 'kt', showCardinal: true
        },
        // Pane próprio, acima do da precipitação (ver initMap) — o vento
        // animado tem que ficar sempre visível por cima do mapa de
        // fundo, nunca coberto pela imagem estática de precipitação.
        paneName: 'pcVentoPane',
        data: data,
        minVelocity: 0,
        maxVelocity: VENTO_MAX_MS,
        // Era 0.01 (o DOBRO do default da própria biblioteca, 0.005) — sem
        // motivo documentado, resultado ficava com aparência irreal/rápida
        // demais. Voltado pro default oficial do leaflet-velocity.
        velocityScale: 0.005,
        opacity: 0.9
      }).addTo(WIND_LAYERS);
      algumaCamada = true;
    });

    if (algumaCamada) {
      LEGENDA_VENTO = criarLegendaVento();
      LEGENDA_VENTO.addTo(MAP);
    }
  }

  /* Legenda de cores do vento animado — o leaflet-velocity não desenha
     nenhuma por conta própria (só o texto de velocidade/direção sob o
     mouse, via displayValues). Pedido em conversa: "seria importante ter
     uma legenda com as cores". Gradiente construído com a MESMA paleta e
     a mesma faixa (0..VENTO_MAX_MS) que a biblioteca usa internamente
     pra colorir as partículas, senão a legenda mentiria sobre as cores
     reais do mapa. */
  function criarLegendaVento() {
    var Legenda = L.Control.extend({
      options: { position: 'bottomright' },
      onAdd: function () {
        var div = L.DomUtil.create('div', 'pc-wind-legend');
        L.DomEvent.disableClickPropagation(div);
        var maxKt = Math.round(VENTO_MAX_MS * MS_TO_KT);
        div.innerHTML =
          '<div class="pc-wind-legend-label">Vento (kt)</div>'
          + '<div class="pc-wind-legend-bar" style="background:linear-gradient(to right,' + VENTO_CORES.join(',') + ')"></div>'
          + '<div class="pc-wind-legend-scale"><span>0</span><span>' + Math.round(maxKt / 2) + '</span><span>' + maxKt + '+</span></div>';
        return div;
      }
    });
    return new Legenda();
  }

  /* Converte a grade de pontos (lat/lon + u/v em kt por nível — ver
     OpenMeteoClient::gradeVento()) pro formato GRIB-like que
     leaflet-velocity espera: dois "arquivos" (u, v), cada um com um
     header descrevendo a grade regular e um array de valores em
     ordem de varredura norte->sul, oeste->leste. */
  function buildVelocityData(vento, nivel) {
    var pontos = (vento.pontos || []).filter(function (p) { return p.niveis && p.niveis[nivel]; });
    if (pontos.length < 4) return null;

    var lats = uniqSorted(pontos.map(function (p) { return p.lat; }), true);
    var lons = uniqSorted(pontos.map(function (p) { return p.lon; }), false);
    if (lats.length < 2 || lons.length < 2) return null;

    var nx = lons.length, ny = lats.length;
    var dx = (lons[lons.length - 1] - lons[0]) / (nx - 1);
    var dy = (lats[0] - lats[lats.length - 1]) / (ny - 1);

    var byKey = {};
    pontos.forEach(function (p) { byKey[p.lat.toFixed(3) + '_' + p.lon.toFixed(3)] = p.niveis[nivel]; });

    var uData = [], vData = [];
    var KT_TO_MS = 0.514444;
    lats.forEach(function (lat) {
      lons.forEach(function (lon) {
        var v = byKey[lat.toFixed(3) + '_' + lon.toFixed(3)];
        uData.push(v ? +(v.uKt * KT_TO_MS).toFixed(2) : 0);
        vData.push(v ? +(v.vKt * KT_TO_MS).toFixed(2) : 0);
      });
    });

    var header = {
      parameterUnit: 'm/s',
      parameterNumberName: 'eastward_wind',
      parameterCategory: 2,
      parameterNumber: 2,
      nx: nx, ny: ny,
      lo1: lons[0], la1: lats[0],
      lo2: lons[lons.length - 1], la2: lats[lats.length - 1],
      dx: dx, dy: dy,
      refTime: vento.geradoEm || new Date().toISOString()
    };

    return [
      { header: header, data: uData },
      { header: Object.assign({}, header, { parameterNumberName: 'northward_wind', parameterNumber: 3 }), data: vData }
    ];
  }

  function uniqSorted(arr, desc) {
    var s = Array.from(new Set(arr.map(function (v) { return +v.toFixed(4); })));
    s.sort(function (a, b) { return desc ? b - a : a - b; });
    return s;
  }

  /* ---------- gráficos de evolução + eventos de máx/mín no mapa ---------- */
  /* Pedido em conversa: "mostrar dados climáticos sentidos pela
     aeronave, como velocidade do vento, rajadas, gelo, neve, chuva etc
     com gráficos de evolução, max/mín e marcar no mapa onde atingiu o
     máximo e o mínimo (tipo eventos)". Cada métrica vira um mini-gráfico
     SVG (desenhado à mão, sem lib nova) a partir de `pesquisa.amostras`
     — que já tem lat/lon por ponto — e o ponto de máximo/mínimo de cada
     um vira um marcador permanente no mapa (EVENTOS_LAYER), clicável
     tanto ali quanto pelo botão embaixo do gráfico. */
  var METRICAS_SENTIDO = [
    { chave: 'ventoKt', extra: 'rajadaKt', tituloExtra: 'rajada', titulo: 'Vento e rajada', unidade: 'kt', cor: '#5aa9ff', corExtra: '#ff8c00' },
    { chave: 'tempC', titulo: 'Temperatura', unidade: '°C', cor: '#ff8c69' },
    { chave: 'precipMmH', titulo: 'Precipitação (chuva/neve)', unidade: 'mm/h', cor: '#4fc3f7' }
  ];

  function construirGraficos() {
    var box = document.getElementById('pc-charts');
    var empty = document.getElementById('pc-charts-empty');
    if (!box) return;
    if (AMOSTRAS.length < 2) { if (empty) empty.hidden = false; return; }
    if (empty) empty.hidden = true;

    METRICAS_SENTIDO.forEach(function (m) {
      var pontos = AMOSTRAS.filter(function (a) { return a[m.chave] != null && isFinite(a[m.chave]); });
      if (pontos.length < 2) return;
      box.appendChild(construirCardGrafico(m, pontos));
    });
  }

  function construirCardGrafico(m, pontos) {
    var w = 560, h = 130, padL = 34, padR = 10, padT = 10, padB = 20;
    var t0 = new Date(pontos[0].em).getTime();
    var t1 = new Date(pontos[pontos.length - 1].em).getTime();
    var vals = pontos.map(function (p) { return p[m.chave]; });
    var vMin = Math.min.apply(null, vals), vMax = Math.max.apply(null, vals);
    if (vMin === vMax) { vMin -= 1; vMax += 1; }

    function xPos(p) { return padL + (t1 === t0 ? 0 : (new Date(p.em).getTime() - t0) / (t1 - t0)) * (w - padL - padR); }
    function yPos(v) { return padT + (1 - (v - vMin) / (vMax - vMin)) * (h - padT - padB); }

    var idxMax = 0, idxMin = 0;
    pontos.forEach(function (p, i) {
      if (p[m.chave] > pontos[idxMax][m.chave]) idxMax = i;
      if (p[m.chave] < pontos[idxMin][m.chave]) idxMin = i;
    });
    var pMax = pontos[idxMax], pMin = pontos[idxMin];

    var linha = pontos.map(function (p) { return xPos(p).toFixed(1) + ',' + yPos(p[m.chave]).toFixed(1); }).join(' ');
    var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="pc-chart-svg" preserveAspectRatio="none">'
      + '<line x1="' + padL + '" y1="' + padT + '" x2="' + padL + '" y2="' + (h - padB) + '" class="pc-chart-axis"></line>'
      + '<line x1="' + padL + '" y1="' + (h - padB) + '" x2="' + (w - padR) + '" y2="' + (h - padB) + '" class="pc-chart-axis"></line>'
      + '<text x="2" y="' + (padT + 4) + '" class="pc-chart-axislabel">' + vMax.toFixed(1) + '</text>'
      + '<text x="2" y="' + (h - padB + 4) + '" class="pc-chart-axislabel">' + vMin.toFixed(1) + '</text>'
      + '<polyline points="' + linha + '" class="pc-chart-linha" style="stroke:' + m.cor + '"></polyline>';

    if (m.extra) {
      var pontosExtra = pontos.filter(function (p) { return p[m.extra] != null && isFinite(p[m.extra]); });
      if (pontosExtra.length > 1) {
        var linhaExtra = pontosExtra.map(function (p) { return xPos(p).toFixed(1) + ',' + yPos(p[m.extra]).toFixed(1); }).join(' ');
        svg += '<polyline points="' + linhaExtra + '" class="pc-chart-linha pc-chart-linha-extra" style="stroke:' + m.corExtra + '"></polyline>';
      }
    }

    svg += '<circle cx="' + xPos(pMax).toFixed(1) + '" cy="' + yPos(pMax[m.chave]).toFixed(1) + '" r="4" class="pc-chart-max"></circle>'
      + '<circle cx="' + xPos(pMin).toFixed(1) + '" cy="' + yPos(pMin[m.chave]).toFixed(1) + '" r="4" class="pc-chart-min"></circle>'
      + '</svg>';

    var card = document.createElement('div');
    card.className = 'pc-chart-card';

    var h4 = document.createElement('h4');
    h4.textContent = m.titulo + ' (' + m.unidade + ')' + (m.extra ? ' — pontilhado: ' + m.tituloExtra : '');
    card.appendChild(h4);

    var svgWrap = document.createElement('div');
    svgWrap.innerHTML = svg;
    card.appendChild(svgWrap.firstChild);

    var stats = document.createElement('div');
    stats.className = 'pc-chart-stats';

    var btnMax = document.createElement('button');
    btnMax.type = 'button';
    btnMax.className = 'pc-chart-stat pc-chart-stat-max';
    btnMax.innerHTML = '<b>Máx.</b> ' + pMax[m.chave].toFixed(1) + ' ' + m.unidade + ' · ' + horaZ(pMax.em);
    btnMax.addEventListener('click', function () { focarNoMapa(pMax, m.titulo + ' — máximo: ' + pMax[m.chave].toFixed(1) + ' ' + m.unidade); });
    stats.appendChild(btnMax);

    var btnMin = document.createElement('button');
    btnMin.type = 'button';
    btnMin.className = 'pc-chart-stat pc-chart-stat-min';
    btnMin.innerHTML = '<b>Mín.</b> ' + pMin[m.chave].toFixed(1) + ' ' + m.unidade + ' · ' + horaZ(pMin.em);
    btnMin.addEventListener('click', function () { focarNoMapa(pMin, m.titulo + ' — mínimo: ' + pMin[m.chave].toFixed(1) + ' ' + m.unidade); });
    stats.appendChild(btnMin);

    card.appendChild(stats);

    marcarEventoNoMapa(pMax, m.titulo + ' — máximo<br>' + pMax[m.chave].toFixed(1) + ' ' + m.unidade + ' · ' + horaZ(pMax.em), '#e63946');
    marcarEventoNoMapa(pMin, m.titulo + ' — mínimo<br>' + pMin[m.chave].toFixed(1) + ' ' + m.unidade + ' · ' + horaZ(pMin.em), '#4fc3f7');

    return card;
  }

  function horaZ(emIso) {
    var d = new Date(emIso);
    return d.getUTCHours().toString().padStart(2, '0') + ':' + d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
  }

  function focarNoMapa(ponto, texto) {
    if (!ponto || !isFinite(ponto.lat) || !isFinite(ponto.lon)) return;
    MAP.panTo([ponto.lat, ponto.lon]);
    L.popup({ maxWidth: 240 })
      .setLatLng([ponto.lat, ponto.lon])
      .setContent('<b>' + texto + '</b><br><span style="opacity:.65;font-size:11px">' + horaZ(ponto.em) + '</span>')
      .openOn(MAP);
  }

  function marcarEventoNoMapa(ponto, textoHtml, cor) {
    if (!ponto || !isFinite(ponto.lat) || !isFinite(ponto.lon)) return;
    L.circleMarker([ponto.lat, ponto.lon], {
      radius: 7, weight: 2, color: '#fff', fillColor: cor, fillOpacity: .95, pane: 'pcEventosPane'
    }).bindTooltip(textoHtml).addTo(EVENTOS_LAYER);
  }

  /* Evento(s) de gelo estrutural REAL (`TelemetryDeriver::deriveEvents()`,
     tipo `icing_onset`) — telemetria de verdade da aeronave, diferente
     dos gráficos acima (que são a previsão meteorológica ao longo da
     rota). `events` guarda offset em segundos desde o início do voo, não
     lat/lon — correlaciona com TRACK (mesmo princípio do
     `pontoMaisProximo()` que o PHP já faz em `PesquisaReprocessarCommand`). */
  function construirEventosTelemetria() {
    if (!TELEMETRIA || !Array.isArray(TELEMETRIA.events) || !TRACK.length) return;
    var inicioMs = new Date(window.KATABATIC_VOO_STARTED_AT).getTime();

    TELEMETRIA.events.forEach(function (e) {
      if (!Array.isArray(e) || e.length < 5 || 'icing_onset' !== e[1]) return;
      var tSeg = e[0], titulo = e[2], valor = e[3];
      var p = pontoDoTrack(tSeg);
      if (!p) return;

      var em = isFinite(inicioMs) ? new Date(inicioMs + tSeg * 1000).toISOString() : null;
      marcarEventoNoMapa(
        { lat: p.lat, lon: p.lon, em: em || new Date().toISOString() },
        '<b>' + titulo + '</b> (telemetria real)' + (valor ? '<br>' + valor : ''),
        '#b48ead'
      );
    });
  }

  function pontoDoTrack(tSeg) {
    var melhor = null, melhorDist = Infinity;
    TRACK.forEach(function (p) {
      if (!isFinite(p[1]) || !isFinite(p[2])) return;
      var d = Math.abs(p[0] - tSeg);
      if (d < melhorDist) { melhorDist = d; melhor = p; }
    });
    return melhor ? { lat: melhor[1], lon: melhor[2] } : null;
  }

  /* ---------- controles ---------- */
  function wireControls() {
    document.getElementById('pc-nivel').addEventListener('change', atualizarVento);
    document.getElementById('pc-vento-toggle').addEventListener('change', atualizarVento);
    document.getElementById('pc-precip-toggle').addEventListener('change', atualizarImagemMapa);
    document.getElementById('pc-follow-alt').addEventListener('change', function () {
      atualizarSeletorNivel();
      atualizarVento();
    });
    document.querySelectorAll('.pc-imgmode button').forEach(function (btn) {
      btn.addEventListener('click', function () { alternarModoMapa(btn.dataset.modo); });
    });
  }

  function boot() {
    initMap();
    buildTimeline();
    wireControls();
    if (CAPTURAS.length) selecionar(CAPTURAS.length - 1);
    // Gráficos + eventos de máx/mín/gelo não dependem de captura/modo
    // selecionado — montados uma vez só, ficam sempre visíveis no mapa.
    construirGraficos();
    construirEventosTelemetria();
  }

  if (typeof L === 'undefined') {
    window.addEventListener('load', boot);
  } else {
    boot();
  }
})();
