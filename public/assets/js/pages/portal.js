/* Katabatic — Portal do piloto: troca de view (Logbook/Frota/Bases),
   filtros/ordenacao do Logbook e grade/lista da Frota.
   Os dados (window.KATABATIC_LB / window.KATABATIC_FLEET) sao injetados
   pelo template a partir do PortalController — ainda mock, mesma forma
   que um Repository vai devolver depois. */
(function () {
  // O #title (h1 da barra) e trocado via JS ao navegar entre views, entao
  // nao da pra usar data-i18n nele (o lang-toggle.js so traduz uma vez, no
  // load - qualquer troca de view depois sobrescreveria com o texto fixo).
  // Por isso o dicionario dos titulos mora aqui, e a gente reage ao evento
  // katabatic:langchange pra manter o titulo certo se o idioma mudar com
  // uma view diferente de Logbook aberta.
  var titles = {
    logbook: { pt: 'Logbook', en: 'Logbook' },
    frota: { pt: 'Frota', en: 'Fleet' },
    bases: { pt: 'Bases', en: 'Bases' }
  };
  var currentView = 'logbook';

  function currentLang() { return document.documentElement.lang === 'en' ? 'en' : 'pt'; }

  function activateView(view) {
    if (!titles[view]) return;
    currentView = view;
    document.querySelectorAll('.rail a[data-view]').forEach(function (a) { a.classList.toggle('on', a.dataset.view === view); });
    document.querySelectorAll('.view').forEach(function (v) { v.classList.remove('on'); });
    var section = document.getElementById('v-' + view);
    if (section) section.classList.add('on');
    document.getElementById('title').textContent = titles[view][currentLang()];
  }

  document.addEventListener('katabatic:langchange', function () {
    document.getElementById('title').textContent = titles[currentView][currentLang()];
    // O restante do conteudo desta pagina (tabela/estatisticas do Logbook,
    // grade/lista da Frota) e montado dinamicamente por lbRender()/
    // fleetRender() mais abaixo neste arquivo - re-chama-los aqui reaplica
    // o L() de cada string com o idioma novo. A view Bases e Twig estatico
    // (data-i18n cuida dela sozinho). Guardado atras de checagem de
    // existencia do elemento/funcao porque cada view so monta seu proprio
    // conteudo (lbRender por ex. so roda se #lb-body existir na pagina).
    if (typeof lbRender === 'function' && document.getElementById('lb-body')) lbRender();
    if (typeof fleetRender === 'function' && document.getElementById('fleet-grid')) fleetRender();
  });

  // Os links do rail tem href de verdade (/portal?view=...) pra funcionar
  // mesmo vindo de outra tela (Voo, Novo voo, Nova aeronave); aqui, ja
  // estando no Portal, so trocamos a view sem recarregar a pagina. O
  // rail e compartilhado com paginas fora do Portal (ver
  // partials/_rail.html.twig) - pra admins ele tambem lista Solicitações/
  // Pilotos, que apontam pra /solicitacoes, uma rota diferente. So
  // interceptamos os data-view que esta pagina realmente possui (logbook/
  // frota/bases); qualquer outro (ex.: solicitacoes/pilotos) deixa o
  // link navegar normal, senao ficaria preso reescrevendo a URL do
  // Portal sem sair da pagina.
  document.querySelectorAll('.rail a[data-view]').forEach(function (link) {
    if (!titles[link.dataset.view]) return;
    link.addEventListener('click', function (e) {
      e.preventDefault();
      activateView(link.dataset.view);
      var url = new URL(window.location.href);
      if (link.dataset.view === 'logbook') url.searchParams.delete('view');
      else url.searchParams.set('view', link.dataset.view);
      window.history.replaceState(null, '', url);
      window.scrollTo(0, 0);
    });
  });

  // Deep link (?view=frota, por exemplo, ao voltar da tela Nova aeronave).
  var initial = new URLSearchParams(window.location.search).get('view');
  if (initial && titles[initial]) activateView(initial);
})();

