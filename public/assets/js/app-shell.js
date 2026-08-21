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

  // Banner de flash message (app.flashes(), ver app_base.html.twig) - so
  // fecha o card ao clicar no x. Sem timeout automatico: a mensagem ja
  // some sozinha na proxima navegacao, porque o flash bag do Symfony e
  // consumido na leitura.
  document.querySelectorAll('.flash-close').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var flash = btn.closest('.flash');
      if (flash) flash.remove();
    });
  });
})();
