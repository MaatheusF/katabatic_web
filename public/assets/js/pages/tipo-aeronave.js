/* ==========================================================================
   Katabatic — tipo-aeronave.js
   Cadastro do perfil de performance por tipo de aeronave (admin-only, ver
   TipoAeronaveController). Mesmo padrao de select+"outro tipo" que
   nova-aeronave.js usa pro campo Tipo (window.KATABATIC_TIPOS_AERONAVE,
   injetado pelo controller, e so a LISTA JA CADASTRADA aqui - o dropdown
   de nomes conhecidos vem do <select id="f-nome-select"> renderizado
   pelo Twig a partir de tiposConhecidos/AeronaveRepository::findDistinctTipos()).

   Um formulario so serve pra criar E editar (troca de "modo" via
   editingId): clicar "Editar" numa linha preenche o formulario com os
   valores daquela linha e muda o botao pra "Salvar alteracoes"; salvar
   nesse modo manda POST /tipos-aeronave/{id}/atualizar em vez de POST
   /tipos-aeronave. "Cancelar edicao" volta pro modo de criacao sem
   salvar nada.
   ========================================================================== */
(function () {
  'use strict';

  var TIPOS = (window.KATABATIC_TIPOS_AERONAVE || []).slice();
  var editingId = null;

  function tr(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  function fmtNum(v, casas) {
    if (v === null || v === undefined) return '<span class="sub">—</span>';
    return typeof casas === 'number' ? v.toFixed(casas) : String(v);
  }

  /* ---------- lista ---------- */
  function tipoRow(t) {
    return '<tr>' +
      '<td>' + t.nome + '</td>' +
      '<td class="num mono">' + fmtNum(t.pesoVazioLb) + '</td>' +
      '<td class="num mono">' + fmtNum(t.pesoMaxDecolagemLb) + '</td>' +
      '<td class="num mono">' + fmtNum(t.combustivelMaxGal) + '</td>' +
      '<td class="num mono">' + fmtNum(t.consumoGph, t.consumoGph === null ? undefined : 1) + '</td>' +
      '<td class="num mono">' + fmtNum(t.decolagemDistanciaFt) + '</td>' +
      '<td class="num mono">' + fmtNum(t.pousoDistanciaFt) + '</td>' +
      '<td><div class="ta-row-actions">' +
        '<button class="btn btn-sm" data-action="edit" data-id="' + t.id + '" type="button">' + tr('tipoaeronave.edit', 'Editar') + '</button>' +
        '<button class="btn btn-sm" data-action="remove" data-id="' + t.id + '" type="button">' + tr('tipoaeronave.remove', 'Remover') + '</button>' +
      '</div></td>' +
      '</tr>';
  }

  function renderList() {
    var body = document.getElementById('ta-body');
    body.innerHTML = TIPOS.length
      ? TIPOS.map(tipoRow).join('')
      : '<tr><td colspan="8" class="empty">' + tr('tipoaeronave.list.empty', 'Nenhum tipo cadastrado ainda.') + '</td></tr>';
    document.getElementById('ta-count').textContent = TIPOS.length + ' ' + tr('tipoaeronave.count.suffix', 'tipos');
  }
  renderList();

  /* ---------- form: campo Tipo (select + "outro tipo") ---------- */
  var nomeSelect = document.getElementById('f-nome-select');
  var nomeCustom = document.getElementById('f-nome-custom');

  nomeSelect.addEventListener('change', function (e) {
    nomeCustom.style.display = e.target.value === '__custom' ? '' : 'none';
    updateSaveState();
  });
  nomeCustom.addEventListener('input', updateSaveState);

  function currentNome() {
    var sel = nomeSelect.value;
    return sel === '__custom' ? nomeCustom.value.trim() : sel;
  }

  function setNomeField(nome) {
    var opt = null;
    for (var i = 0; i < nomeSelect.options.length; i++) {
      if (nomeSelect.options[i].value === nome) { opt = nomeSelect.options[i]; break; }
    }
    if (opt) {
      nomeSelect.value = nome;
      nomeCustom.style.display = 'none';
      nomeCustom.value = '';
    } else {
      nomeSelect.value = '__custom';
      nomeCustom.style.display = '';
      nomeCustom.value = nome;
    }
  }

  var numericIds = ['f-pesovazio', 'f-mtow', 'f-combustivel', 'f-consumo', 'f-decolagem', 'f-pouso'];
  numericIds.forEach(function (id) {
    document.getElementById(id).addEventListener('input', updateSaveState);
  });

  var saveBtn = document.getElementById('btn-save');
  var cancelBtn = document.getElementById('btn-cancel-edit');
  var formTitle = document.getElementById('ta-form-title');
  var errorEl = document.getElementById('ta-error');

  function updateSaveState() {
    saveBtn.disabled = !currentNome();
  }
  updateSaveState();

  function showError(message) {
    errorEl.textContent = message;
    errorEl.style.display = '';
    window.scrollTo(0, 0);
  }
  function hideError() {
    errorEl.style.display = 'none';
  }

  function resetForm() {
    editingId = null;
    nomeSelect.value = '';
    nomeCustom.style.display = 'none';
    nomeCustom.value = '';
    numericIds.forEach(function (id) { document.getElementById(id).value = ''; });
    document.getElementById('f-obs').value = '';
    formTitle.textContent = tr('tipoaeronave.new.title', 'Novo tipo');
    saveBtn.textContent = tr('tipoaeronave.save', 'Salvar tipo');
    cancelBtn.style.display = 'none';
    updateSaveState();
  }

  function startEdit(t) {
    editingId = t.id;
    setNomeField(t.nome);
    document.getElementById('f-pesovazio').value = t.pesoVazioLb === null ? '' : t.pesoVazioLb;
    document.getElementById('f-mtow').value = t.pesoMaxDecolagemLb === null ? '' : t.pesoMaxDecolagemLb;
    document.getElementById('f-combustivel').value = t.combustivelMaxGal === null ? '' : t.combustivelMaxGal;
    document.getElementById('f-consumo').value = t.consumoGph === null ? '' : t.consumoGph;
    document.getElementById('f-decolagem').value = t.decolagemDistanciaFt === null ? '' : t.decolagemDistanciaFt;
    document.getElementById('f-pouso').value = t.pousoDistanciaFt === null ? '' : t.pousoDistanciaFt;
    document.getElementById('f-obs').value = t.observacoes || '';
    formTitle.textContent = t.nome;
    saveBtn.textContent = tr('tipoaeronave.save.editing', 'Salvar alterações');
    cancelBtn.style.display = '';
    updateSaveState();
    hideError();
    window.scrollTo(0, 0);
  }

  cancelBtn.addEventListener('click', function (e) {
    e.preventDefault();
    resetForm();
    hideError();
  });

  saveBtn.addEventListener('click', function () {
    if (saveBtn.disabled) return;

    hideError();
    saveBtn.disabled = true;
    var savingLabel = tr('tipoaeronave.save.saving', 'Salvando…');
    var previousLabel = saveBtn.textContent;
    saveBtn.textContent = savingLabel;

    var payload = {
      nome: currentNome(),
      pesoVazioLb: document.getElementById('f-pesovazio').value.trim(),
      pesoMaxDecolagemLb: document.getElementById('f-mtow').value.trim(),
      combustivelMaxGal: document.getElementById('f-combustivel').value.trim(),
      consumoGph: document.getElementById('f-consumo').value.trim(),
      decolagemDistanciaFt: document.getElementById('f-decolagem').value.trim(),
      pousoDistanciaFt: document.getElementById('f-pouso').value.trim(),
      observacoes: document.getElementById('f-obs').value.trim()
    };

    var url = editingId ? '/tipos-aeronave/' + editingId + '/atualizar' : '/tipos-aeronave';

    function restoreButton() {
      saveBtn.disabled = false;
      saveBtn.textContent = previousLabel;
    }

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (result) {
        if (!result.ok) {
          var msg = (result.body.errors && result.body.errors[0]) || result.body.error || tr('tipoaeronave.error.generic', 'Não foi possível salvar o tipo — tente novamente.');
          showError(msg);
          restoreButton();
          return;
        }

        TIPOS = TIPOS.filter(function (t) { return t.id !== result.body.id; });
        TIPOS.push(result.body);
        TIPOS.sort(function (a, b) { return a.nome < b.nome ? -1 : a.nome > b.nome ? 1 : 0; });
        renderList();
        resetForm();
      })
      .catch(function () {
        showError(tr('tipoaeronave.error.generic', 'Não foi possível salvar o tipo — verifique sua conexão e tente novamente.'));
        restoreButton();
      });
  });

  /* ---------- editar/remover na lista ---------- */
  document.getElementById('ta-body').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-action]');
    if (!btn) return;
    var id = parseInt(btn.dataset.id, 10);
    var tipo = TIPOS.filter(function (t) { return t.id === id; })[0];
    if (!tipo) return;

    if (btn.dataset.action === 'edit') {
      startEdit(tipo);
      return;
    }

    if (btn.dataset.action === 'remove') {
      var msg = tr('tipoaeronave.remove.confirm', 'Remover o perfil de performance de "%s"? As calculadoras em Ferramentas do piloto vão parar de funcionar pra esse tipo até ele ser cadastrado de novo.').replace('%s', tipo.nome);
      if (!window.confirm(msg)) return;

      btn.disabled = true;
      fetch('/tipos-aeronave/' + id + '/remover', { method: 'POST' })
        .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
        .then(function (result) {
          if (!result.ok) {
            btn.disabled = false;
            showError((result.body && result.body.error) || tr('tipoaeronave.remove.error', 'Não foi possível remover — tente novamente.'));
            return;
          }
          TIPOS = TIPOS.filter(function (t) { return t.id !== id; });
          renderList();
          if (editingId === id) resetForm();
        })
        .catch(function () {
          btn.disabled = false;
          showError(tr('tipoaeronave.remove.error', 'Não foi possível remover — verifique sua conexão e tente novamente.'));
        });
    }
  });

  document.addEventListener('katabatic:langchange', function () {
    renderList();
  });
})();
