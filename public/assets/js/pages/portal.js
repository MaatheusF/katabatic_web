/* Katabatic — Portal do piloto: troca de view (Logbook/Frota/Bases),
   filtros/ordenacao do Logbook e grade/lista da Frota.
   Os dados (window.KATABATIC_LB / window.KATABATIC_FLEET) sao injetados
   pelo template a partir do PortalController — ainda mock, mesma forma
   que um Repository vai devolver depois. */
(function () {
  var titles = { logbook: 'Logbook', frota: 'Frota', bases: 'Bases' };
  document.querySelectorAll('.rail a[data-view]').forEach(function (link) {
    link.addEventListener('click', function () {
      document.querySelectorAll('.rail a[data-view]').forEach(function (a) { a.classList.remove('on'); });
      link.classList.add('on');
      document.querySelectorAll('.view').forEach(function (v) { v.classList.remove('on'); });
      document.getElementById('v-' + link.dataset.view).classList.add('on');
      document.getElementById('title').textContent = titles[link.dataset.view];
      window.scrollTo(0, 0);
    });
  });
})();

/* ---------- Logbook: filtros, ordenacao, densidade ---------- */
var LB = window.KATABATIC_LB || [];
var lbState = { tipo: 'Todos', periodo: 30, busca: '', sortKey: 'data', sortDir: 'desc' };

function diffClass(v) { return v >= 70 ? 'hi' : (v >= 45 ? 'mid' : 'lo'); }

function lbRow(f) {
  var dd = f.data.slice(8, 10) + '/' + f.data.slice(5, 7);
  // TODO: apontar para a rota do relatorio de voo (katabatic-voo.html) quando ela existir.
  return '<tr data-callsign="' + f.callsign + '">' +
    '<td class="mono">' + dd + '<span class="sub">' + f.hora + '</span></td>' +
    '<td class="mono">' + f.callsign + '<span class="sub">' + f.tipo + '</span></td>' +
    '<td><div class="route">' + f.origem + ' <i></i> ' + f.destino + '</div><span class="sub">' + f.rota + '</span></td>' +
    '<td class="mono">' + f.aeronave + '<span class="sub">' + f.modelo + '</span></td>' +
    '<td class="num mono">' + f.tempo + '</td>' +
    '<td><span class="tag tag-' + f.condTag + '">' + f.cond + '</span></td>' +
    '<td>' + (f.ocor ? '<span class="tag tag-' + f.ocorTag + '">' + f.ocor + '</span>' : '<span class="tag">—</span>') + '</td>' +
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
  body.innerHTML = rows.map(lbRow).join('');
  document.getElementById('lb-empty').style.display = rows.length ? 'none' : 'block';
  document.getElementById('filter-count').textContent = rows.length + (rows.length === 1 ? ' voo' : ' voos');
  document.getElementById('sum-count').textContent = rows.length;

  document.querySelectorAll('th.sortable').forEach(function (th) {
    th.classList.toggle('active', th.dataset.sort === lbState.sortKey);
    var arw = th.querySelector('.arw');
    if (arw) arw.textContent = th.dataset.sort === lbState.sortKey ? (lbState.sortDir === 'asc' ? '▴' : '▾') : '▾';
  });
}

if (document.getElementById('lb-body')) {
  document.querySelectorAll('.filters .chip[data-tipo]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      lbState.tipo = chip.dataset.tipo;
      lbRender();
    });
  });
  var periodoEl = document.getElementById('periodo');
  if (periodoEl) periodoEl.addEventListener('change', function (e) {
    lbState.periodo = +e.target.value; lbRender();
  });
  var buscaEl = document.getElementById('busca');
  if (buscaEl) buscaEl.addEventListener('input', function (e) {
    lbState.busca = e.target.value.trim(); lbRender();
  });
  document.querySelectorAll('th.sortable').forEach(function (th) {
    th.addEventListener('click', function () {
      if (lbState.sortKey === th.dataset.sort) {
        lbState.sortDir = lbState.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        lbState.sortKey = th.dataset.sort;
        lbState.sortDir = 'desc';
      }
      lbRender();
    });
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

function fleetGridCard(p) {
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
    '</div>' +
    '</article>';
}
function fleetListRow(p) {
  return '<tr><td class="mono">' + p.reg + '</td><td>' + p.tipo + '</td>' +
    '<td><span class="tag tag-' + p.statusTag + '">' + p.status + '</span></td>' +
    '<td class="mono">' + p.base + '</td><td class="mono">' + p.pos + '</td>' +
    '<td class="num mono">' + p.horas + '</td><td class="mono">' + p.ultimo + '</td></tr>';
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
