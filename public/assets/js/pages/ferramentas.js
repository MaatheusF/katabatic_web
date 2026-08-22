/* ==========================================================================
   Katabatic — ferramentas.js
   4 calculadoras client-side (ver FerramentasController):

   1. Vento cruzado/cauda — so trigonometria, nao depende de nenhum dado
      cadastrado.
   2. Conversor de unidades + ETA — idem.
   3. Peso e balanceamento — usa window.KATABATIC_FERRAMENTAS_AERONAVES
      (frota, reg->tipo) e window.KATABATIC_FERRAMENTAS_TIPOS (perfil de
      performance por tipo, App\Entity\TipoAeronave) pra achar peso vazio
      + MTOW do tipo selecionado. So verifica peso total vs. MTOW - sem
      envelope de CG/momento, ver aviso no template.
   4. Distancia de decolagem/pouso ajustada — usa a mesma lista de tipos
      pra achar a distancia de referencia, aplica uma regra de bolso de
      correcao por altitude de densidade + vento. Estimativa aproximada,
      ver aviso no template.

   Time de qualquer tipo/aeronave sem TipoAeronave cadastrado (ou com os
   campos relevantes em branco) mostra um aviso e desabilita a
   calculadora em vez de assumir um numero - ver tools.wb.missing/
   tools.dist.missing.
   ========================================================================== */
