/* ==========================================================================
   Katabatic — adesao.js
   Formulario de solicitacao de adesao (tela publica). "Enviar
   solicitacao" agora faz um POST de verdade em /adesao (JSON) - ver
   AdesaoController::submit(). Sucesso troca o card do formulario por um
   card de confirmacao com o codigo real devolvido pelo servidor; erro
   (validacao ou falha de rede) mostra o motivo acima do formulario sem
   perder o que a pessoa ja preencheu. Porte do mesmo padrao de
   chips/contador de caracteres usado em novo-voo.js.
   ========================================================================== */
(function () {
  'use strict';

  function L(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

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

  function updateCharCount() {
    var n = motivacaoEl.value.length;
    document.getElementById('ad-motivacao-count').textContent =
      n + ' ' + L('adesao.count.word', 'caractere') + (n === 1 ? '' : 's');
  }
  motivacaoEl.addEventListener('input', updateCharCount);
  updateCharCount();

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

  // Guarda o ultimo ID gerado pra poder remontar a mesma frase de resumo
  // (com o mesmo ID) se o usuario trocar de idioma depois de enviar -
  // ver listener de katabatic:langchange abaixo.
  var lastRequestId = null;

  function baseLabelText() {
    return state.base === 'Sem preferência'
      ? L('adesao.summary.noBasePref', 'sem preferência de base')
      : L('adesao.summary.basePrefix', 'base') + ' ' + state.base;
  }

  function discordLabelText() {
    var v = discordEl.value.trim();
    if (!v) return '';
    return ' Discord ' + v + ' ' + L('adesao.summary.discordNoted', 'anotado') + ' —';
  }

  function buildSuccessSummary(id) {
    return nomeEl.value.trim() +
      L('adesao.summary.receivedPrefix', ', recebemos seu pedido (') + id +
      L('adesao.summary.withCid', ') com CID ') + cidEl.value.trim() +
      L('adesao.summary.and', ' e ') + baseLabelText() + '.' + discordLabelText() +
      L('adesao.summary.reviewPrefix', ' Vamos revisar e responder por e-mail (') + emailEl.value.trim() +
      L('adesao.summary.soonSuffix', ') em breve.');
  }

  var errorEl = document.getElementById('ad-error');
  var submitLabel = submitBtn.textContent;

  function showError(message) {
    errorEl.textContent = message;
    errorEl.style.display = '';
    window.scrollTo(0, 0);
  }

  function hideError() {
    errorEl.style.display = 'none';
  }

  document.getElementById('adesao-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitBtn.disabled) return;

    hideError();
    submitBtn.disabled = true;
    submitBtn.textContent = L('adesao.submit.sending', 'Enviando…');

    var payload = {
      nome: nomeEl.value.trim(),
      email: emailEl.value.trim(),
      cid: cidEl.value.trim(),
      discord: discordEl.value.trim(),
      experiencia: state.exp,
      basePref: state.base,
      comoConheceu: document.getElementById('ad-conheceu').value,
      motivacao: motivacaoEl.value.trim(),
      _csrf_token: window.KATABATIC_CSRF_TOKEN
    };

    fetch('/adesao', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (result) {
        if (!result.ok) {
          var msg = (result.body.errors && result.body.errors[0]) || L('adesao.error.generic', 'Não foi possível enviar sua solicitação — tente novamente.');
          showError(msg);
          submitBtn.disabled = false;
          submitBtn.textContent = submitLabel;
          return;
        }

        lastRequestId = result.body.code;
        document.getElementById('ad-success-summary').textContent = buildSuccessSummary(lastRequestId);
        document.getElementById('adesao-form-card').style.display = 'none';
        document.getElementById('adesao-success-card').style.display = '';
        window.scrollTo(0, 0);
      })
      .catch(function () {
        showError(L('adesao.error.generic', 'Não foi possível enviar sua solicitação — verifique sua conexão e tente novamente.'));
        submitBtn.disabled = false;
        submitBtn.textContent = submitLabel;
      });
  });

  // Idioma pode ser trocado com o formulario ainda em preenchimento (contador
  // de caracteres) ou depois do envio (frase de resumo) - reconstroi os dois
  // com o estado atual em vez de deixar o texto congelado no idioma antigo.
  document.addEventListener('katabatic:langchange', function () {
    updateCharCount();
    if (lastRequestId) {
      document.getElementById('ad-success-summary').textContent = buildSuccessSummary(lastRequestId);
    }
  });
})();
