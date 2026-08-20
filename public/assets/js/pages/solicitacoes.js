/* ==========================================================================
   Katabatic — solicitacoes.js
   Grid de administração: troca de view (Solicitações/Pilotos), filtros,
   expandir motivação e aprovar/rejeitar pedidos (mock, só nesta sessão
   do navegador). Dados injetados pelo SolicitacoesController via
   window.KATABATIC_SOLICITACOES / window.KATABATIC_PILOTOS.
   ========================================================================== */
(function () {
  // Mesmo padrao de troca-de-view do portal.js (titulo dinamico, deep
  // link ?view=, rail com href real). O rail e compartilhado com o
  // Portal (Logbook/Frota/Bases apontam pra /portal) - so interceptamos
  // os data-view que esta pagina possui, senao os links do Portal
  // ficariam presos reescrevendo a URL desta pagina sem navegar.
  var titles = {
    solicitacoes: { pt: 'Solicitações', en: 'Applications' },
    pilotos: { pt: 'Pilotos', en: 'Pilots' }
  };
  var currentView = 'solicitacoes';

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

  document.querySelectorAll('.rail a[data-view]').forEach(function (link) {
    if (!titles[link.dataset.view]) return;
    link.addEventListener('click', function (e) {
      e.preventDefault();
      activateView(link.dataset.view);
      var url = new URL(window.location.href);
      if (link.dataset.view === 'solicitacoes') url.searchParams.delete('view');
      else url.searchParams.set('view', link.dataset.view);
      window.history.replaceState(null, '', url);
      window.scrollTo(0, 0);
    });
  });

  var initial = new URLSearchParams(window.location.search).get('view');
  if (initial && titles[initial]) activateView(initial);
})();

/* ---------- dados + estado ---------- */
var SOLIC = window.KATABATIC_SOLICITACOES || [];
var PILOTOS = window.KATABATIC_PILOTOS || [];
var solState = { status: 'Todos', busca: '' };
var pilState = { busca: '' };
var expandedId = null;

function ddmmyyyy(iso) { return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4); }

function statusTag(status) {
  var map = { pendente: ['warn', 'Pendente'], aprovado: ['ok', 'Aprovado'], rejeitado: ['bad', 'Rejeitado'] };
  var m = map[status] || ['', status];
  return '<span class="tag tag-' + m[0] + '">' + m[1] + '</span>';
}

/* ---------- Solicitações ---------- */
function solRow(f) {
  var actions = f.status === 'pendente'
    ? '<div class="row-actions">' +
      '<button class="btn-approve" data-action="aprovar" data-id="' + f.id + '" type="button">Aprovar</button>' +
      '<button class="btn-reject" data-action="rejeitar" data-id="' + f.id + '" type="button">Rejeitar</button>' +
      '</div>'
    : '<span style="color:var(--muted);font-size:12px">—</span>';
  // E-mail e obrigatorio no formulario de adesao (sempre preenchido);
  // Discord e opcional, so entra na linha quando existe.
  var subLine = f.cid + ' · ' + f.email + (f.discord ? ' · ' + f.discord : '');
  return '<tr class="sol-row" data-id="' + f.id + '" tabindex="0" title="Ver motivação completa">' +
    '<td class="mono">' + ddmmyyyy(f.data) + '</td>' +
    '<td>' + f.nome + '<span class="sub">' + subLine + '</span></td>' +
    '<td>' + f.basePref + '</td>' +
    '<td>' + f.experiencia + '</td>' +
    '<td>' + statusTag(f.status) + '</td>' +
    '<td>' + actions + '</td>' +
    '</tr>';
}

function detailRow(f) {
  return '<tr class="detail-row" data-detail-for="' + f.id + '"><td colspan="6">' +
    '<div class="detail-grid">' +
    '<div><span>E-mail</span><b>' + f.email + '</b></div>' +
    '<div><span>Discord</span><b>' + (f.discord || '—') + '</b></div>' +
    '<div><span>CID</span><b>' + f.cid + '</b></div>' +
    '<div><span>Como conheceu</span><b>' + (f.comoConheceu || '—') + '</b></div>' +
    '</div>' +
    '<div class="detail-motiv"><span>Motivação</span>' + f.motivacao + '</div>' +
    '</td></tr>';
}

function filteredSolic() {
  return SOLIC.filter(function (f) {
    if (solState.status !== 'Todos' && f.status !== solState.status) return false;
    if (solState.busca) {
      var q = solState.busca.toLowerCase();
      var hay = (f.nome + ' ' + f.cid + ' ' + f.email + ' ' + (f.discord || '')).toLowerCase();
      if (hay.indexOf(q) === -1) return false;
    }
    return true;
  });
}

