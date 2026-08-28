/* ==========================================================================
   Katabatic — nova-aeronave.js
   Cadastro de aeronave: pais de registro define o prefixo da matricula,
   normaliza a matricula pro formato sem hifen (o que o ACARS manda no
   ATC ID) e habilita "Salvar" quando matricula + tipo estao ok. Porte
   quase literal do JS do mockup original (katabatic-nova-aeronave.html),
   so removendo o toggle de tema, que agora e compartilhado (ver
   theme-toggle.js, carregado antes deste em app_base.html.twig).

   "Salvar aeronave" agora faz um POST de verdade em /nova-aeronave (JSON)
   - ver NovaAeronaveController::submit(). Sucesso redireciona pro Portal
   na view Frota, ja com a aeronave nova la; erro (validacao ou falha de
   rede) mostra o motivo acima do formulario, mesmo padrao de adesao.js.
   ========================================================================== */
(function () {
  'use strict';

  var PREFIX = { CL: 'CC-', US: 'N' };
  var pais = 'CL';

  // Le o dicionario EN da pagina (window.KATABATIC_I18N_EN, definido no
  // page_javascripts de nova_aeronave/index.html.twig) - mesmo padrao do
  // helper equivalente em voo.js/novo-voo.js. Unico uso aqui e o alert
  // de "salvar" (mock); o resto do texto da tela e estatico e ja
  // coberto por data-i18n no template.
  function L(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  document.querySelectorAll('#pais-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#pais-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      pais = c.dataset.pais;
      document.getElementById('reg-prefix').textContent = PREFIX[pais];
      document.getElementById('f-reg').value = '';
      syncReg();
    });
  });

  document.querySelectorAll('#base-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#base-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
    });
  });

  function syncReg() {
    var raw = document.getElementById('f-reg').value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    var full = PREFIX[pais] + raw;
    document.getElementById('reg-norm').textContent = full.replace('-', '');
    document.getElementById('sim-tail').textContent = full || (PREFIX[pais] + '···');
    updateSaveState();
  }
  document.getElementById('f-reg').addEventListener('input', syncReg);
  syncReg();

  document.getElementById('f-tipo').addEventListener('change', function (e) {
    var custom = document.getElementById('f-tipo-custom');
    custom.style.display = e.target.value === '__custom' ? '' : 'none';
    updateSaveState();
  });
  document.getElementById('f-tipo-custom').addEventListener('input', updateSaveState);

  function updateSaveState() {
    var reg = document.getElementById('f-reg').value.trim();
    var tipoSel = document.getElementById('f-tipo').value;
    var tipoOk = tipoSel && (tipoSel !== '__custom' || document.getElementById('f-tipo-custom').value.trim());
    document.getElementById('btn-save').disabled = !(reg && tipoOk);
  }

  var errorEl = document.getElementById('na-error');
  var saveBtn = document.getElementById('btn-save');
  var saveLabel = saveBtn.textContent;

  function showError(message) {
    errorEl.textContent = message;
    errorEl.style.display = '';
    window.scrollTo(0, 0);
  }

  function hideError() {
    errorEl.style.display = 'none';
  }

  saveBtn.addEventListener('click', function () {
    if (saveBtn.disabled) return;

    hideError();
    saveBtn.disabled = true;
    saveBtn.textContent = L('novaaeronave.save.saving', 'Salvando…');

    var tipoSel = document.getElementById('f-tipo').value;
    var tipo = tipoSel === '__custom' ? document.getElementById('f-tipo-custom').value.trim() : tipoSel;
    var baseChip = document.querySelector('#base-chips .chip.on');

    var payload = {
      pais: pais,
      reg: document.getElementById('f-reg').value.trim(),
      tipo: tipo,
      base: baseChip ? baseChip.dataset.base : '',
      limiteG: document.getElementById('f-glimit').value.trim(),
      limiteGNegativo: document.getElementById('f-glimit-neg').value.trim(),
      vsLimiteFpm: document.getElementById('f-vslimit').value.trim(),
      horas: document.getElementById('f-hours').value.trim(),
      observacoes: document.getElementById('f-obs').value.trim()
    };

    function restoreButton() {
      saveBtn.disabled = false;
      saveBtn.textContent = saveLabel;
    }

    fetch('/nova-aeronave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (result) {
        if (!result.ok) {
          var msg = (result.body.errors && result.body.errors[0]) || result.body.error || L('novaaeronave.error.generic', 'Não foi possível salvar a aeronave — tente novamente.');
          showError(msg);
          restoreButton();
          return;
        }

        window.location.href = '/portal?view=frota';
      })
      .catch(function () {
        showError(L('novaaeronave.error.generic', 'Não foi possível salvar a aeronave — verifique sua conexão e tente novamente.'));
        restoreButton();
      });
  });

  // reg-hint e o sim-box guardam <code id="reg-norm">/<code id="sim-tail">
  // que syncReg() atualiza ao vivo - a troca de idioma reescreve o
  // innerHTML inteiro desses paragrafos (data-i18n no template), o que
  // por um instante volta esses codes pro valor "de mockup" fixo do
  // dicionario. Re-chamar syncReg() logo em seguida devolve o valor
  // atual (pais + matricula digitada) no lugar certo.
  document.addEventListener('katabatic:langchange', function () {
    syncReg();
  });
})();
