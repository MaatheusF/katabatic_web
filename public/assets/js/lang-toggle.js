/* Katabatic — alternancia de idioma PT/EN, compartilhada por todas as telas.
   Espelha o theme-toggle.js: persiste em localStorage (mesma chave em toda
   a app) e funciona em qualquer pagina que tenha um `.lang` com botoes
   data-lang="pt"/"en".

   Traducao de conteudo e opcional por pagina: se a pagina definir
   `window.KATABATIC_I18N_EN` (objeto chave -> HTML em ingles) ANTES deste
   script rodar, os elementos com `data-i18n="chave"` sao traduzidos; sem
   isso, o botao ainda funciona (troca <html lang>, persiste a escolha),
   so nao ha texto pra traduzir naquela tela ainda.

   Este script e carregado por ULTIMO em base.html.twig (depois do bloco
   `javascripts`), justamente pra dar tempo das paginas definirem seu
   dicionario antes dele rodar.

   Paginas com JS proprio que reescrevem texto dinamicamente (ex.: o
   #title do Portal, trocado ao navegar entre Logbook/Frota/Bases) devem
   escutar o evento `katabatic:langchange` (detail.lang) pra re-aplicar a
   traducao deles quando o idioma mudar depois do carregamento inicial. */
(function () {
  var root = document.documentElement;
  var STORAGE_KEY = 'katabatic-lang';

  var nodes = document.querySelectorAll('[data-i18n]');
  var dictPt = {};
  nodes.forEach(function (n) { dictPt[n.dataset.i18n] = n.innerHTML; });
  var I18N = { pt: dictPt, en: window.KATABATIC_I18N_EN || {} };

  function applyLang(lang) {
    var dict = I18N[lang] || I18N.pt;
    nodes.forEach(function (n) {
      var v = dict[n.dataset.i18n];
      if (v) n.innerHTML = v;
    });
    root.lang = lang === 'en' ? 'en' : 'pt-BR';
    document.querySelectorAll('.lang button[data-lang]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.lang === lang));
    });
    document.dispatchEvent(new CustomEvent('katabatic:langchange', { detail: { lang: lang } }));
  }

  function setLang(lang) {
    applyLang(lang);
    try { if (window.localStorage) window.localStorage.setItem(STORAGE_KEY, lang); } catch (e) { /* ignora */ }
  }
  window.katabaticSetLang = setLang;

  document.querySelectorAll('.lang button[data-lang]').forEach(function (b) {
    b.addEventListener('click', function () { setLang(b.dataset.lang); });
  });

  var saved = null;
  try { saved = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : null; } catch (e) { saved = null; }
  if (saved === 'pt' || saved === 'en') {
    applyLang(saved);
  } else if ((navigator.language || '').slice(0, 2) !== 'pt') {
    applyLang('en');
  }
})();
