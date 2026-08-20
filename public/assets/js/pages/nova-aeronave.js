/* ==========================================================================
   Katabatic — nova-aeronave.js
   Cadastro de aeronave: pais de registro define o prefixo da matricula,
   normaliza a matricula pro formato sem hifen (o que o ACARS manda no
   ATC ID) e habilita "Salvar" quando matricula + tipo estao ok. Porte
   quase literal do JS do mockup original (katabatic-nova-aeronave.html),
   so removendo o toggle de tema, que agora e compartilhado (ver
   theme-toggle.js, carregado antes deste em app_base.html.twig).

   "Salvar aeronave" ainda e mock (alert) - vira INSERT de verdade
   quando a Frota for tabela real.
   ========================================================================== */
(function () {
  'use strict';

  var PREFIX = { CL: 'CC-', US: 'N' };
  var pais = 'CL';

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

  document.getElementById('btn-save').addEventListener('click', function () {
    alert('Aeronave salva (mock) — apareceria agora na Frota.');
  });
})();
