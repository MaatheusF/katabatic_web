/* Katabatic — alternancia de tema claro/escuro, compartilhada por todas as telas. */
(function () {
  var root = document.documentElement;
  var STORAGE_KEY = 'katabatic-theme';

  var saved = null;
  try { saved = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : null; } catch (e) { saved = null; }

  if (saved === 'light' || saved === 'dark') {
    root.setAttribute('data-theme', saved);
  } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    root.setAttribute('data-theme', 'dark');
  }

  var btn = document.getElementById('theme');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { if (window.localStorage) window.localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* ignora */ }
  });
})();