// Helper generico pra tudo que este arquivo renderiza via JS (tabela do
// Logbook, estatisticas agregadas, grade/lista da Frota) - o
// data-i18n/data-i18n-attr estatico do template nao alcanca esse conteudo
// (ver cabecalho do arquivo). Le o dicionario global (definido em
// app_base.html.twig + complementado no page_javascripts do Portal) e
// devolve o valor em ingles se o idioma atual for EN e a chave existir,
// senao devolve o texto em portugues passado como fallback. Fica no
// escopo de topo do arquivo (fora da IIFE acima) porque e usado por
// lbRow/lbDetailRow/lbStatsRender/lbRender/fleetGridCard/fleetListRow
// mais abaixo, que tambem vivem no escopo de topo.
function L(key, ptFallback) {
  var en = window.KATABATIC_I18N_EN || {};
  return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
}

/* ---------- Logbook: filtros, ordenacao, densidade, paginacao ---------- */
var LB = window.KATABATIC_LB || [];
var lbState = { tipo: 'Todos', periodo: 30, busca: '', sortKey: 'data', sortDir: 'desc', page: 1 };
// Acima disso a tabela pagina em vez de tentar renderizar tudo de uma vez.
// O scroll interno do .table-scroll (portal.css) ja segura visualmente
// bem menos que isso - o limite aqui existe pra quando o Logbook virar
// consulta de verdade e puder ter centenas/milhares de voos.
var LB_PAGE_SIZE = 500;

function diffClass(v) { return v >= 70 ? 'hi' : (v >= 45 ? 'mid' : 'lo'); }

// Rotulo amigavel por condTag - usado so no painel de estatisticas
// (topo "Condicao mais comum"), pra bucketizar as strings de `cond`
// (que sao todas diferentes entre si) num numero pequeno de categorias.
var CONDLABELS = { ok: 'Boas condições', warn: 'Condições adversas', bad: 'Condições severas' };
var CONDLABEL_KEYS = { ok: 'portal.cond.good', warn: 'portal.cond.adverse', bad: 'portal.cond.severe' };
function condLabel(tag) { return L(CONDLABEL_KEYS[tag], CONDLABELS[tag]); }

// minutos -> "52min" ou "2h29" - usado no detalhe por voo (tempo ar/solo)
// e no painel de estatisticas (somatorios).
function fmtMin(min) {
  if (min == null) return '—';
  if (min < 60) return min + 'min';
  var h = Math.floor(min / 60), m = min % 60;
  return h + 'h' + (m ? (m < 10 ? '0' : '') + m : '');
}

// Linha de detalhe (colapsada por padrao) com os campos que nao cabem na
// tabela principal sem poluir - fica escondida logo abaixo da linha do
// voo, aberta via o botao "▸" (ver wiring do click delegado em lb-body).
function lbDetailRow(f) {
  return '<tr class="lb-detail" hidden><td></td><td colspan="8">' +
    '<div class="lb-detail-grid">' +
    '<div><span>' + L('portal.detail.distance', 'Distância') + '</span><b>' + f.dist + ' nm</b></div>' +
    '<div><span>' + L('portal.detail.fuel', 'Combustível') + '</span><b>' + f.combustivelKg + ' kg</b></div>' +
    '<div><span>' + L('portal.airground', 'Tempo ar / solo') + '</span><b>' + fmtMin(f.tempoArMin) + ' / ' + fmtMin(f.tempoSoloMin) + '</b></div>' +
    '<div><span>' + L('portal.detail.payload', 'Carga / payload') + '</span><b>' + f.carga + '</b></div>' +
    '<div class="lb-detail-metar"><span>' + L('portal.detail.metar', 'METAR na decolagem') + '</span><b class="mono">' + f.metar + '</b></div>' +
    '</div></td></tr>';
}