(function () {
  'use strict';

  var AERONAVES = window.KATABATIC_FERRAMENTAS_AERONAVES || [];
  var TIPOS = window.KATABATIC_FERRAMENTAS_TIPOS || [];

  function tr(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  function fmt(n, casas) {
    if (!isFinite(n)) return '—';
    return n.toLocaleString(undefined, { minimumFractionDigits: casas || 0, maximumFractionDigits: casas || 0 });
  }

  function tipoByNome(nome) {
    return TIPOS.filter(function (t) { return t.nome === nome; })[0] || null;
  }

  /* ==================== 1. Vento cruzado / cauda ==================== */
  (function () {
    var rwyEl = document.getElementById('w-rwy');
    var dirEl = document.getElementById('w-dir');
    var speedEl = document.getElementById('w-speed');
    var resultEl = document.getElementById('w-result');

    function calc() {
      var rwy = parseFloat(rwyEl.value);
      var dir = parseFloat(dirEl.value);
      var speed = parseFloat(speedEl.value);

      if (!isFinite(rwy) || !isFinite(dir) || !isFinite(speed) || rwy < 1 || rwy > 36) {
        resultEl.innerHTML = '';
        return;
      }

      var rwyHeading = rwy * 10;
      var angle = dir - rwyHeading;
      // normaliza pra -180..180
      angle = ((angle + 180) % 360 + 360) % 360 - 180;
      var rad = angle * Math.PI / 180;
      var crosswind = speed * Math.sin(rad);
      var alongRunway = speed * Math.cos(rad);

      var html = '';
      if (Math.abs(crosswind) < 0.5) {
        html += '<div class="result-line">' + tr('tools.wind.result.calm', 'Alinhado com a pista — sem componente de vento cruzado.') + '</div>';
      } else {
        var lado = crosswind > 0 ? tr('tools.wind.result.right', 'pela direita') : tr('tools.wind.result.left', 'pela esquerda');
        html += '<div class="result-line"><span class="result-label">' + tr('tools.wind.result.crosswind', 'Vento cruzado') + '</span><span class="result-value">' + fmt(Math.abs(crosswind), 1) + ' kt ' + lado + '</span></div>';
      }
      var alongLabel = alongRunway >= 0 ? tr('tools.wind.result.headwind', 'Vento de proa') : tr('tools.wind.result.tailwind', 'Vento de cauda');
      html += '<div class="result-line"><span class="result-label">' + alongLabel + '</span><span class="result-value">' + fmt(Math.abs(alongRunway), 1) + ' kt</span></div>';
      resultEl.innerHTML = html;
    }

    [rwyEl, dirEl, speedEl].forEach(function (el) { el.addEventListener('input', calc); });
    document.addEventListener('katabatic:langchange', calc);
  })();

  /* ==================== 2. Conversor de unidades + ETA ==================== */
  (function () {
    // Fator de conversao pra uma unidade-base de cada categoria (nao
    // precisa ser SI, so consistente dentro da categoria).
    var CATEGORIES = {
      dist: { base: 'nm', units: { nm: 1, km: 1.852, mi: 1.150779, ft: 1 / 6076.12, m: 1852 } },
      speed: { base: 'kt', units: { kt: 1, kmh: 1.852, mph: 1.150779 } },
      weight: { base: 'lb', units: { lb: 1, kg: 0.453592 } },
      fuel: { base: 'gal', units: { gal: 1, l: 3.785412 } },
      alt: { base: 'ft', units: { ft: 1, m: 0.3048 } }
    };
    var UNIT_LABELS = {
      nm: 'NM', km: 'km', mi: 'mi', ft: 'ft', m: 'm',
      kt: 'kt', kmh: 'km/h', mph: 'mph',
      lb: 'lb', kg: 'kg', gal: 'gal', l: 'L'
    };

    var catChips = document.querySelectorAll('#conv-cat-chips .chip');
    var valueEl = document.getElementById('conv-value');
    var fromEl = document.getElementById('conv-from');
    var toEl = document.getElementById('conv-to');
    var resultEl = document.getElementById('conv-result');
    var cat = 'dist';

    function populateUnits() {
      var units = Object.keys(CATEGORIES[cat].units);
      [fromEl, toEl].forEach(function (sel, i) {
        sel.innerHTML = units.map(function (u) { return '<option value="' + u + '">' + UNIT_LABELS[u] + '</option>'; }).join('');
        sel.selectedIndex = i === 0 ? 0 : Math.min(1, units.length - 1);
      });
    }

    function convert() {
      var v = parseFloat(valueEl.value);
      if (!isFinite(v)) {
        resultEl.innerHTML = '';
        return;
      }
      var units = CATEGORIES[cat].units;
      var base = v * units[fromEl.value];
      var out = base / units[toEl.value];
      resultEl.innerHTML = '<div class="result-line"><span class="result-value result-value-lg">' + fmt(out, out < 10 ? 2 : (out < 100 ? 1 : 0)) + ' ' + UNIT_LABELS[toEl.value] + '</span></div>';
    }

    catChips.forEach(function (c) {
      c.addEventListener('click', function () {
        catChips.forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        cat = c.dataset.cat;
        populateUnits();
        convert();
      });
    });
    [valueEl, fromEl, toEl].forEach(function (el) { el.addEventListener('input', convert); });
    populateUnits();

    /* ---- ETA ---- */
    var distEl = document.getElementById('eta-dist');
    var gsEl = document.getElementById('eta-gs');
    var depEl = document.getElementById('eta-dep');
    var etaResultEl = document.getElementById('eta-result');

    function calcEta() {
      var dist = parseFloat(distEl.value);
      var gs = parseFloat(gsEl.value);
      if (!isFinite(dist) || !isFinite(gs) || gs <= 0 || dist < 0) {
        etaResultEl.innerHTML = '';
        return;
      }
      var minutos = Math.round((dist / gs) * 60);
      var h = Math.floor(minutos / 60);
      var m = minutos % 60;
      var duracao = h + 'h' + (m < 10 ? '0' : '') + m;

      var html = '<div class="result-line"><span class="result-label">' + tr('tools.eta.result.duration', 'Tempo de voo') + '</span><span class="result-value">' + duracao + '</span></div>';

      if (depEl.value) {
        var partes = depEl.value.split(':');
        var depMin = parseInt(partes[0], 10) * 60 + parseInt(partes[1], 10);
        var etaMin = (depMin + minutos) % 1440;
        var etaH = Math.floor(etaMin / 60);
        var etaM = etaMin % 60;
        var etaStr = (etaH < 10 ? '0' : '') + etaH + ':' + (etaM < 10 ? '0' : '') + etaM;
        var diasDepois = Math.floor((depMin + minutos) / 1440);
        html += '<div class="result-line"><span class="result-label">' + tr('tools.eta.result.eta', 'ETA') + '</span><span class="result-value">' + etaStr + (diasDepois > 0 ? ' +' + diasDepois + 'd' : '') + '</span></div>';
      }
      etaResultEl.innerHTML = html;
    }
    [distEl, gsEl, depEl].forEach(function (el) { el.addEventListener('input', calcEta); });

    document.addEventListener('katabatic:langchange', function () { convert(); calcEta(); });
  })();

  /* ==================== 3. Peso e balanceamento ==================== */
  (function () {
    var aircraftEl = document.getElementById('wb-aircraft');
    var refEl = document.getElementById('wb-ref');
    var missingEl = document.getElementById('wb-missing');
    var fieldsEl = document.getElementById('wb-fields');
    var densityFieldEl = document.getElementById('wb-density-field');
    var densityChips = document.querySelectorAll('#wb-density-chips .chip');
    var crewEl = document.getElementById('wb-crew');
    var payloadEl = document.getElementById('wb-payload');
    var fuelEl = document.getElementById('wb-fuel');
    var resultEl = document.getElementById('wb-result');
    var densidade = 6.7;

    // reg unicos ordenados, ligados ao tipo pra descobrir o TipoAeronave certo
    AERONAVES.forEach(function (a) {
      var opt = document.createElement('option');
      opt.value = a.reg;
      opt.textContent = a.reg + ' — ' + a.tipo;
      aircraftEl.appendChild(opt);
    });

    densityChips.forEach(function (c) {
      c.addEventListener('click', function () {
        densityChips.forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        densidade = parseFloat(c.dataset.density);
        calc();
      });
    });

    function currentTipo() {
      var aeronave = AERONAVES.filter(function (a) { return a.reg === aircraftEl.value; })[0];
      return aeronave ? tipoByNome(aeronave.tipo) : null;
    }

    function updateVisibility() {
      var tipo = currentTipo();
      if (!aircraftEl.value) {
        refEl.style.display = 'none';
        missingEl.style.display = 'none';
        fieldsEl.style.display = 'none';
        densityFieldEl.style.display = 'none';
        resultEl.innerHTML = '';
        return;
      }
      var aeronave = AERONAVES.filter(function (a) { return a.reg === aircraftEl.value; })[0];
      var temDados = tipo && tipo.pesoVazioLb !== null && tipo.pesoMaxDecolagemLb !== null;
      if (!temDados) {
        refEl.style.display = 'none';
        fieldsEl.style.display = 'none';
        densityFieldEl.style.display = 'none';
        missingEl.style.display = '';
        missingEl.innerHTML = tr('tools.wb.missing', 'Ainda não há perfil de performance cadastrado pra <b>%s</b> — peça pra um admin preencher em <a href="/tipos-aeronave">Tipos de aeronave</a>.').replace('%s', aeronave.tipo);
        resultEl.innerHTML = '';
        return;
      }
      missingEl.style.display = 'none';
      fieldsEl.style.display = '';
      densityFieldEl.style.display = '';
      refEl.style.display = '';
      refEl.innerHTML = tr('tools.wb.ref', 'Peso vazio <b>%1$s lb</b> · MTOW <b>%2$s lb</b>').replace('%1$s', fmt(tipo.pesoVazioLb)).replace('%2$s', fmt(tipo.pesoMaxDecolagemLb));
      calc();
    }

    function calc() {
      var tipo = currentTipo();
      if (!tipo || tipo.pesoVazioLb === null || tipo.pesoMaxDecolagemLb === null) return;

      var crew = parseFloat(crewEl.value) || 0;
      var payload = parseFloat(payloadEl.value) || 0;
      var fuelGal = parseFloat(fuelEl.value) || 0;
      var fuelLb = fuelGal * densidade;

      var total = tipo.pesoVazioLb + crew + payload + fuelLb;
      var margem = tipo.pesoMaxDecolagemLb - total;

      var tag = margem < 0 ? 'tag-bad' : (margem < tipo.pesoMaxDecolagemLb * 0.05 ? 'tag-warn' : 'tag-ok');
      var linha2 = margem < 0
        ? '<span class="tag ' + tag + '">' + tr('tools.wb.result.over', 'ACIMA DO MTOW em') + ' ' + fmt(Math.abs(margem)) + ' lb</span>'
        : '<span class="tag ' + tag + '">' + fmt(margem) + ' lb ' + tr('tools.wb.result.margin.ok', 'de margem até o MTOW') + '</span>';

      resultEl.innerHTML =
        '<div class="result-line"><span class="result-label">' + tr('tools.wb.result.total', 'Peso total') + '</span><span class="result-value">' + fmt(total) + ' lb</span></div>' +
        '<div class="result-line">' + linha2 + '</div>';
    }

    aircraftEl.addEventListener('change', updateVisibility);
    [crewEl, payloadEl, fuelEl].forEach(function (el) { el.addEventListener('input', calc); });
    document.addEventListener('katabatic:langchange', updateVisibility);
  })();

  /* ==================== 4. Distância de decolagem/pouso ajustada ==================== */
  (function () {
    var typeEl = document.getElementById('dist-type');
    var missingEl = document.getElementById('dist-missing');
    var fieldsEl = document.getElementById('dist-fields');
    var elevEl = document.getElementById('dist-elev');
    var oatEl = document.getElementById('dist-oat');
    var windEl = document.getElementById('dist-wind');
    var resultEl = document.getElementById('dist-result');

    TIPOS.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.nome;
      opt.textContent = t.nome;
      typeEl.appendChild(opt);
    });

    function updateVisibility() {
      if (!typeEl.value) {
        missingEl.style.display = 'none';
        fieldsEl.style.display = 'none';
        resultEl.innerHTML = '';
        return;
      }
      var tipo = tipoByNome(typeEl.value);
      var temDados = tipo && (tipo.decolagemDistanciaFt !== null || tipo.pousoDistanciaFt !== null);
      if (!temDados) {
        fieldsEl.style.display = 'none';
        missingEl.style.display = '';
        missingEl.innerHTML = tr('tools.dist.missing', 'Ainda não há distância de referência cadastrada pra <b>%s</b> — peça pra um admin preencher em <a href="/tipos-aeronave">Tipos de aeronave</a>.').replace('%s', typeEl.value);
        resultEl.innerHTML = '';
        return;
      }
      missingEl.style.display = 'none';
      fieldsEl.style.display = '';
      calc();
    }

    // Regra de bolso: altitude de densidade aproximada (PA + 120ft por
    // grau C acima do ISA na PA) e correcao de +-10% de distancia por
    // patamar de densidade/vento - ver docblock de FerramentasController
    // e o aviso "estimativa aproximada" no template. Nunca reduz abaixo
    // da distancia de referencia por conta de densidade baixa/vento de
    // proa forte (fator minimo 1.0) - e melhor superestimar do que
    // subestimar quanta pista vai ser usada.
    function densityAltitude(elevFt, oatC) {
      var isaTemp = 15 - (2 * elevFt / 1000);
      return elevFt + 120 * (oatC - isaTemp);
    }

    function calc() {
      var tipo = tipoByNome(typeEl.value);
      if (!tipo) return;

      var elev = parseFloat(elevEl.value);
      var oat = parseFloat(oatEl.value);
      var wind = parseFloat(windEl.value);
      if (!isFinite(elev) || !isFinite(oat)) {
        resultEl.innerHTML = '';
        return;
      }
      wind = isFinite(wind) ? wind : 0;

      var da = densityAltitude(elev, oat);
      var densityFactor = Math.max(1, 1 + 0.10 * (da / 1000));
      var windFactor = wind >= 0
        ? Math.max(0.5, 1 - 0.10 * (wind / 9))
        : 1 + 0.10 * (Math.abs(wind) / 2);
      var fator = densityFactor * windFactor;

      var html = '<div class="result-line"><span class="result-label">' + tr('tools.dist.result.da', 'Altitude de densidade') + '</span><span class="result-value">' + fmt(da) + ' ft</span></div>';

      if (tipo.decolagemDistanciaFt !== null) {
        var takeoff = tipo.decolagemDistanciaFt * fator;
        html += '<div class="result-line"><span class="result-label">' + tr('tools.dist.result.takeoff', 'Distância de decolagem ajustada') + '</span><span class="result-value">' + fmt(takeoff) + ' ft <span class="sub">(' + tr('tools.dist.result.reference', 'ref.') + ' ' + fmt(tipo.decolagemDistanciaFt) + ' ft)</span></span></div>';
      }
      if (tipo.pousoDistanciaFt !== null) {
        var landing = tipo.pousoDistanciaFt * fator;
        html += '<div class="result-line"><span class="result-label">' + tr('tools.dist.result.landing', 'Distância de pouso ajustada') + '</span><span class="result-value">' + fmt(landing) + ' ft <span class="sub">(' + tr('tools.dist.result.reference', 'ref.') + ' ' + fmt(tipo.pousoDistanciaFt) + ' ft)</span></span></div>';
      }
      resultEl.innerHTML = html;
    }

    typeEl.addEventListener('change', updateVisibility);
    [elevEl, oatEl, windEl].forEach(function (el) { el.addEventListener('input', calc); });
    document.addEventListener('katabatic:langchange', updateVisibility);
  })();
})();
