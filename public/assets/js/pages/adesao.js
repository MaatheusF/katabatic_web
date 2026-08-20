/* ==========================================================================
   Katabatic — adesao.js
   Formulario de solicitacao de adesao (tela publica). "Enviar
   solicitacao" ainda e mock - troca o card do formulario por um card de
   confirmacao, sem POST de verdade (ver AdesaoController). Porte do
   mesmo padrao de chips/contador de caracteres usado em novo-voo.js.
   ========================================================================== */
(function () {
  'use strict';

  var state = { exp: 'Iniciante', base: 'PAFA' };

  document.querySelectorAll('#ad-exp-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#ad-exp-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      state.exp = c.dataset.exp;
    });
  });
  document.querySelectorAll('#ad-base-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#ad-base-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      state.base = c.dataset.base;
    });
  });

  var motivacaoEl = document.getElementById('ad-motivacao');
  motivacaoEl.addEventListener('input', function (e) {
    document.getElementById('ad-motivacao-count').textContent = e.target.value.length + ' caractere' + (e.target.value.length === 1 ? '' : 's');
  });

  var nomeEl = document.getElementById('ad-nome');
  var emailEl = document.getElementById('ad-email');
  var cidEl = document.getElementById('ad-cid');
  var discordEl = document.getElementById('ad-discord');
  var agreeEl = document.getElementById('ad-agree');
  var submitBtn = document.getElementById('ad-submit');

  function updateSubmitState() {
    var ok = nomeEl.value.trim() && emailEl.value.trim() && cidEl.value.trim() && motivacaoEl.value.trim() && agreeEl.checked;
    submitBtn.disabled = !ok;
  }
  [nomeEl, emailEl, cidEl, motivacaoEl].forEach(function (el) { el.addEventListener('input', updateSubmitState); });
  agreeEl.addEventListener('change', updateSubmitState);
  updateSubmitState();

  document.getElementById('adesao-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitBtn.disabled) return;

    // ID de solicitacao so pra dar um retorno concreto na tela - nao e
    // persistido em lugar nenhum (ver nota no card de confirmacao).
    var id = 'KADE-' + Math.floor(1000 + Math.random() * 9000);
    var baseLabel = state.base === 'Sem preferência' ? 'sem preferência de base' : 'base ' + state.base;
    var discordLabel = discordEl.value.trim() ? ' Discord ' + discordEl.value.trim() + ' anotado —' : '';

    document.getElementById('ad-success-summary').textContent =
      nomeEl.value.trim() + ', recebemos seu pedido (' + id + ') com CID ' + cidEl.value.trim() +
      ' e ' + baseLabel + '.' + discordLabel + ' Vamos revisar e responder por e-mail (' + emailEl.value.trim() + ') em breve.';

    document.getElementById('adesao-form-card').style.display = 'none';
    document.getElementById('adesao-success-card').style.display = '';
    window.scrollTo(0, 0);
  });
})();