function lbRow(f) {
  var dd = f.data.slice(8, 10) + '/' + f.data.slice(5, 7) + '/' + f.data.slice(0, 4);
  // So as linhas com flightId tem telemetria real gravada (ver
  // PortalController::logbook) - so essas abrem o relatorio ao clicar.
  var attrs = f.flightId
    ? ' data-flight-id="' + f.flightId + '" tabindex="0"'
    : ' title="' + L('portal.row.notelemetry', 'Sem telemetria registrada para este voo') + '"';
  // Um voo pode ter mais de uma ocorrencia (ex.: overspeed E quique na
  // mesma perna) - f.ocorrencias e sempre uma lista (pode ser vazia).
  var ocorHtml = f.ocorrencias && f.ocorrencias.length
    ? '<div class="occ-list">' + f.ocorrencias.map(function (o) {
        return '<span class="tag tag-' + o.tag + '">' + o.label + '</span>';
      }).join('') + '</div>'
    : '<span class="tag">—</span>';
  var main = '<tr data-callsign="' + f.callsign + '"' + attrs + '>' +
    '<td class="lb-expand-col"><button class="lb-expand-btn" type="button" aria-label="' + L('portal.row.detailsaria', 'Detalhes do voo') + '" aria-expanded="false">' +
    '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>' +
    '</button></td>' +
    '<td class="mono">' + dd + '<span class="sub">' + f.hora + '</span></td>' +
    '<td class="mono">' + f.callsign + '<span class="sub">' + f.tipo + '</span></td>' +
    '<td><div class="route">' + f.origem + ' <i></i> ' + f.destino + '</div><span class="sub">' + f.rota + '</span></td>' +
    '<td class="mono">' + f.aeronave + '<span class="sub">' + f.modelo + '</span></td>' +
    '<td class="num mono">' + f.tempo + '</td>' +
    '<td><span class="tag tag-' + f.condTag + '">' + f.cond + '</span></td>' +
    '<td>' + ocorHtml + '</td>' +
    '<td class="num"><span class="diff ' + diffClass(f.dif) + '"><span class="bar-t"><i style="width:' + f.dif + '%"></i></span><b>' + f.dif + '</b></span></td>' +
    '</tr>';
  return main + lbDetailRow(f);
}

// Estatisticas agregadas do conjunto FILTRADO (nao so da pagina atual) -
// recalculada a cada lbRender, entao muda junto com os chips/periodo/
// busca. `rows` aqui e sempre o array ja filtrado (antes de paginar).
function lbStatsRender(rows) {
  var noteEl = document.getElementById('lbst-note');
  if (noteEl) noteEl.textContent = rows.length + ' ' + (rows.length === 1 ? L('portal.note.flightconsidered', 'voo considerado') : L('portal.note.flightsconsidered', 'voos considerados'));

  var distEl = document.getElementById('lbst-dist');
  var fuelEl = document.getElementById('lbst-fuel');
  var airEl = document.getElementById('lbst-airground');
  var occEl = document.getElementById('lbst-occ');
  var routeEl = document.getElementById('lbst-route');
  var acEl = document.getElementById('lbst-aircraft');
  var condEl = document.getElementById('lbst-cond');
  var trendEl = document.getElementById('lb-trend-bars');
  if (!distEl || !trendEl) return;

  if (!rows.length) {
    distEl.textContent = fuelEl.textContent = airEl.textContent = occEl.textContent = '—';
    routeEl.textContent = acEl.textContent = condEl.textContent = '—';
    trendEl.innerHTML = '<span class="lb-trend-empty">' + L('portal.stats.emptyfilter', 'Sem voos nesse filtro.') + '</span>';
    return;
  }

  var distTotal = 0, fuelTotal = 0, airTotal = 0, soloTotal = 0, occCount = 0;
  var byRoute = {}, byAircraft = {}, byCond = { ok: 0, warn: 0, bad: 0 };
  rows.forEach(function (f) {
    distTotal += f.dist || 0;
    fuelTotal += f.combustivelKg || 0;
    airTotal += f.tempoArMin || 0;
    soloTotal += f.tempoSoloMin || 0;
    if (f.ocorrencias && f.ocorrencias.length) occCount++;
    var routeKey = f.origem + ' → ' + f.destino;
    byRoute[routeKey] = (byRoute[routeKey] || 0) + 1;
    byAircraft[f.aeronave] = (byAircraft[f.aeronave] || 0) + (f.tempoMin || 0);
    if (byCond[f.condTag] != null) byCond[f.condTag]++;
  });

  function topKey(obj) {
    var best = null, bestV = -1;
    Object.keys(obj).forEach(function (k) { if (obj[k] > bestV) { bestV = obj[k]; best = k; } });
    return best === null ? null : { key: best, v: bestV };
  }

  distEl.innerHTML = distTotal.toLocaleString('pt-BR') + '<small> nm</small>';
  fuelEl.innerHTML = fuelTotal.toLocaleString('pt-BR') + '<small> kg</small>';
  airEl.textContent = fmtMin(airTotal) + ' / ' + fmtMin(soloTotal);
  occEl.innerHTML = occCount + '<small> ' + L('portal.of', 'de') + ' ' + rows.length + '</small>';

  var topRoute = topKey(byRoute);
  routeEl.textContent = topRoute ? (topRoute.key + ' (' + topRoute.v + (topRoute.v === 1 ? ' ' + L('portal.word.flight', 'voo') + ')' : ' ' + L('portal.word.flights', 'voos') + ')')) : '—';

  var topAc = topKey(byAircraft);
  acEl.textContent = topAc ? (topAc.key + ' · ' + fmtMin(topAc.v)) : '—';

  var topCond = topKey(byCond);
  condEl.textContent = topCond ? (condLabel(topCond.key) + ' (' + topCond.v + (topCond.v === 1 ? ' ' + L('portal.word.flight', 'voo') + ')' : ' ' + L('portal.word.flights', 'voos') + ')')) : '—';

  var sorted = rows.slice().sort(function (a, b) { return (a.data + a.hora) < (b.data + b.hora) ? -1 : 1; });
  trendEl.innerHTML = sorted.map(function (f) {
    return '<span class="lb-trend-bar ' + diffClass(f.dif) + '" style="height:' + Math.max(f.dif, 4) + '%" title="' +
      f.data + ' · ' + f.callsign + ' · ' + L('portal.word.difficulty', 'dificuldade') + ' ' + f.dif + '"></span>';
  }).join('');
}