function renderSolic() {
  var rows = filteredSolic();
  var html = '';
  rows.forEach(function (f) {
    html += solRow(f);
    if (expandedId === f.id) html += detailRow(f);
  });
  document.getElementById('sol-body').innerHTML = html;
  document.getElementById('sol-empty').style.display = rows.length ? 'none' : 'block';
  document.getElementById('sol-count').textContent = rows.length + (rows.length === 1 ? ' pedido' : ' pedidos');

  var counts = { pendente: 0, aprovado: 0, rejeitado: 0 };
  SOLIC.forEach(function (f) { counts[f.status] = (counts[f.status] || 0) + 1; });
  document.getElementById('sum-pend').textContent = counts.pendente;
  document.getElementById('sum-aprov').textContent = counts.aprovado;
  document.getElementById('sum-rej').textContent = counts.rejeitado;
  document.getElementById('sum-total').textContent = SOLIC.length;
}

/* ---------- Pilotos ---------- */
function pilRow(p) {
  var papelTag = p.papel === 'admin' ? '<span class="tag tag-warn">Admin</span>' : '<span class="tag">Piloto</span>';
  var statusTagHtml = p.status === 'ativo' ? '<span class="tag tag-ok">Ativo</span>' : '<span class="tag tag-bad">Inativo</span>';
  return '<tr>' +
    '<td class="mono">' + p.nome + '</td>' +
    '<td class="mono">' + p.cid + '</td>' +
    '<td class="mono">' + p.base + '</td>' +
    '<td>' + papelTag + '</td>' +
    '<td class="mono">' + ddmmyyyy(p.dataAdesao) + '</td>' +
    '<td class="num mono">' + p.voos + '</td>' +
    '<td>' + statusTagHtml + '</td>' +
    '</tr>';
}

function filteredPilotos() {
  if (!pilState.busca) return PILOTOS;
  var q = pilState.busca.toLowerCase();
  return PILOTOS.filter(function (p) { return (p.nome + ' ' + p.cid).toLowerCase().indexOf(q) !== -1; });
}

function renderPilotos() {
  var rows = filteredPilotos();
  document.getElementById('pil-body').innerHTML = rows.map(pilRow).join('');
  document.getElementById('pil-empty').style.display = rows.length ? 'none' : 'block';
  document.getElementById('pil-count').textContent = rows.length + (rows.length === 1 ? ' piloto' : ' pilotos');

  document.getElementById('sum-pilotos').textContent = PILOTOS.length;
  document.getElementById('sum-ativos').textContent = PILOTOS.filter(function (p) { return p.status === 'ativo'; }).length;
  document.getElementById('sum-admins').textContent = PILOTOS.filter(function (p) { return p.papel === 'admin'; }).length;
  document.getElementById('sum-voos').textContent = PILOTOS.reduce(function (s, p) { return s + (p.voos || 0); }, 0);
}

/* ---------- aprovar / rejeitar (mock) ----------
   So muda o array em memoria desta pagina - sem POST, sem persistir.
   Aprovar tambem adiciona (se ainda nao existir pelo CID) uma linha na
   tabela de Pilotos, pra deixar visivel o efeito esperado da acao;
   recarregar a pagina volta pro estado mock original (ver README). */
function setStatus(id, status) {
  var f = SOLIC.filter(function (s) { return s.id === id; })[0];
  if (!f) return;
  f.status = status;
  if (status === 'aprovado') {
    var already = PILOTOS.some(function (p) { return p.cid === f.cid; });
    if (!already) {
      PILOTOS.push({
        nome: f.nome,
        cid: f.cid,
        base: f.basePref === 'Sem preferência' ? 'PAFA' : f.basePref,
        papel: 'piloto',
        dataAdesao: '2026-08-19',
        voos: 0,
        status: 'ativo'
      });
    }
  }
  expandedId = null;
  renderSolic();
  renderPilotos();
}

/* ---------- eventos ---------- */
document.querySelectorAll('.filters .chip[data-status]').forEach(function (chip) {
  chip.addEventListener('click', function () {
    document.querySelectorAll('.filters .chip[data-status]').forEach(function (c) { c.classList.remove('on'); });
    chip.classList.add('on');
    solState.status = chip.dataset.status;
    expandedId = null;
    renderSolic();
  });
});
document.getElementById('sol-busca').addEventListener('input', function (e) {
  solState.busca = e.target.value.trim();
  renderSolic();
});
document.getElementById('pil-busca').addEventListener('input', function (e) {
  pilState.busca = e.target.value.trim();
  renderPilotos();
});

document.getElementById('sol-body').addEventListener('click', function (e) {
  var btn = e.target.closest('button[data-action]');
  if (btn) {
    e.stopPropagation();
    setStatus(+btn.dataset.id, btn.dataset.action === 'aprovar' ? 'aprovado' : 'rejeitado');
    return;
  }
  var tr = e.target.closest('tr.sol-row');
  if (!tr) return;
  var id = +tr.dataset.id;
  expandedId = expandedId === id ? null : id;
  renderSolic();
});
document.getElementById('sol-body').addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  var tr = e.target.closest('tr.sol-row');
  if (!tr) return;
  e.preventDefault();
  tr.click();
});

renderSolic();
renderPilotos();
