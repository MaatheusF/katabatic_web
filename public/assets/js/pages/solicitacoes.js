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

  // Alem do #title, praticamente TUDO nesta tela e vocabulario de status
  // (Pendente/Aprovado/Rejeitado na tabela de Solicitações, Ativo/Inativo
  // na de Pilotos) montado na mao por renderSolic()/renderPilotos() via
  // L() (definido mais abaixo) - nao re-executa sozinho quando o idioma
  // muda, entao o listener precisa re-chamar os dois pra nao deixar as
  // tabelas presas no idioma de quando a pagina carregou.
  document.addEventListener('katabatic:langchange', function () {
    document.getElementById('title').textContent = titles[currentView][currentLang()];
    renderSolic();
    renderPilotos();
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

// Le o dicionario EN da pagina (window.KATABATIC_I18N_EN, mesclado no
// page_javascripts do template) e devolve a versao em ingles de `key`
// quando o idioma atual e EN, senao devolve `ptFallback` - usado em toda
// string que este arquivo monta na mao (fora do alcance de data-i18n, que
// so traduz o que ja esta no DOM no load). Ver a extensao do listener
// katabatic:langchange acima pra saber quando renderSolic/renderPilotos
// sao re-chamadas depois de trocar o idioma.
function L(key, ptFallback) {
  var en = window.KATABATIC_I18N_EN || {};
  return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
}

function ddmmyyyy(iso) { return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4); }

function statusTag(status) {
  var map = {
    pendente: ['warn', L('solicitacoes.status.pending', 'Pendente')],
    aprovado: ['ok', L('solicitacoes.status.approved', 'Aprovado')],
    rejeitado: ['bad', L('solicitacoes.status.rejected', 'Rejeitado')]
  };
  var m = map[status] || ['', status];
  return '<span class="tag tag-' + m[0] + '">' + m[1] + '</span>';
}

/* ---------- Solicitações ---------- */
function solRow(f) {
  var actions = f.status === 'pendente'
    ? '<div class="row-actions">' +
      '<button class="btn-approve" data-action="aprovar" data-id="' + f.id + '" type="button">' + L('solicitacoes.approve', 'Aprovar') + '</button>' +
      '<button class="btn-reject" data-action="rejeitar" data-id="' + f.id + '" type="button">' + L('solicitacoes.reject', 'Rejeitar') + '</button>' +
      '</div>'
    : '<span style="color:var(--muted);font-size:12px">—</span>';
  // E-mail e obrigatorio no formulario de adesao (sempre preenchido);
  // Discord e opcional, so entra na linha quando existe.
  var subLine = f.cid + ' · ' + f.email + (f.discord ? ' · ' + f.discord : '');
  return '<tr class="sol-row" data-id="' + f.id + '" tabindex="0" title="' + L('solicitacoes.rowtitle', 'Ver motivação completa') + '">' +
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
    '<div><span>' + L('solicitacoes.field.email', 'E-mail') + '</span><b>' + f.email + '</b></div>' +
    '<div><span>' + L('solicitacoes.field.discord', 'Discord') + '</span><b>' + (f.discord || '—') + '</b></div>' +
    '<div><span>' + L('solicitacoes.field.cid', 'CID') + '</span><b>' + f.cid + '</b></div>' +
    '<div><span>' + L('solicitacoes.field.heard', 'Como conheceu') + '</span><b>' + (f.comoConheceu || '—') + '</b></div>' +
    '</div>' +
    '<div class="detail-motiv"><span>' + L('solicitacoes.field.motivation', 'Motivação') + '</span>' + f.motivacao + '</div>' +
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
  document.getElementById('sol-count').textContent = rows.length + (rows.length === 1 ? L('solicitacoes.count.one', ' pedido') : L('solicitacoes.count.many', ' pedidos'));

  var counts = { pendente: 0, aprovado: 0, rejeitado: 0 };
  SOLIC.forEach(function (f) { counts[f.status] = (counts[f.status] || 0) + 1; });
  document.getElementById('sum-pend').textContent = counts.pendente;
  document.getElementById('sum-aprov').textContent = counts.aprovado;
  document.getElementById('sum-rej').textContent = counts.rejeitado;
  document.getElementById('sum-total').textContent = SOLIC.length;
}

/* ---------- Pilotos ---------- */
// CID do piloto logado (window.KATABATIC_CURRENT_CID, ver template) -
// nao desenha o botao de (des)ativar na propria linha, mesmo guard que
// o backend ja faz (SolicitacoesController::alternarStatus() recusa
// com 409) - aqui so evita mostrar uma acao que ia ser recusada.
var CURRENT_CID = window.KATABATIC_CURRENT_CID || null;

function pilRow(p) {
  var papelTag = p.papel === 'admin' ? '<span class="tag tag-warn">Admin</span>' : '<span class="tag">' + L('solicitacoes.pilot', 'Piloto') + '</span>';
  var ativo = p.status === 'ativo';
  var statusTagHtml = ativo ? '<span class="tag tag-ok">' + L('solicitacoes.pilot.status.active', 'Ativo') + '</span>' : '<span class="tag tag-bad">' + L('solicitacoes.pilot.status.inactive', 'Inativo') + '</span>';
  var actions = p.cid === CURRENT_CID
    ? '<span style="color:var(--muted);font-size:12px">—</span>'
    : '<button class="' + (ativo ? 'btn-reject' : 'btn-approve') + '" data-action="toggle-status" data-cid="' + p.cid + '" data-nome="' + p.nome + '" data-ativo="' + (ativo ? '1' : '0') + '" type="button">' +
      (ativo ? L('solicitacoes.pilot.deactivate', 'Desativar') : L('solicitacoes.pilot.activate', 'Ativar')) + '</button>';
  return '<tr>' +
    '<td class="mono">' + p.nome + '</td>' +
    '<td class="mono">' + p.cid + '</td>' +
    '<td class="mono">' + p.base + '</td>' +
    '<td>' + papelTag + '</td>' +
    '<td class="mono">' + ddmmyyyy(p.dataAdesao) + '</td>' +
    '<td class="num mono">' + p.voos + '</td>' +
    '<td>' + statusTagHtml + '</td>' +
    '<td>' + actions + '</td>' +
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
  document.getElementById('pil-count').textContent = rows.length + (rows.length === 1 ? L('solicitacoes.pilots.count.one', ' piloto') : L('solicitacoes.pilots.count.many', ' pilotos'));

  document.getElementById('sum-pilotos').textContent = PILOTOS.length;
  document.getElementById('sum-ativos').textContent = PILOTOS.filter(function (p) { return p.status === 'ativo'; }).length;
  document.getElementById('sum-admins').textContent = PILOTOS.filter(function (p) { return p.papel === 'admin'; }).length;
  document.getElementById('sum-voos').textContent = PILOTOS.reduce(function (s, p) { return s + (p.voos || 0); }, 0);
}

/* ---------- aprovar / rejeitar (de verdade) ----------
   POST em /solicitacoes/{id}/aprovar ou /rejeitar (ver
   SolicitacoesController) - aprovar cria um Pilot de verdade (ou liga
   o pedido a um piloto ja existente com o mesmo CID) e devolve a senha
   temporaria gerada quando um piloto novo foi criado; rejeitar so
   marca o pedido. As duas respostas trazem a solicitacao (e o piloto,
   quando aplicavel) ja atualizados, entao so precisamos mesclar no
   array local e re-renderizar - sem reload. */
var banner = document.getElementById('action-banner');
var bannerText = document.getElementById('action-banner-text');
document.getElementById('action-banner-close').addEventListener('click', function () {
  banner.style.display = 'none';
});

function showBanner(kind, html) {
  banner.className = 'action-banner action-banner-' + kind;
  bannerText.innerHTML = html;
  banner.style.display = '';
  window.scrollTo(0, 0);
}

function tempPasswordMessage(nome, cid, senha) {
  var tpl = L('solicitacoes.tempPassword', 'Conta de piloto criada para {name} (CID {cid}). Senha temporária: {password} — copie agora, ela não será mostrada de novo.');
  return tpl
    .replace('{name}', nome)
    .replace('{cid}', cid)
    .replace('{password}', '<code>' + senha + '</code>');
}

function mergePilot(piloto) {
  if (!piloto) return;
  var idx = -1;
  for (var i = 0; i < PILOTOS.length; i++) {
    if (PILOTOS[i].cid === piloto.cid) { idx = i; break; }
  }
  if (idx === -1) PILOTOS.push(piloto);
  else PILOTOS[idx] = piloto;
}

function performAction(id, action, btn) {
  var url = '/solicitacoes/' + id + '/' + action;
  btn.disabled = true;

  fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.KATABATIC_CSRF_TOKEN } })
    .then(function (res) {
      return res.json().then(function (body) { return { ok: res.ok, body: body }; });
    })
    .then(function (result) {
      if (!result.ok) {
        showBanner('error', result.body.error || L('solicitacoes.error.generic', 'Algo deu errado — tente de novo.'));
        btn.disabled = false;
        return;
      }

      var updated = result.body.solicitacao;
      var f = SOLIC.filter(function (s) { return s.id === updated.id; })[0];
      if (f) {
        f.status = updated.status;
      }

      if (result.body.piloto) {
        mergePilot(result.body.piloto);
      }
      if (result.body.senhaTemporaria) {
        showBanner('ok', tempPasswordMessage(result.body.piloto.nome, result.body.piloto.cid, result.body.senhaTemporaria));
      }

      expandedId = null;
      renderSolic();
      renderPilotos();
    })
    .catch(function () {
      showBanner('error', L('solicitacoes.error.generic', 'Algo deu errado — verifique sua conexão e tente de novo.'));
      btn.disabled = false;
    });
}