function lbRender() {
  var now = window.KATABATIC_NOW ? new Date(window.KATABATIC_NOW) : new Date();
  var rows = LB.filter(function (f) {
    if (lbState.tipo !== 'Todos' && f.tipo !== lbState.tipo) return false;
    if (lbState.periodo > 0) {
      var days = (now - new Date(f.data + 'T00:00:00Z')) / 86400000;
      if (days > lbState.periodo) return false;
    }
    if (lbState.busca) {
      var q = lbState.busca.toLowerCase();
      var hay = (f.callsign + ' ' + f.origem + ' ' + f.destino + ' ' + f.rota).toLowerCase();
      if (hay.indexOf(q) === -1) return false;
    }
    return true;
  });

  lbStatsRender(rows);

  rows.sort(function (a, b) {
    var av, bv;
    if (lbState.sortKey === 'data') { av = a.data + a.hora; bv = b.data + b.hora; }
    else if (lbState.sortKey === 'tempo') { av = a.tempoMin; bv = b.tempoMin; }
    else { av = a.dif; bv = b.dif; }
    if (av < bv) return lbState.sortDir === 'asc' ? -1 : 1;
    if (av > bv) return lbState.sortDir === 'asc' ? 1 : -1;
    return 0;
  });

  var body = document.getElementById('lb-body');
  if (!body) return;

  var totalRows = rows.length;
  var totalPages = Math.max(1, Math.ceil(totalRows / LB_PAGE_SIZE));
  if (lbState.page > totalPages) lbState.page = totalPages;
  if (lbState.page < 1) lbState.page = 1;
  var pageRows = totalRows > LB_PAGE_SIZE
    ? rows.slice((lbState.page - 1) * LB_PAGE_SIZE, lbState.page * LB_PAGE_SIZE)
    : rows;

  body.innerHTML = pageRows.map(lbRow).join('');
  document.getElementById('lb-empty').style.display = totalRows ? 'none' : 'block';
  document.getElementById('filter-count').textContent = totalRows + ' ' + (totalRows === 1 ? L('portal.word.flight', 'voo') : L('portal.word.flights', 'voos'));
  document.getElementById('sum-count').textContent = totalRows;

  var pager = document.getElementById('lb-pager');
  if (pager) {
    pager.style.display = totalPages > 1 ? 'flex' : 'none';
    if (totalPages > 1) {
      document.getElementById('lb-pageinfo').textContent = L('portal.page.label', 'Página') + ' ' + lbState.page + ' ' + L('portal.of', 'de') + ' ' + totalPages;
      document.getElementById('lb-prev').disabled = lbState.page <= 1;
      document.getElementById('lb-next').disabled = lbState.page >= totalPages;
    }
  }

  document.querySelectorAll('th.sortable').forEach(function (th) {
    th.classList.toggle('active', th.dataset.sort === lbState.sortKey);
    var arw = th.querySelector('.arw');
    if (arw) arw.textContent = th.dataset.sort === lbState.sortKey ? (lbState.sortDir === 'asc' ? '▴' : '▾') : '▾';
  });
}

