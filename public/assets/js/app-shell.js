/* Katabatic — comportamento compartilhado das telas da area logada:
   tema (reaproveita o botao #theme, igual ao site publico) e o padrao
   generico de "grupo de chips" onde clicar um marca .on e desmarca os
   irmaos (usado em varios filtros: tipo de voo, base, etc.). */
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
