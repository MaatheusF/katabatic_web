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

/* ---------- Logbook: filtros, ordenacao, densidade, paginacao ---------- */
var LB = window.KATABATIC_LB || [];
var lbState = { tipo: 'Todos', periodo: 30, busca: '', sortKey: 'data', sortDir: 'desc', page: 1 };
// Acima disso a tabela pagina em vez de tentar renderizar tudo de uma vez.
// O scroll interno do .table-scroll (portal.css) ja segura visualmente
// bem menos que isso - o limite aqui existe pra quando o Logbook virar
// consulta de verdade e puder ter centenas/milhares de voos.
var LB_PAGE_SIZE = 500;

function diffClass(v) { return v >= 70 ? 'hi' : (v >= 45 ? 'mid' : 'lo'); }

function lbRow(f) {
  var dd = f.data.slice(8, 10) + '/' + f.data.slice(5, 7) + '/' + f.data.slice(0, 4);
  // So as linhas com flightId tem telemetria real gravada (ver
  // PortalController::logbook) - so essas abrem o relatorio ao clicar.
  var attrs = f.flightId
    ? ' data-flight-id="' + f.flightId + '" tabindex="0"'
    : ' title="Sem telemetria registrada para este voo"';
  // Um voo pode ter mais de uma ocorrencia (ex.: overspeed E quique na
  // mesma perna) - f.ocorrencias e sempre uma lista (pode ser vazia).
  var ocorHtml = f.ocorrencias && f.ocorrencias.length
    ? '<div class="occ-list">' + f.ocorrencias.map(function (o) {
        return '<span class="tag tag-' + o.tag + '">' + o.label + '</span>';
      }).join('') + '</div>'
    : '<span class="tag">—</span>';
  return '<tr data-callsign="' + f.callsign + '"' + attrs + '>' +
    '<td class="mono">' + dd + '<span class="sub">' + f.hora + '</span></td>' +
    '<td class="mono">' + f.callsign + '<span class="sub">' + f.tipo + '</span></td>' +
    '<td><div class="route">' + f.origem + ' <i></i> ' + f.destino + '</div><span class="sub">' + f.rota + '</span></td>' +
    '<td class="mono">' + f.aeronave + '<span class="sub">' + f.modelo + '</span></td>' +
    '<td class="num mono">' + f.tempo + '</td>' +
    '<td><span class="tag tag-' + f.condTag + '">' + f.cond + '</span></td>' +
    '<td>' + ocorHtml + '</td>' +
    '<td class="num"><span class="diff ' + diffClass(f.dif) + '"><span class="bar-t"><i style="width:' + f.dif + '%"></i></span><b>' + f.dif + '</b></span></td>' +
    '</tr>';
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
  document.getElementById('filter-count').textContent = totalRows + (totalRows === 1 ? ' voo' : ' voos');
  document.getElementById('sum-count').textContent = totalRows;

  var pager = document.getElementById('lb-pager');
  if (pager) {
    pager.style.display = totalPages > 1 ? 'flex' : 'none';
    if (totalPages > 1) {
      document.getElementById('lb-pageinfo').textContent = 'Página ' + lbState.page + ' de ' + totalPages;
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
    var tr = e.target.closest('tr[data-flight-id]');
    if (tr) window.location.href = '/voo?id=' + encodeURIComponent(tr.dataset.flightId);
  });
  document.getElementById('lb-body').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
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

// So as 6 matriculas mock da frota tem historico de pernas montado
// (AeronaveController::aircraftLegs) - o link so aparece pra elas, pra
// nao levar pra uma /aeronave/{reg} vazia se a frota crescer sem o
// historico correspondente ainda existir.
var AC_HISTORY_REGS = ['CC-KBA', 'CC-KBC', 'CC-KBD', 'N208KB', 'N412KB', 'N67KB'];

function fleetGridCard(p) {
  var hist = AC_HISTORY_REGS.indexOf(p.reg) !== -1
    ? '<a class="plane-hist" href="/aeronave/' + encodeURIComponent(p.reg) + '">Ver histórico no mapa ›</a>' : '';
  return '<article class="plane">' +
    '<div class="plane-shot"><span>Foto · 16:10</span></div>' +
    '<div class="plane-body">' +
    '<div class="plane-head"><b>' + p.reg + '</b><span class="tag tag-' + p.statusTag + '">' + p.status + '</span></div>' +
    '<div class="plane-type">' + p.tipo + '</div>' +
    '<div class="plane-rows">' +
    '<div><span>Base</span><b>' + p.base + '</b></div>' +
    '<div><span>Posição atual</span><b>' + p.pos + '</b></div>' +
    '<div><span>Horas</span><b>' + p.horas + '</b></div>' +
    '<div><span>Último voo</span><b>' + p.ultimo + '</b></div>' +
    '</div>' +
    hist +
    '</div>' +
    '</article>';
}
function fleetListRow(p) {
  var hist = AC_HISTORY_REGS.indexOf(p.reg) !== -1
    ? '<a href="/aeronave/' + encodeURIComponent(p.reg) + '">Histórico ›</a>' : '<span style="color:var(--muted)">—</span>';
  return '<tr><td class="mono">' + p.reg + '</td><td>' + p.tipo + '</td>' +
    '<td><span class="tag tag-' + p.statusTag + '">' + p.status + '</span></td>' +
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