if (document.getElementById('lb-body')) {
  // Linhas com telemetria real (data-flight-id) abrem o relatorio do voo.
  // Delegado no tbody (nao nas <tr>, recriadas a cada lbRender) pra
  // continuar funcionando depois de filtrar/ordenar/buscar.
  document.getElementById('lb-body').addEventListener('click', function (e) {
    // Botao "▸" abre/fecha a linha de detalhe (dist/combustivel/carga/
    // METAR) sem navegar - precisa ser checado antes do clique-na-linha,
    // senao um clique no botao dentro de uma linha com flightId também
    // navegaria pra /voo.
    var expBtn = e.target.closest('.lb-expand-btn');
    if (expBtn) {
      var ownerTr = expBtn.closest('tr');
      var detail = ownerTr ? ownerTr.nextElementSibling : null;
      if (detail && detail.classList.contains('lb-detail')) {
        detail.hidden = !detail.hidden;
        expBtn.classList.toggle('open', !detail.hidden);
        expBtn.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true');
      }
      return;
    }
    var tr = e.target.closest('tr[data-flight-id]');
    if (tr) window.location.href = '/voo?id=' + encodeURIComponent(tr.dataset.flightId);
  });
  document.getElementById('lb-body').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    if (e.target.closest('.lb-expand-btn')) return; // deixa o botao tratar a propria ativacao
    var tr = e.target.closest('tr[data-flight-id]');
    if (!tr) return;
    e.preventDefault();
    window.location.href = '/voo?id=' + encodeURIComponent(tr.dataset.flightId);
  });

  document.querySelectorAll('.filters .chip[data-tipo]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      document.querySelectorAll('.filters .chip[data-tipo]').forEach(function (c) { c.classList.remove('on'); });
      chip.classList.add('on');
      lbState.tipo = chip.dataset.tipo;
      lbState.page = 1;
      lbRender();
    });
  });
  var periodoEl = document.getElementById('periodo');
  if (periodoEl) periodoEl.addEventListener('change', function (e) {
    lbState.periodo = +e.target.value; lbState.page = 1; lbRender();
  });
  var buscaEl = document.getElementById('busca');
  if (buscaEl) buscaEl.addEventListener('input', function (e) {
    lbState.busca = e.target.value.trim(); lbState.page = 1; lbRender();
  });
  document.querySelectorAll('th.sortable').forEach(function (th) {
    th.addEventListener('click', function () {
      if (lbState.sortKey === th.dataset.sort) {
        lbState.sortDir = lbState.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        lbState.sortKey = th.dataset.sort;
        lbState.sortDir = 'desc';
      }
      lbState.page = 1;
      lbRender();
    });
  });

  // Paginacao (so aparece quando ha mais de LB_PAGE_SIZE linhas filtradas -
  // ver lbRender). Volta o scroll interno pro topo a cada troca de pagina.
  var lbPrev = document.getElementById('lb-prev');
  var lbNext = document.getElementById('lb-next');
  var lbScroll = document.getElementById('lb-scroll');
  if (lbPrev) lbPrev.addEventListener('click', function () {
    lbState.page -= 1;
    lbRender();
    if (lbScroll) lbScroll.scrollTop = 0;
  });
  if (lbNext) lbNext.addEventListener('click', function () {
    lbState.page += 1;
    lbRender();
    if (lbScroll) lbScroll.scrollTop = 0;
  });

  var vtNormal = document.getElementById('vt-normal');
  var vtDense = document.getElementById('vt-dense');
  if (vtNormal && vtDense) {
    vtNormal.addEventListener('click', function () {
      vtNormal.classList.add('on'); vtDense.classList.remove('on');
      document.getElementById('lb-table').classList.remove('dense');
    });
    vtDense.addEventListener('click', function () {
      vtDense.classList.add('on'); vtNormal.classList.remove('on');
      document.getElementById('lb-table').classList.add('dense');
    });
  }

  lbRender();
}

