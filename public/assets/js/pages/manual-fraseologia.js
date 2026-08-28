/* ==========================================================================
   Katabatic — manual-fraseologia.js
   Manual de Fraseologia VATSIM: so um botao "Copiar" por chamada de
   radio (.m-call), copiando o texto-modelo (com os placeholders entre
   colchetes) pra area de transferencia. Pagina e conteudo estatico -
   nao ha fetch/estado, so esse unico comportamento.
   ========================================================================== */
(function () {
  'use strict';

  // Le o idioma atual na hora do clique (window.katabaticLang, de
  // lang-toggle.js) em vez de escutar `katabatic:langchange` - essas
  // strings de feedback so sao escritas em resposta a um clique, entao
  // sempre pegam o idioma corrente nesse instante; nao ha texto "parado"
  // em tela pra re-traduzir quando o idioma muda (o label de repouso do
  // botao, esse sim, e coberto pelo data-i18n no template).
  function L(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  document.querySelectorAll('.m-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var call = btn.closest('.m-call');
      var text = call ? call.dataset.call : '';
      if (!text) return;

      function feedback() {
        var prev = btn.textContent;
        btn.textContent = L('manual.copy.copied', 'Copiado!');
        btn.dataset.copied = '1';
        clearTimeout(btn._mTimer);
        btn._mTimer = setTimeout(function () {
          btn.textContent = prev;
          btn.dataset.copied = '0';
        }, 1600);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(feedback).catch(function () {
          btn.textContent = L('manual.copy.manual', 'Copie manualmente');
        });
      } else {
        // Fallback pra navegadores sem Clipboard API (ou fora de https) -
        // um textarea temporario + document.execCommand ainda funciona
        // na maioria dos casos.
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); feedback(); } catch (e) { btn.textContent = L('manual.copy.manual', 'Copie manualmente'); }
        document.body.removeChild(ta);
      }
    });
  });
})();
