/* ==========================================================================
   Katabatic — agendamento.js
   Agendamento de voo: um card por aeronave da frota mostrando a sequência
   de pernas futuras já reservadas (quando a próxima perna sai de onde a
   anterior chega, elas contam como uma sequência encadeada — ver
   buildWaypoints/legChainInfo) e um formulário pra criar um novo
   agendamento. Tudo mock: window.KATABATIC_AG_AGENDAMENTOS só dá o estado
   inicial (via AgendamentoController), o resto vira um array em memória
   nesta página (sem persistir entre reloads), mesmo padrão de
   solicitacoes.js.
   ========================================================================== */
(function () {
  'use strict';

  var FLEET = window.KATABATIC_AG_FLEET || [];
  var AGENDAMENTOS = (window.KATABATIC_AG_AGENDAMENTOS || []).slice();
  var AIRPORTS = {};
  var nextId = AGENDAMENTOS.reduce(function (m, a) { return Math.max(m, a.id); }, 0) + 1;
  var HIGHLIGHT_REG = null, HIGHLIGHT_ID = null;
  var EDITING_ID = null; // id do agendamento em edição no formulário, ou null quando é um novo
  var NOW = window.KATABATIC_NOW ? new Date(window.KATABATIC_NOW) : new Date();

  // Le o dicionario EN da pagina (window.KATABATIC_I18N_EN, mesclado no
  // page_javascripts do template) e devolve a versao em ingles de `key`
  // quando o idioma atual e EN, senao devolve `ptFallback` - usado em toda
  // string que este arquivo monta na mao (fora do alcance de data-i18n,
  // que so traduz o que ja esta no DOM no load). Ver a extensao do
  // listener katabatic:langchange no fim do arquivo pra saber quando as
  // funcoes de render sao re-chamadas depois de trocar o idioma.
  function L(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  // Vocabulario de status compartilhado com Portal/Aeronave/Mapa ao vivo
  // (common.status.* no dicionario global) - reaproveitado aqui pro badge
  // de status de cada card de aeronave, pra nao inventar uma variante
  // diferente (ex.: "Flying") da mesma palavra.
  var STATUS_LABEL_KEY = {
    'Em voo': 'common.status.inflight',
    'Disponível': 'common.status.available',
    'Fora de base': 'common.status.awayfrombase'
  };
  function statusLabel(pt) {
    var key = STATUS_LABEL_KEY[pt];
    return key ? L(key, pt) : pt;
  }

  /* ---------- helpers de data ---------- */
  function pad2(n) { return String(n).padStart(2, '0'); }

  function toIso(dateStr, timeStr, addDay) {
    if (!dateStr || !timeStr) return null;
    var d = new Date(dateStr + 'T' + timeStr + ':00Z');
    if (addDay) d.setUTCDate(d.getUTCDate() + 1);
    return d.toISOString();
  }

  // "Hoje"/"Amanhã"/dd-mm em relação a `NOW` (window.KATABATIC_NOW) - usado
  // tanto nas tabelas por aeronave quanto na Agenda da semana, pra não
  // precisar ficar lendo a data por extenso pra saber o que é iminente.
  function dayKeyOf(dateObjOrIso) {
    var d = dateObjOrIso instanceof Date ? dateObjOrIso : new Date(dateObjOrIso);
    return d.toISOString().slice(0, 10);
  }
  function dayLabel(iso) {
    var k = dayKeyOf(iso);
    if (k === dayKeyOf(NOW)) return L('agendamento.today', 'Hoje');
    if (k === dayKeyOf(new Date(NOW.getTime() + 86400000))) return L('agendamento.tomorrow', 'Amanhã');
    var d = new Date(iso);
    return pad2(d.getUTCDate()) + '/' + pad2(d.getUTCMonth() + 1);
  }

  function fmtTimeRange(deIso, ateIso) {
    var d = new Date(deIso), a = new Date(ateIso);
    var hh = function (x) { return pad2(x.getUTCHours()) + ':' + pad2(x.getUTCMinutes()); };
    var sameDay = d.getUTCDate() === a.getUTCDate() && d.getUTCMonth() === a.getUTCMonth();
    return hh(d) + '–' + hh(a) + 'Z' + (sameDay ? '' : ' (+1d)');
  }

  function fmtWindow(deIso, ateIso) {
    return dayLabel(deIso) + ' ' + fmtTimeRange(deIso, ateIso);
  }

  /* ---------- consultas sobre os agendamentos ---------- */
  function legsByReg(reg) {
    return AGENDAMENTOS.filter(function (a) { return a.reg === reg; }).sort(function (a, b) { return a.de < b.de ? -1 : (a.de > b.de ? 1 : 0); });
  }

  function fleetOf(reg) { return FLEET.filter(function (a) { return a.reg === reg; })[0] || null; }

  // Pra cada perna (índice i dentro da lista já ordenada `legs` da mesma
  // aeronave), diz se ela "continua" de onde a anterior terminou - a
  // primeira perna compara com a posição atual da aeronave (`ac.pos`),
  // as seguintes comparam com o destino da perna anterior.
  function legChainInfo(ac, legs, i) {
    if (i === 0) {
      var ok0 = legs[0].origem === ac.pos;
      return {
        ok: ok0,
        label: ok0
          ? L('agendamento.chain.departs', 'Sai de {icao}').replace('{icao}', ac.pos)
          : L('agendamento.chain.aircraftat', 'Aeronave está em {icao}').replace('{icao}', ac.pos)
      };
    }
    var prev = legs[i - 1];
    var ok = legs[i].origem === prev.destino;
    return {
      ok: ok,
      label: ok
        ? L('agendamento.chain.continues', 'Continua de {icao}').replace('{icao}', prev.destino)
        : L('agendamento.chain.changes', 'Muda de {a} → {b}').replace('{a}', prev.destino).replace('{b}', legs[i].origem)
    };
  }

  // Onde a aeronave "deveria" estar pra sair num horário `beforeIso`:
  // destino da última perna já agendada que termina antes disso, ou a
  // posição atual se não houver nenhuma - usado pra sugerir e validar o
  // campo Origem do formulário. `excludeId` tira o próprio agendamento
  // da conta quando está editando (senão ele "colide" com ele mesmo).
  function expectedOrigem(reg, beforeIso, excludeId) {
    var ac = fleetOf(reg);
    var prior = legsByReg(reg)
      .filter(function (l) { return l.ate <= beforeIso && l.id !== excludeId; })
      .sort(function (a, b) { return a.ate < b.ate ? 1 : -1; })[0];
    return prior ? prior.destino : (ac ? ac.pos : null);
  }

  function hasOverlap(reg, deIso, ateIso, excludeId) {
    var deT = new Date(deIso).getTime(), ateT = new Date(ateIso).getTime();
    return AGENDAMENTOS.some(function (a) {
      if (a.reg !== reg || a.id === excludeId) return false;
      var aDe = new Date(a.de).getTime(), aAte = new Date(a.ate).getTime();
      return deT < aAte && aDe < ateT;
    });
  }

  /* ---------- linha do tempo resumida por aeronave ---------- */
  function buildWaypoints(ac, legs) {
    if (!legs.length) return [];
    var wp = [{ icao: ac.pos, connector: null }];
    legs.forEach(function (leg, i) {
      var info = legChainInfo(ac, legs, i);
      if (!info.ok) wp.push({ icao: leg.origem, connector: 'warn' });
      wp.push({ icao: leg.destino, connector: 'ok' });
    });
    return wp;
  }

  function chainHtml(wp) {
    return wp.map(function (w, i) {
      var arrow = i > 0 ? '<span class="ag-arrow' + (w.connector === 'warn' ? ' warn' : '') + '">→</span>' : '';
      return arrow + '<span class="ag-node">' + w.icao + '</span>';
    }).join('');
  }

  /* ---------- tabela de pernas por aeronave ---------- */
  function legTableRow(ac, legs, i) {
    var leg = legs[i];
    var info = legChainInfo(ac, legs, i);
    var cls = leg.id === HIGHLIGHT_ID ? ' class="ag-new-row"' : '';
    return '<tr' + cls + '>' +
      '<td class="route">' + leg.origem + ' → ' + leg.destino + '</td>' +
      '<td>' + fmtWindow(leg.de, leg.ate) + '</td>' +
      '<td>' + leg.tipo + '</td>' +
      '<td>' + leg.piloto + (leg.notas ? '<span class="sub">' + leg.notas + '</span>' : '') + '</td>' +
      '<td><span class="tag tag-' + (info.ok ? 'ok' : 'warn') + '">' + info.label + '</span></td>' +
      '<td><div class="row-actions">' +
      '<button class="btn-edit" data-id="' + leg.id + '" type="button">' + L('common.edit', 'Editar') + '</button>' +
      '<button class="btn-remove" data-id="' + leg.id + '" data-armed="0" type="button">' + L('common.remove', 'Remover') + '</button>' +
      '</div></td>' +
      '</tr>';
  }

  function cardHtml(ac) {
    var legs = legsByReg(ac.reg);
    var chain = legs.length ? chainHtml(buildWaypoints(ac, legs)) : '<span class="ag-node muted">' + ac.pos + '</span>';
    var body;
    if (!legs.length) {
      body = ac.status === 'Em voo'
        ? '<p class="ag-empty flying">' + L('agendamento.card.flying', 'Em voo agora — assim que pousar, dá pra agendar a próxima perna.') + '</p>'
        : '<p class="ag-empty">' + L('agendamento.card.empty', 'Nenhum voo agendado.') + '</p>';
    } else {
      body = '<div class="ag-table-wrap"><table class="ag-table"><thead><tr><th>' + L('common.route', 'Rota') + '</th><th>' + L('agendamento.window', 'Janela') + '</th><th>' + L('agendamento.type', 'Tipo') + '</th><th>' + L('agendamento.pilot', 'Piloto') + '</th><th>' + L('agendamento.sequence', 'Sequência') + '</th><th>' + L('common.actions', 'Ações') + '</th></tr></thead><tbody>' +
        legs.map(function (l, i) { return legTableRow(ac, legs, i); }).join('') +
        '</tbody></table></div>';
    }
    return '<div class="card ag-card" data-reg="' + ac.reg + '">' +
      '<div class="card-h ag-card-h">' +
      '<div class="ag-ac"><b>' + ac.reg + '</b><span>' + ac.tipo + '</span></div>' +
      '<span class="tag tag-' + ac.statusTag + '">' + statusLabel(ac.status) + '</span>' +
      '<button class="btn btn-ghost ag-add-btn" data-reg="' + ac.reg + '" type="button">' + L('agendamento.addbtn', '+ Agendar') + '</button>' +
      '</div>' +
      '<div class="ag-chain">' + chain + '</div>' +
      body +
      '</div>';
  }

  function renderCards() {
    document.getElementById('ag-cards').innerHTML = FLEET.map(cardHtml).join('');
    document.querySelectorAll('.ag-add-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { openForm(btn.dataset.reg, null); });
    });
    if (HIGHLIGHT_REG) {
      var card = document.querySelector('.ag-card[data-reg="' + HIGHLIGHT_REG + '"]');
      if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function editLeg(id) {
    var entry = AGENDAMENTOS.filter(function (a) { return a.id === id; })[0];
    if (entry) openForm(entry.reg, entry);
  }

  function removeLeg(id) {
    AGENDAMENTOS = AGENDAMENTOS.filter(function (a) { return a.id !== id; });
    if (EDITING_ID === id) closeForm();
    HIGHLIGHT_REG = null;
    HIGHLIGHT_ID = null;
    renderSummary();
    renderAgenda();
    renderCards();
  }

  // Delegado (anexado uma única vez em wireForm) em vez de religar por
  // linha a cada render - clique em "Remover" arma um estado de
  // confirmação por 4s ("Confirmar?") antes de remover de verdade, sem
  // depender de um confirm() nativo do navegador.
  function wireCardActions() {
    document.getElementById('ag-cards').addEventListener('click', function (e) {
      var editBtn = e.target.closest('.btn-edit');
      if (editBtn) { editLeg(+editBtn.dataset.id); return; }

      var rmBtn = e.target.closest('.btn-remove');
      if (!rmBtn) return;
      if (rmBtn.dataset.armed === '1') {
        removeLeg(+rmBtn.dataset.id);
        return;
      }
      rmBtn.dataset.armed = '1';
      rmBtn.textContent = L('agendamento.confirmremove', 'Confirmar?');
      clearTimeout(rmBtn._agTimer);
      rmBtn._agTimer = setTimeout(function () {
        rmBtn.dataset.armed = '0';
        rmBtn.textContent = L('common.remove', 'Remover');
      }, 4000);
    });
  }

  /* ---------- agenda da semana (cronológica, cruzando todas as aeronaves) ---------- */
  function allLegsSorted() {
    return AGENDAMENTOS.slice().sort(function (a, b) { return a.de < b.de ? -1 : (a.de > b.de ? 1 : 0); });
  }

  function agendaRows(searchQ) {
    var legs = allLegsSorted();
    var q = (searchQ || '').toLowerCase().trim();
    if (q) {
      legs = legs.filter(function (l) {
        var hay = (l.reg + ' ' + l.piloto + ' ' + l.origem + ' ' + l.destino + ' ' + l.tipo).toLowerCase();
        return hay.indexOf(q) !== -1;
      });
    }
    return legs;
  }

  function agendaRowHtml(leg) {
    var ac = fleetOf(leg.reg);
    var legsOfAc = legsByReg(leg.reg);
    var idx = legsOfAc.indexOf(leg);
    if (idx === -1) idx = 0;
    var info = ac ? legChainInfo(ac, legsOfAc, idx) : { ok: true, label: '' };
    return '<tr>' +
      '<td class="mono">' + fmtTimeRange(leg.de, leg.ate) + '</td>' +
      '<td class="mono">' + leg.reg + '</td>' +
      '<td class="route">' + leg.origem + ' → ' + leg.destino + '</td>' +
      '<td>' + leg.tipo + '</td>' +
      '<td>' + leg.piloto + '</td>' +
      '<td><span class="tag tag-' + (info.ok ? 'ok' : 'warn') + '">' + info.label + '</span></td>' +
      '</tr>';
  }

  function renderAgenda() {
    var searchEl = document.getElementById('ag-search');
    var legs = agendaRows(searchEl ? searchEl.value : '');

    var html = '';
    var lastDayKey = null;
    legs.forEach(function (leg) {
      var k = dayKeyOf(leg.de);
      if (k !== lastDayKey) {
        lastDayKey = k;
        html += '<tr class="ag-day-row"><td colspan="6">' + dayLabel(leg.de) + ' · ' + k.slice(8, 10) + '/' + k.slice(5, 7) + '</td></tr>';
      }
      html += agendaRowHtml(leg);
    });

    document.getElementById('ag-agenda-body').innerHTML = html;
    document.getElementById('ag-agenda-table').style.display = legs.length ? '' : 'none';
    document.getElementById('ag-agenda-empty').style.display = legs.length ? 'none' : 'block';
    document.getElementById('ag-agenda-count').textContent = legs.length + (legs.length === 1 ? L('agendamento.count.one', ' voo agendado') : L('agendamento.count.many', ' voos agendados'));
  }

  function renderSummary() {
    document.getElementById('sum-total').textContent = AGENDAMENTOS.length;

    var byReg = {};
    AGENDAMENTOS.forEach(function (a) { (byReg[a.reg] = byReg[a.reg] || []).push(a); });
    var seqCount = Object.keys(byReg).filter(function (reg) { return byReg[reg].length > 1; }).length;
    document.getElementById('sum-seq').textContent = seqCount;

    var next = AGENDAMENTOS.slice().sort(function (a, b) { return a.de < b.de ? -1 : 1; })[0];
    document.getElementById('sum-next').textContent = next ? (next.reg + ' · ' + fmtWindow(next.de, next.ate)) : '—';

    var warnCount = 0;
    FLEET.forEach(function (ac) {
      var legs = legsByReg(ac.reg);
      legs.forEach(function (l, i) { if (!legChainInfo(ac, legs, i).ok) warnCount++; });
    });
    document.getElementById('sum-warn').textContent = warnCount;
  }

  /* ---------- formulário ---------- */
  function populateSelects() {
    var regSel = document.getElementById('f-reg');
    regSel.innerHTML = '<option value="">Selecione…</option>' + FLEET.map(function (ac) {
      return '<option value="' + ac.reg + '">' + ac.reg + ' · ' + ac.tipo + '</option>';
    }).join('');

    var icaoOptions = '<option value="">Selecione…</option>' + Object.keys(AIRPORTS).sort().map(function (icao) {
      return '<option value="' + icao + '">' + icao + ' · ' + AIRPORTS[icao].city + '</option>';
    }).join('');
    document.getElementById('f-origem').innerHTML = icaoOptions;
    document.getElementById('f-destino').innerHTML = icaoOptions;
  }

  function currentTipo() {
    var el = document.querySelector('#f-tipo-chips .chip.on');
    return el ? el.dataset.tipo : null;
  }

  function computeWindow() {
    var dataV = document.getElementById('f-data').value;
    var deV = document.getElementById('f-de').value;
    var ateV = document.getElementById('f-ate').value;
    if (!dataV || !deV || !ateV) return null;
    var deIso = toIso(dataV, deV, false);
    var rollover = ateV <= deV;
    var ateIso = toIso(dataV, ateV, rollover);
    return { de: deIso, ate: ateIso };
  }

  function suggestOrigem(reg) {
    var ac = fleetOf(reg);
    if (!ac) return;
    var legs = legsByReg(reg);
    var suggested = legs.length ? legs[legs.length - 1].destino : ac.pos;
    document.getElementById('f-origem').value = suggested;
    document.getElementById('f-origem-hint').textContent = legs.length
      ? L('agendamento.suggest.continues', 'Sugerido: continua de onde termina o último voo agendado dessa aeronave ({icao}).').replace('{icao}', suggested)
      : L('agendamento.suggest.currentpos', 'Sugerido: posição atual da aeronave ({icao}).').replace('{icao}', suggested);
  }

  function showAlert(kind, msg) {
    var el = document.getElementById('f-alert');
    el.className = 'ag-alert ' + kind;
    el.textContent = msg;
    el.hidden = false;
  }
  function hideAlert() { document.getElementById('f-alert').hidden = true; }

  function validateForm() {
    var reg = document.getElementById('f-reg').value;
    var origem = document.getElementById('f-origem').value;
    var destino = document.getElementById('f-destino').value;
    var piloto = document.getElementById('f-piloto').value.trim();
    var tipo = currentTipo();
    var win = computeWindow();
    var submit = document.getElementById('f-submit');

    if (!(reg && origem && destino && piloto && tipo && win)) {
      submit.disabled = true;
      hideAlert();
      return;
    }

    if (hasOverlap(reg, win.de, win.ate, EDITING_ID)) {
      showAlert('bad', L('agendamento.alert.overlap', 'Essa aeronave já tem outro voo agendado que se sobrepõe a essa janela de horário — ajuste o horário ou escolha outra aeronave.'));
      submit.disabled = true;
      return;
    }

    var expected = expectedOrigem(reg, win.de, EDITING_ID);
    if (expected && expected !== origem) {
      showAlert('warn', L('agendamento.alert.sequence', 'A sequência esperada pra essa aeronave é sair de {expected} nesse horário — confirme se {origem} está certo (pode ser um reposicionamento fora da sequência).').replace('{expected}', expected).replace('{origem}', origem));
    } else {
      hideAlert();
    }
    submit.disabled = false;
  }

  function resetForm() {
    ['f-reg', 'f-origem', 'f-destino', 'f-data', 'f-de', 'f-ate', 'f-notas'].forEach(function (id) { document.getElementById(id).value = ''; });
    document.getElementById('f-piloto').value = window.KATABATIC_PILOT_NAME || '';
    document.getElementById('f-origem-hint').textContent = '';
    document.querySelectorAll('#f-tipo-chips .chip').forEach(function (c, i) { c.classList.toggle('on', i === 0); });
    hideAlert();
    document.getElementById('f-submit').disabled = true;
  }

  // `editEntry` (opcional) preenche o formulário com um agendamento já
  // existente e faz o "Agendar voo" virar "Salvar alterações" - vem de
  // um clique em "Editar" numa linha (ver wireCardActions/editLeg).
  // `prefillReg` sozinho (sem editEntry) é o fluxo normal de criar um
  // novo, com a Origem já sugerida pra continuar a sequência da
  // aeronave escolhida.
  // Titulo do formulario e texto do botao de submit dependem do modo
  // (novo vs edicao, ver EDITING_ID) - por isso ficam fora do alcance de
  // data-i18n (que so sabe reaplicar um valor fixo por chave) e sao
  // recalculados aqui: chamado de openForm() ao entrar em cada modo, e de
  // novo pelo listener katabatic:langchange (fim do arquivo) pra manter
  // certo se o idioma for trocado com o formulario já aberto (ou fechado,
  // preparando o texto certo pra próxima vez que abrir).
  function refreshFormLabels() {
    var titleEl = document.getElementById('ag-form-title');
    var submitEl = document.getElementById('f-submit');
    if (titleEl) titleEl.textContent = EDITING_ID ? L('agendamento.form.title.edit', 'Editar agendamento') : L('agendamento.form.title.new', 'Novo agendamento');
    if (submitEl) submitEl.textContent = EDITING_ID ? L('agendamento.form.submit.edit', 'Salvar alterações') : L('agendamento.book', 'Agendar voo');
  }

  function openForm(prefillReg, editEntry) {
    resetForm();
    EDITING_ID = editEntry ? editEntry.id : null;
    refreshFormLabels();
    document.getElementById('ag-form-panel').hidden = false;

    var reg = editEntry ? editEntry.reg : prefillReg;
    if (reg) {
      document.getElementById('f-reg').value = reg;
      if (editEntry) {
        document.getElementById('f-origem').value = editEntry.origem;
        document.getElementById('f-destino').value = editEntry.destino;
        var d = new Date(editEntry.de), a = new Date(editEntry.ate);
        document.getElementById('f-data').value = d.toISOString().slice(0, 10);
        document.getElementById('f-de').value = pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes());
        document.getElementById('f-ate').value = pad2(a.getUTCHours()) + ':' + pad2(a.getUTCMinutes());
        document.getElementById('f-piloto').value = editEntry.piloto;
        document.getElementById('f-notas').value = editEntry.notas || '';
        document.querySelectorAll('#f-tipo-chips .chip').forEach(function (c) { c.classList.toggle('on', c.dataset.tipo === editEntry.tipo); });
      } else {
        suggestOrigem(reg);
      }
      validateForm();
    }
    document.getElementById('ag-form-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function closeForm() {
    document.getElementById('ag-form-panel').hidden = true;
    EDITING_ID = null;
  }

  function wireForm() {
    wireCardActions();
    document.getElementById('ag-open-form').addEventListener('click', function () { openForm(null, null); });
    document.getElementById('f-cancel').addEventListener('click', closeForm);

    document.getElementById('f-reg').addEventListener('change', function (e) {
      if (e.target.value) suggestOrigem(e.target.value);
      validateForm();
    });
    document.querySelectorAll('#f-tipo-chips .chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        document.querySelectorAll('#f-tipo-chips .chip').forEach(function (c) { c.classList.remove('on'); });
        chip.classList.add('on');
        validateForm();
      });
    });
    ['f-origem', 'f-destino', 'f-data', 'f-de', 'f-ate', 'f-piloto'].forEach(function (id) {
      document.getElementById(id).addEventListener('input', validateForm);
      document.getElementById(id).addEventListener('change', validateForm);
    });

    document.getElementById('f-submit').addEventListener('click', function () {
      var win = computeWindow();
      var reg = document.getElementById('f-reg').value;
      if (!win || !reg) return;
      var origem = document.getElementById('f-origem').value;
      var destino = document.getElementById('f-destino').value;
      var tipo = currentTipo();
      var piloto = document.getElementById('f-piloto').value.trim();
      var notas = document.getElementById('f-notas').value.trim() || null;

      if (EDITING_ID) {
        for (var i = 0; i < AGENDAMENTOS.length; i++) {
          if (AGENDAMENTOS[i].id === EDITING_ID) {
            AGENDAMENTOS[i] = { id: EDITING_ID, reg: reg, origem: origem, destino: destino, tipo: tipo, piloto: piloto, de: win.de, ate: win.ate, notas: notas };
            break;
          }
        }
        HIGHLIGHT_REG = reg;
        HIGHLIGHT_ID = EDITING_ID;
      } else {
        var entry = { id: nextId++, reg: reg, origem: origem, destino: destino, tipo: tipo, piloto: piloto, de: win.de, ate: win.ate, notas: notas };
        AGENDAMENTOS.push(entry);
        HIGHLIGHT_REG = reg;
        HIGHLIGHT_ID = entry.id;
      }
      closeForm();
      renderSummary();
      renderAgenda();
      renderCards();
    });

    var searchEl = document.getElementById('ag-search');
    if (searchEl) searchEl.addEventListener('input', renderAgenda);
  }

  /* ---------- carregamento ---------- */
  var airportsUrl = window.KATABATIC_AIRPORTS_URL || '/assets/data/airports.json';
  fetch(airportsUrl)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      AIRPORTS = data;
      populateSelects();
      renderSummary();
      renderAgenda();
      renderCards();
      wireForm();
    })
    .catch(function (err) {
      console.error('Katabatic: falha ao carregar coordenadas dos aeroportos.', err);
      renderSummary();
      renderAgenda();
      renderCards();
    });

  // Alem do #title (ja cuidado abaixo), praticamente todo o resto desta
  // tela - tiles do resumo, tabela da Agenda da semana, cards por
  // aeronave (badge de status, tabela de pernas, chain de waypoints) e os
  // rotulos dinamicos do formulario - e montado na mao por render*()
  // acima usando L(), que nao e re-executado sozinho quando o idioma
  // muda. Por isso o listener precisa re-chamar os mesmos pontos de
  // entrada de render usados no load/filtro, senao esse conteudo fica
  // preso no idioma de quando a pagina carregou.
  document.addEventListener('katabatic:langchange', function () {
    var el = document.getElementById('title');
    if (el) el.textContent = document.documentElement.lang === 'en' ? 'Scheduling' : 'Agendamentos';
    renderSummary();
    renderAgenda();
    renderCards();
    refreshFormLabels();
  });
})();