/* ---------- Frota: grade/lista ---------- */
var FLEET = window.KATABATIC_FLEET || [];
var fleetBase = 'Todas';

// Atualizado (backend real): /aeronave/{reg} agora busca a aeronave e o
// historico de verdade no banco (AeronaveController, ver README) pra
// qualquer matricula da frota - nao existe mais uma lista fixa de "quem
// tem historico", entao o link de historico aparece pra toda linha da
// Frota (uma aeronave sem nenhum voo registrado so mostra a tela vazia,
// nao um 404).

// p.status vem de Aeronave::getStatus() (App\Entity\Aeronave) sempre em portugues -
// esse mapeamento so decide qual chave do dicionario COMMON (a mesma
// usada em Aeronave/Agendamento pra esse mesmo vocabulario) exibir, sem
// alterar p.status em si.
var STATUS_KEYS = { 'Em voo': 'common.status.inflight', 'Disponível': 'common.status.available', 'Fora de base': 'common.status.awayfrombase' };
function statusLabel(p) { return L(STATUS_KEYS[p.status] || '', p.status); }

function fleetGridCard(p) {
  var hist = '<a class="plane-hist" href="/aeronave/' + encodeURIComponent(p.reg) + '">' + L('portal.fleet.viewhistory', 'Ver histórico no mapa ›') + '</a>';
  return '<article class="plane">' +
    '<div class="plane-shot"><span>' + L('portal.fleet.photoplaceholder', 'Foto · 16:10') + '</span></div>' +
    '<div class="plane-body">' +
    '<div class="plane-head"><b>' + p.reg + '</b><span class="tag tag-' + p.statusTag + '">' + statusLabel(p) + '</span></div>' +
    '<div class="plane-type">' + p.tipo + '</div>' +
    '<div class="plane-rows">' +
    '<div><span>' + L('portal.th.base', 'Base') + '</span><b>' + p.base + '</b></div>' +
    '<div><span>' + L('portal.fleet.currentpos', 'Posição atual') + '</span><b>' + p.pos + '</b></div>' +
    '<div><span>' + L('common.hours', 'Horas') + '</span><b>' + p.horas + '</b></div>' +
    '<div><span>' + L('portal.th.lastflight', 'Último voo') + '</span><b>' + p.ultimo + '</b></div>' +
    '</div>' +
    hist +
    '</div>' +
    '</article>';
}
function fleetListRow(p) {
  var hist = '<a href="/aeronave/' + encodeURIComponent(p.reg) + '">' + L('portal.fleet.history', 'Histórico ›') + '</a>';
  return '<tr><td class="mono">' + p.reg + '</td><td>' + p.tipo + '</td>' +
    '<td><span class="tag tag-' + p.statusTag + '">' + statusLabel(p) + '</span></td>' +
    '<td class="mono">' + p.base + '</td><td class="mono">' + p.pos + '</td>' +
    '<td class="num mono">' + p.horas + '</td><td class="mono">' + p.ultimo + '</td>' +
    '<td>' + hist + '</td></tr>';
}
function fleetRender() {
  var grid = document.getElementById('fleet-grid');
  if (!grid) return;
  var rows = FLEET.filter(function (p) { return fleetBase === 'Todas' || p.base === fleetBase; });
  grid.innerHTML = rows.map(fleetGridCard).join('');
  document.getElementById('fleet-list-body').innerHTML = rows.map(fleetListRow).join('');
}

if (document.getElementById('fleet-grid')) {
  document.querySelectorAll('.filters .chip[data-base]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      fleetBase = chip.dataset.base;
      fleetRender();
    });
  });
  var vtGrid = document.getElementById('vt-grid');
  var vtList = document.getElementById('vt-list');
  if (vtGrid && vtList) {
    vtGrid.addEventListener('click', function () {
      vtGrid.classList.add('on'); vtList.classList.remove('on');
      document.getElementById('fleet-grid').style.display = '';
      document.getElementById('fleet-list').style.display = 'none';
    });
    vtList.addEventListener('click', function () {
      vtList.classList.add('on'); vtGrid.classList.remove('on');
      document.getElementById('fleet-list').style.display = '';
      document.getElementById('fleet-grid').style.display = 'none';
    });
  }
  fleetRender();
}
