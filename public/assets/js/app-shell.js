/* Katabatic — comportamento compartilhado das telas da area logada:
   o padrao generico de "grupo de chips" onde clicar um marca .on e
   desmarca os irmaos (usado em varios filtros: tipo de voo, base, etc.).
   O tema (botao #theme) e o proprio theme-toggle.js do site publico -
   carregado antes deste arquivo em app_base.html.twig, com a mesma
   persistencia em localStorage. */
(function () {
  document.querySelectorAll('.filters').forEach(function (group) {
    group.querySelectorAll('.chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        group.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('on'); });
        chip.classList.add('on');
      });
    });
  });
})();
