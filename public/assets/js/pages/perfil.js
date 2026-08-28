/* ==========================================================================
   Katabatic — perfil.js
   Formulario de configuracoes do piloto: so cuida do preview da foto
   antes do envio (FileReader -> troca a imagem/iniciais no avatar e o
   texto de status). Validacao de verdade (tipo/tamanho) e feita no
   servidor, em PerfilController - isto aqui e so feedback visual.
   ========================================================================== */
(function () {
  'use strict';

  var input = document.getElementById('f-photo');
  if (!input) return;

  var img = document.getElementById('avatar-img');
  var fallback = document.getElementById('avatar-fallback');
  var filenameEl = document.getElementById('avatar-filename');

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;

    filenameEl.textContent = file.name;

    var reader = new FileReader();
    reader.onload = function (e) {
      img.src = e.target.result;
      img.hidden = false;
      if (fallback) fallback.hidden = true;
    };
    reader.readAsDataURL(file);
  });
})();
