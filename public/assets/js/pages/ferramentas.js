/* ==========================================================================
   Katabatic — ferramentas.js
   7 calculadoras client-side (ver FerramentasController):

   1. Vento cruzado/cauda — so trigonometria, nao depende de nenhum dado
      cadastrado.
   2. Conversor de unidades + ETA (inclui QNH hPa<->inHg como categoria
      "pressure") — idem.
   3. Peso e balanceamento — usa window.KATABATIC_FERRAMENTAS_AERONAVES
      (frota, reg->tipo) e window.KATABATIC_FERRAMENTAS_TIPOS (perfil de
      performance por tipo, App\Entity\TipoAeronave) pra achar peso vazio
      + MTOW do tipo selecionado. So verifica peso total vs. MTOW - sem
      envelope de CG/momento, ver aviso no template.
   4. Distancia de decolagem/pouso ajustada — usa a mesma lista de tipos
      pra achar a distancia de referencia, aplica uma regra de bolso de
      correcao por altitude de densidade + vento. Estimativa aproximada,
      ver aviso no template.
   5. Zulu (UTC) x hora local das Bases — so matematica de fuso horario
      via Intl.DateTimeFormat (ver tzOffsetMinutes), nao depende de
      nenhum dado cadastrado (as 6 bases sao a mesma lista fixa usada em
      PortalController::bases()/aeroporto.js).
   6. Alcance de planeio (engine-out) — so trigonometria/regra de bolso a
      partir de altitude + razao de planeio informada pelo piloto (nao ha
      campo de L/D em TipoAeronave ainda), idem tool 1/2.
   7. Ponto ideal de descida (TOD) — idem, regra de bolso trigonometrica
      a partir de altitude a perder + velocidade no solo + taxa/angulo.
   8. Gerador de callsign/numero de voo — pedido em conversa: numero
      determinado pela ROTA (origem->destino) + digito do tipo de
      operacao (mesmo mapa 1/2/4/5/9 que novo-voo.js ja sugere pelo 1o
      digito do callsign), NAO pela aeronave - decisao tomada em
      conversa depois de confirmar que companhias aereas de verdade
      amarram numero de voo a rota, nao a aeronave especifica (o mesmo
      aviao voa varios numeros por dia). Mesma rota sempre gera o mesmo
      numero (hash determinístico do par origem/destino, ver
      hashRota()); o sentido contrario sai no numero vizinho (par/
      impar), imitando a convencao real de ida/volta. Aeronave
      selecionada e so contexto no resultado, nunca entra na conta.

   Time de qualquer tipo/aeronave sem TipoAeronave cadastrado (ou com os
   campos relevantes em branco) mostra um aviso e desabilita a
   calculadora em vez de assumir um numero - ver tools.wb.missing/
   tools.dist.missing (essa parte vale so pras calculadoras 3/4).
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
    //
    // **Corrigido (bug reportado em conversa):** convert() abaixo faz
    // `base = v * units[from]` e `out = base / units[to]` - ou seja,
    // `units[u]` precisa ser "quantas unidades-base equivalem a 1 `u`"
    // (multiplicar o valor em `u` por `units[u]` pra chegar na base).
    // A tabela antiga guardava o fato real na direção oposta - "1 nm =
    // 1.852 km" virou literalmente `km: 1.852`, quando o que a fórmula
    // precisa é "1 km = quantos nm" (0.539957, o inverso). Isso quebrava
    // TODAS as unidades não-base de TODAS as categorias, não só kg→lb
    // (o mais notado por ser o mais usado) - `ft` em `dist` era o único
    // caso que por acaso já estava certo, porque tinha sido escrito como
    // fração (`1 / 6076.12`) em vez do fato memorizado direto. Fix:
    // cada fator vira `1 / X`, onde `X` é exatamente o mesmo número
    // (real, correto) que já estava aqui - só a direção da divisão
    // muda, os fatos em si nunca estiveram errados.
    var CATEGORIES = {
      dist: { base: 'nm', units: { nm: 1, km: 1 / 1.852, mi: 1 / 1.150779, ft: 1 / 6076.12, m: 1 / 1852 } },
      speed: { base: 'kt', units: { kt: 1, kmh: 1 / 1.852, mph: 1 / 1.150779 } },
      weight: { base: 'lb', units: { lb: 1, kg: 1 / 0.453592 } },
      fuel: { base: 'gal', units: { gal: 1, l: 1 / 3.785412 } },
      alt: { base: 'ft', units: { ft: 1, m: 1 / 0.3048 } },
      // QNH/altímetro - pedido em conversa. 1 inHg = 33.8639 hPa (fato
      // real), então units.inHg (unidade->base, mesma convenção das
      // outras categorias acima) é exatamente esse número.
      pressure: { base: 'hPa', units: { hPa: 1, inHg: 33.8639 } }
    };
    var UNIT_LABELS = {
      nm: 'NM', km: 'km', mi: 'mi', ft: 'ft', m: 'm',
      kt: 'kt', kmh: 'km/h', mph: 'mph',
      lb: 'lb', kg: 'kg', gal: 'gal', l: 'L',
      hPa: 'hPa', inHg: 'inHg'
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
      // inHg sempre com 2 casas (altímetro/QNH se lê em centésimos, ex.
      // 29.92) - a regra genérica por magnitude (abaixo) daria só 1 casa
      // pra valores nessa faixa (10-100), o que perderia precisão real.
      var casas = toEl.value === 'inHg' ? 2 : (out < 10 ? 2 : (out < 100 ? 1 : 0));
      resultEl.innerHTML = '<div class="result-line"><span class="result-value result-value-lg">' + fmt(out, casas) + ' ' + UNIT_LABELS[toEl.value] + '</span></div>';
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
      // Peso e balanceamento vale igual pra aviao/helicoptero (peso total
      // vs. MTOW nao muda com asa fixa vs. rotativa) - so rotula a
      // categoria aqui como contexto extra, nao afeta a conta.
      var categoriaTag = tipo.categoria === 'Helicoptero'
        ? ' <span class="tag">' + tr('common.category.helicopter', 'Helicóptero') + '</span>'
        : '';
      refEl.innerHTML = tr('tools.wb.ref', 'Peso vazio <b>%1$s lb</b> · MTOW <b>%2$s lb</b>').replace('%1$s', fmt(tipo.pesoVazioLb)).replace('%2$s', fmt(tipo.pesoMaxDecolagemLb)) + categoriaTag;
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

      // Helicoptero: pouso/decolagem vertical, o conceito de "distancia de
      // ground roll" nem existe - mostra "nao aplicavel" em vez do aviso
      // de dado faltando (mesmo sem nenhum numero cadastrado, nao faz
      // sentido pedir pro admin preencher algo que fisicamente nao se
      // aplica). Ver TipoAeronave::$categoria/FerramentasController.
      if (tipo && tipo.categoria === 'Helicoptero') {
        fieldsEl.style.display = 'none';
        missingEl.style.display = '';
        missingEl.innerHTML = tr('tools.dist.helicopter', '<b>%s</b> está cadastrado como helicóptero — pouso/decolagem vertical não usa distância de ground roll, então esta calculadora não tem o que estimar aqui.').replace('%s', typeEl.value);
        resultEl.innerHTML = '';
        return;
      }

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

  /* ==================== 5. Zulu (UTC) x hora local das Bases ==================== */
  (function () {
    var BASES = [
      { icao: 'PAFA', name: 'Fairbanks, Alasca', tz: 'America/Anchorage' },
      { icao: 'SCCI', name: 'Punta Arenas, Chile', tz: 'America/Punta_Arenas' },
      { icao: 'SLLP', name: 'La Paz, Bolívia', tz: 'America/La_Paz' },
      { icao: 'VNKT', name: 'Catmandu, Nepal', tz: 'Asia/Kathmandu' },
      { icao: 'WAJW', name: 'Wamena, Nova Guiné', tz: 'Asia/Jayapura' },
      { icao: 'VQPR', name: 'Paro, Butão', tz: 'Asia/Thimphu' }
    ];

    var timeEl = document.getElementById('zulu-time');
    var refEl = document.getElementById('zulu-ref');
    var nowBtn = document.getElementById('zulu-now');
    var resultEl = document.getElementById('zulu-result');

    // Offset (minutos, +/- de UTC) de um fuso IANA pro instante atual —
    // truque padrao sem lib de timezone: formata "agora" em UTC e no
    // fuso alvo com toLocaleString (mesma referencia temporal, so texto
    // muda), reconstroi como Date e tira a diferenca. Ja cobre horario
    // de verao automaticamente, porque usa o offset vigente *agora* -
    // nao serve pra outra data/epoca do ano com certeza absoluta (ver
    // aviso no template), mas e exatamente o que "hora de referencia" no
    // campo pede.
    function tzOffsetMinutes(tz) {
      if (tz === 'UTC') return 0;
      var now = new Date();
      var utcAsLocal = new Date(now.toLocaleString('en-US', { timeZone: 'UTC' }));
      var tzAsLocal = new Date(now.toLocaleString('en-US', { timeZone: tz }));
      return Math.round((tzAsLocal.getTime() - utcAsLocal.getTime()) / 60000);
    }

    function hhmm(totalMin) {
      var m = ((totalMin % 1440) + 1440) % 1440;
      var h = Math.floor(m / 60);
      var mm = m % 60;
      return (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
    }

    function calc() {
      var val = timeEl.value;
      if (!val) { resultEl.innerHTML = ''; return; }
      var partes = val.split(':');
      var refMin = parseInt(partes[0], 10) * 60 + parseInt(partes[1], 10);
      var refOffset = tzOffsetMinutes(refEl.value);
      var utcTotal = refMin - refOffset; // minutos desde 00:00 UTC do dia nominal, pode passar de 0..1440

      function linha(label, tz) {
        var offset = tzOffsetMinutes(tz);
        var total = utcTotal + offset;
        var dia = Math.floor(total / 1440);
        var sufixo = dia !== 0 ? ' ' + (dia > 0 ? '+' : '') + dia + 'd' : '';
        return '<div class="result-line"><span class="result-label">' + label + '</span><span class="result-value">' + hhmm(total) + sufixo + '</span></div>';
      }

      var html = linha(tr('tools.zulu.ref.utc', 'Zulu (UTC)'), 'UTC');
      BASES.forEach(function (b) { html += linha(b.icao + ' — ' + b.name, b.tz); });
      resultEl.innerHTML = html;
    }

    nowBtn.addEventListener('click', function () {
      var now = new Date();
      timeEl.value = (now.getUTCHours() < 10 ? '0' : '') + now.getUTCHours() + ':' + (now.getUTCMinutes() < 10 ? '0' : '') + now.getUTCMinutes();
      refEl.value = 'UTC';
      calc();
    });
    timeEl.addEventListener('input', calc);
    refEl.addEventListener('change', calc);
    document.addEventListener('katabatic:langchange', calc);
  })();

  /* ==================== 6. Alcance de planeio (engine-out) ==================== */
  (function () {
    var altEl = document.getElementById('glide-alt');
    var ratioEl = document.getElementById('glide-ratio');
    var windEl = document.getElementById('glide-wind');
    var resultEl = document.getElementById('glide-result');

    function calc() {
      var alt = parseFloat(altEl.value);
      var ratio = parseFloat(ratioEl.value);
      if (!isFinite(alt) || alt < 0 || !isFinite(ratio) || ratio <= 0) {
        resultEl.innerHTML = '';
        return;
      }
      var wind = parseFloat(windEl.value);
      wind = isFinite(wind) ? wind : 0;

      // Distancia base: altitude(ft) * razao de planeio / ft-por-NM.
      // Vento: mesma regra de bolso da calculadora de distancia de
      // decolagem/pouso (tool 4) - proa reduz ate 50%, cauda aumenta
      // sem teto - reaproveitada aqui por consistencia, ver comentario
      // lá.
      var baseNm = (alt * ratio) / 6076.12;
      var windFactor = wind >= 0
        ? Math.max(0.5, 1 - 0.10 * (wind / 9))
        : 1 + 0.10 * (Math.abs(wind) / 2);
      var nm = baseNm * windFactor;

      resultEl.innerHTML = '<div class="result-line"><span class="result-label">' + tr('tools.glide.result.distance', 'Alcance estimado') + '</span><span class="result-value result-value-lg">' + fmt(nm, nm < 10 ? 2 : 1) + ' NM</span></div>';
    }

    [altEl, ratioEl, windEl].forEach(function (el) { el.addEventListener('input', calc); });
    document.addEventListener('katabatic:langchange', calc);
  })();

  /* ==================== 7. Ponto ideal de descida (TOD) ==================== */
  (function () {
    var methodChips = document.querySelectorAll('#tod-method-chips .chip');
    var altEl = document.getElementById('tod-alt');
    var gsEl = document.getElementById('tod-gs');
    var rateEl = document.getElementById('tod-rate');
    var angleEl = document.getElementById('tod-angle');
    var rateFieldEl = document.getElementById('tod-rate-field');
    var angleFieldEl = document.getElementById('tod-angle-field');
    var resultEl = document.getElementById('tod-result');
    var method = 'rate';

    methodChips.forEach(function (c) {
      c.addEventListener('click', function () {
        methodChips.forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        method = c.dataset.method;
        rateFieldEl.style.display = method === 'rate' ? '' : 'none';
        angleFieldEl.style.display = method === 'angle' ? '' : 'none';
        calc();
      });
    });

    function calc() {
      var alt = parseFloat(altEl.value);
      var gs = parseFloat(gsEl.value);
      if (!isFinite(alt) || alt < 0 || !isFinite(gs) || gs <= 0) {
        resultEl.innerHTML = '';
        return;
      }

      var minutos, distNm, rateOut;
      if (method === 'rate') {
        var rate = parseFloat(rateEl.value);
        if (!isFinite(rate) || rate <= 0) { resultEl.innerHTML = ''; return; }
        minutos = alt / rate;
        distNm = (minutos / 60) * gs;
      } else {
        var angulo = parseFloat(angleEl.value);
        if (!isFinite(angulo) || angulo <= 0) { resultEl.innerHTML = ''; return; }
        var distFt = alt / Math.tan(angulo * Math.PI / 180);
        distNm = distFt / 6076.12;
        minutos = (distNm / gs) * 60;
        rateOut = minutos > 0 ? alt / minutos : 0;
      }

      var html = '<div class="result-line"><span class="result-label">' + tr('tools.tod.result.distance', 'Distância do TOD') + '</span><span class="result-value result-value-lg">' + fmt(distNm, distNm < 10 ? 2 : 1) + ' NM</span></div>';
      html += '<div class="result-line"><span class="result-label">' + tr('tools.tod.result.time', 'Tempo de descida') + '</span><span class="result-value">' + fmt(minutos, 1) + ' min</span></div>';
      if (method === 'angle') {
        html += '<div class="result-line"><span class="result-label">' + tr('tools.tod.result.rate', 'Taxa de descida recomendada') + '</span><span class="result-value">' + fmt(rateOut, 0) + ' fpm</span></div>';
      }
      resultEl.innerHTML = html;
    }

    [altEl, gsEl, rateEl, angleEl].forEach(function (el) { el.addEventListener('input', calc); });
    document.addEventListener('katabatic:langchange', calc);
  })();

  /* ==================== 8. Gerador de callsign / número de voo ==================== */
  (function () {
    var tipoChips = document.querySelectorAll('#cs-tipo-chips .chip');
    var origemEl = document.getElementById('cs-origem');
    var destinoEl = document.getElementById('cs-destino');
    var aircraftEl = document.getElementById('cs-aircraft');
    var swapBtn = document.getElementById('cs-swap');
    var resultEl = document.getElementById('cs-result');

    // Mesmo mapa dígito->tipo que novo-voo.js usa pra sugerir o tipo a
    // partir do 1º dígito do callsign (ver docblock do arquivo).
    var TIPO_DIGITO = { Carga: '1', Pessoal: '2', Pesquisa: '4', Medvec: '5', Reposicionamento: '9' };
    var tipoAtual = 'Carga';

    // callsign (maiúsculo) -> lista de rotas {origem,destino} que já
    // usaram ele de verdade (ver VooRepository::findCallsignsRotasUsados()
    // / FerramentasController) - usado só pra EVITAR sugerir um número
    // que já pertence a uma rota DIFERENTE (a mesma rota reusar seu
    // próprio número não é colisão, é o comportamento esperado).
    var VOOS_EXISTENTES = window.KATABATIC_FERRAMENTAS_VOOS_EXISTENTES || [];
    var CALLSIGN_ROTAS = {};
    VOOS_EXISTENTES.forEach(function (v) {
      var cs = (v.callsign || '').toUpperCase();
      if (!cs) return;
      (CALLSIGN_ROTAS[cs] = CALLSIGN_ROTAS[cs] || []).push({
        origem: (v.origem || '').toUpperCase(),
        destino: (v.destino || '').toUpperCase(),
      });
    });

    // Livre pra esta rota quando o callsign nunca foi usado, OU quando
    // toda vez que foi usado, foi exatamente com esta mesma origem/
    // destino (reuso esperado da mesma linha) - qualquer ocorrência com
    // origem/destino diferente conta como ocupado por outra rota.
    function callsignLivreParaRota(callsign, origem, destino) {
      var rotas = CALLSIGN_ROTAS[callsign];
      if (!rotas) return true;
      return rotas.every(function (r) { return r.origem === origem && r.destino === destino; });
    }

    AERONAVES.forEach(function (a) {
      var opt = document.createElement('option');
      opt.value = a.reg;
      opt.textContent = a.reg + ' — ' + a.tipo;
      aircraftEl.appendChild(opt);
    });

    tipoChips.forEach(function (c) {
      c.addEventListener('click', function () {
        tipoChips.forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        tipoAtual = c.dataset.tipo;
        calc();
      });
    });

    function normalizarIcao(el) {
      el.value = el.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 4);
    }

    // Hash simples e determinístico do par origem/destino (ordenado
    // alfabeticamente, pra não depender de qual lado é "origem" na
    // hora de casar ida/volta no mesmo par) - não precisa ser
    // criptográfico, só estável e bem distribuído.
    //
    // 4999 faixas (×2 pra sobrar o vizinho ímpar da volta = 9998
    // números, 4 dígitos) por tipo de operação - NÃO é matematicamente
    // livre de colisão (rotas diferentes podem cair na mesma faixa por
    // acaso; pelo paradoxo do aniversário isso só fica ~50% provável
    // depois de umas 83 rotas distintas do MESMO tipo, e só é garantido
    // depois de 4999), mas na prática, combinado com a checagem contra
    // o banco em `acharFaixaLivre()`, colisão real vira um evento raro.
    // Não trava nada se colidir mesmo assim - `Voo::$callsign` não é
    // único no banco (só `codigo`, o ID de sessão do ACARS, é - ver
    // `Entity\Voo`), então na pior das hipóteses duas rotas diferentes
    // mostram o mesmo número, sem quebrar nada, só uma inconsistência
    // de imersão. Faixa de 3 dígitos (499) trocada por essa depois de
    // pedido em conversa ("o simbrief aceita até 9 caracteres além do
    // KBT, não seria melhor já ampliar?") - sufixo final (dígito do
    // tipo + número) fica com 5 caracteres, ainda longe do limite de 9.
    function hashRota(a, b) {
      var par = [a, b].slice().sort();
      var chave = par[0] + '|' + par[1];
      var h = 0;
      for (var i = 0; i < chave.length; i++) {
        h = (h * 31 + chave.charCodeAt(i)) >>> 0;
      }
      return (h % 4999) + 1; // 1..4999
    }

    function numeroTxt(n) {
      var s = String(n);
      while (s.length < 4) { s = '0' + s; }
      return s;
    }

    // Monta o par ida/volta pra uma FAIXA específica (1..4999) - a base
    // é sempre PAR, o lado alfabeticamente menor do par origem/destino
    // usa a base, o outro lado usa o ímpar vizinho.
    function callsignsDaFaixa(slot, origem, destino, digito) {
      var par = [origem, destino].slice().sort();
      var base = slot * 2;
      var numeroIda = origem === par[0] ? base : base + 1;
      var numeroVolta = origem === par[0] ? base + 1 : base;
      return {
        ida: 'KBT' + digito + numeroTxt(numeroIda),
        volta: 'KBT' + digito + numeroTxt(numeroVolta),
      };
    }

    // Começa na faixa natural (hash da rota) e, se ela já pertencer a
    // outra rota, vai tentando a próxima (com wrap) até achar uma livre
    // pros dois sentidos - nunca trava: se as 4999 faixas do tipo
    // estiverem todas ocupadas por rotas diferentes (extremamente
    // improvável), devolve a faixa natural mesmo assim. Pedido em
    // conversa: "não precisa travar, mas priorizar sempre números não
    // utilizados".
    function acharFaixaLivre(origem, destino, digito) {
      var slotNatural = hashRota(origem, destino);
      for (var tentativa = 0; tentativa < 4999; tentativa++) {
        var slot = ((slotNatural - 1 + tentativa) % 4999) + 1;
        var cs = callsignsDaFaixa(slot, origem, destino, digito);
        if (callsignLivreParaRota(cs.ida, origem, destino) && callsignLivreParaRota(cs.volta, destino, origem)) {
          return { ida: cs.ida, volta: cs.volta, ajustado: tentativa > 0, esgotado: false };
        }
      }
      var csNatural = callsignsDaFaixa(slotNatural, origem, destino, digito);
      return { ida: csNatural.ida, volta: csNatural.volta, ajustado: false, esgotado: true };
    }

    function calc() {
      var origem = origemEl.value;
      var destino = destinoEl.value;
      if (4 !== origem.length || 4 !== destino.length) {
        resultEl.innerHTML = '';
        return;
      }
      if (origem === destino) {
        resultEl.innerHTML = '<p class="hint">' + tr('tools.callsign.hint.same', 'Origem e destino precisam ser diferentes.') + '</p>';
        return;
      }

      var digito = TIPO_DIGITO[tipoAtual];
      var achado = acharFaixaLivre(origem, destino, digito);

      var html = '<div class="result-line"><span class="result-label">' + origem + ' → ' + destino + '</span><span class="result-value result-value-lg">' + achado.ida + '</span></div>';
      html += '<div class="result-line"><span class="result-label">' + destino + ' → ' + origem + ' (' + tr('tools.callsign.result.reverse', 'sentido contrário') + ')</span><span class="result-value">' + achado.volta + '</span></div>';

      if (achado.esgotado) {
        html += '<p class="hint">' + tr('tools.callsign.hint.esgotado', 'Todas as faixas de número pra esse tipo de operação já estão em uso por outras rotas — número reaproveitado mesmo assim.') + '</p>';
      } else if (achado.ajustado) {
        html += '<p class="hint">' + tr('tools.callsign.hint.ajustado', 'Número ajustado — o número natural dessa rota já está em uso por outra rota; escolhido o próximo disponível.') + '</p>';
      }

      var aeronave = AERONAVES.filter(function (a) { return a.reg === aircraftEl.value; })[0];
      if (aeronave) {
        html += '<p class="hint">' + tr('tools.callsign.result.aircraft', 'Aeronave selecionada') + ': <b>' + aeronave.reg + '</b> — ' + aeronave.tipo + '</p>';
      }

      resultEl.innerHTML = html;
    }

    swapBtn.addEventListener('click', function () {
      var tmp = origemEl.value;
      origemEl.value = destinoEl.value;
      destinoEl.value = tmp;
      calc();
    });

    origemEl.addEventListener('input', function () { normalizarIcao(origemEl); calc(); });
    destinoEl.addEventListener('input', function () { normalizarIcao(destinoEl); calc(); });
    aircraftEl.addEventListener('change', calc);
    document.addEventListener('katabatic:langchange', calc);
  })();
})();
