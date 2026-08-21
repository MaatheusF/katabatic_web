/* Katabatic — alternancia de idioma PT/EN, compartilhada por todas as telas.
   Espelha o theme-toggle.js: persiste em localStorage (mesma chave em toda
   a app) e funciona em qualquer pagina que tenha um `.lang` com botoes
   data-lang="pt"/"en".

   Traducao de conteudo e opcional por pagina: se a pagina definir
   `window.KATABATIC_I18N_EN` (objeto chave -> HTML em ingles) ANTES deste
   script rodar, os elementos com `data-i18n="chave"` sao traduzidos; sem
   isso, o botao ainda funciona (troca <html lang>, persiste a escolha),
   so nao ha texto pra traduzir naquela tela ainda.

   Alem de `data-i18n` (traduz o innerHTML), tambem existe `data-i18n-attr`
   pra traduzir ATRIBUTOS (aria-label, title, placeholder, value etc.) -
   o innerHTML puro nao cobre esses casos. Formato: um ou mais pares
   "atributo:chave" separados por ";", ex.:
     <button aria-label="Idioma" data-i18n-attr="aria-label:a11y.language">
     <input placeholder="Nome" data-i18n-attr="placeholder:novovoo.notes.ph">
   Cada chave usa o mesmo dicionario EN da pagina (window.KATABATIC_I18N_EN),
   igual ao data-i18n. Duas chaves universais (a11y.language, a11y.theme)
   ja vem resolvidas por este script mesmo sem a pagina declarar nada -
   sao o par idioma/tema que se repete identico em toda tela.

   Este script e carregado por ULTIMO em base.html.twig (depois do bloco
   `javascripts`), justamente pra dar tempo das paginas definirem seu
   dicionario antes dele rodar.

   Paginas com JS proprio que reescrevem texto dinamicamente (ex.: o
   #title do Portal, trocado ao navegar entre Logbook/Frota/Bases, ou
   tabelas/paineis reconstruidos por filtro) devem escutar o evento
   `katabatic:langchange` (detail.lang) pra re-aplicar a traducao deles
   quando o idioma mudar depois do carregamento inicial - normalmente
   bastando re-chamar a mesma funcao de render usada no load/filtro. */
(function () {
  var root = document.documentElement;
  var STORAGE_KEY = 'katabatic-lang';

  // Duas chaves universais resolvidas aqui mesmo, sem a pagina precisar
  // declarar - o par idioma/tema se repete identico em toda tela logada
  // e publica.
  var CORE_EN = { 'a11y.language': 'Language', 'a11y.theme': 'Toggle theme' };
  var pageEn = window.KATABATIC_I18N_EN || {};
  var mergedEn = {};
  for (var k in CORE_EN) { mergedEn[k] = CORE_EN[k]; }
  for (var k2 in pageEn) { mergedEn[k2] = pageEn[k2]; }

  var nodes = document.querySelectorAll('[data-i18n]');
  var dictPt = {};
  nodes.forEach(function (n) { dictPt[n.dataset.i18n] = n.innerHTML; });

  // data-i18n-attr="attr1:chave1;attr2:chave2" - guarda o valor PT original
  // de cada (no, atributo) pra poder voltar quando o idioma for PT de novo.
  var attrEntries = [];
  document.querySelectorAll('[data-i18n-attr]').forEach(function (n) {
    n.dataset.i18nAttr.split(';').forEach(function (pair) {
      pair = pair.trim();
      if (!pair) return;
      var parts = pair.split(':');
      var attr = parts[0];
      var key = parts[1];
      if (!attr || !key) return;
      attrEntries.push({ node: n, attr: attr, key: key, pt: n.getAttribute(attr) });
    });
  });

  var I18N = { pt: dictPt, en: mergedEn };

  function applyLang(lang) {
    var dict = I18N[lang] || I18N.pt;
    nodes.forEach(function (n) {
      var v = dict[n.dataset.i18n];
      if (v) n.innerHTML = v;
    });
    attrEntries.forEach(function (e) {
      if (lang === 'en' && mergedEn[e.key]) {
        e.node.setAttribute(e.attr, mergedEn[e.key]);
      } else {
        e.node.setAttribute(e.attr, e.pt);
      }
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

  // Helper pra paginas com JS proprio que renderiza texto dinamico: em vez
  // de cada arquivo reimplementar sua propria leitura de idioma atual (o
  // padrao que ja existia, ad-hoc, em portal.js), usam
  // `window.katabaticLang()` -> 'pt' | 'en'. Continua funcionando mesmo
  // antes deste script rodar (fallback pt), porque so é chamado dentro de
  // handlers/eventos que disparam depois do load.
  window.katabaticLang = function () { return root.lang === 'en' ? 'en' : 'pt'; };

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