/* ---------- (des)ativar piloto ----------
   POST /solicitacoes/pilotos/{cid}/status (ver
   SolicitacoesController::alternarStatus()) - alterna Pilot::$active e
   devolve o piloto ja atualizado, mesmo padrao de performAction()
   acima (merge local + re-render, sem reload). Confirmacao so pro lado
   de desativar (ativar de volta e sempre seguro, nao precisa de
   confirm()). */
function performPilotToggle(cid, nome, ativoAtual, btn) {
  var confirmMsg = L('solicitacoes.pilot.deactivate.confirm', 'Desativar {name}? A pessoa não vai conseguir fazer login até ser reativada.').replace('{name}', nome);
  if (ativoAtual && !window.confirm(confirmMsg)) {
    return;
  }

  btn.disabled = true;

  fetch('/solicitacoes/pilotos/' + encodeURIComponent(cid) + '/status', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.KATABATIC_CSRF_TOKEN } })
    .then(function (res) {
      return res.json().then(function (body) { return { ok: res.ok, body: body }; });
    })
    .then(function (result) {
      if (!result.ok) {
        showBanner('error', result.body.error || L('solicitacoes.error.generic', 'Algo deu errado — tente de novo.'));
        btn.disabled = false;
        return;
      }
      mergePilot(result.body.piloto);
      renderPilotos();
    })
    .catch(function () {
      showBanner('error', L('solicitacoes.error.generic', 'Algo deu errado — verifique sua conexão e tente de novo.'));
      btn.disabled = false;
    });
}

document.getElementById('pil-body').addEventListener('click', function (e) {
  var btn = e.target.closest('button[data-action="toggle-status"]');
  if (!btn) return;
  performPilotToggle(btn.dataset.cid, btn.dataset.nome, btn.dataset.ativo === '1', btn);
});

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
    performAction(+btn.dataset.id, btn.dataset.action, btn);
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
