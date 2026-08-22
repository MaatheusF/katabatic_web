/* ==========================================================================
   Katabatic — aeroporto.js
   Cadastro de aeroportos (admin-only, ver AeroportoController). Lista os
   aeroportos ja cadastrados (window.KATABATIC_AEROPORTOS, injetado pelo
   controller) numa tabela e manda "Novo aeroporto" via POST /aeroportos
   em JSON - mesmo padrao de nova-aeronave.js (validacao no cliente so
   habilita/desabilita o botao; a validacao de verdade e sempre a do
   servidor, os erros dela e que aparecem no banner).

   Atualizado: importacao global (OurAirports, ver README) mudou o que
   KATABATIC_AEROPORTOS traz - so bases + postos avancados (pequeno,
   estavel), nao mais o catalogo inteiro (que hoje tem milhares de
   linhas, inviavel numa tabela sem paginacao). A busca abaixo
   (KATABATIC_AEROPORTOS_BUSCA_URL, mesmo endpoint do combobox de
   origem/destino em agendamento.js) e como um admin alcanca qualquer
   outro ICAO do catalogo grande pra marca-lo como posto avancado via
   POST /aeroportos/{icao}/posto-avancado - rota nova, so faz sentido
   pra aeroportos que ja existem (o cadastro de um aeroporto NOVO
   continua sendo o formulario "Novo aeroporto" acima, que ja deixa
   marcar o posto avancado no mesmo passo).

   Atualizado de novo: pistas sem ICAO das regioes de missao (Chile,
   Argentina, Antartida+ilhas proximas, Canada, Russia, Alasca,
   Groenlandia/Svalbard - ver README) entram no catalogo com
   icaoOficial=false (App\Entity\Aeroporto::$icaoOficial). codigoLocalTag()
   abaixo desenha um selo "Local" ao lado do codigo nesses casos, tanto na
   lista quanto na busca, pra nao confundir com um ICAO de verdade.

   Atualizado mais uma vez: a lista principal ("Bases e postos avancados")
   ganhou seu proprio botao "Remover" por linha - antes so dava pra
   desmarcar um posto avancado indo ate o card de busca e achando o ICAO
   de novo, mesmo pra um aeroporto que ja estava bem na frente na lista.
   enviarAtualizacaoPosto()/sincronizarAposAtualizacao() abaixo sao
   compartilhados entre a lista e a busca - os dois chamam o mesmo
   POST /aeroportos/{icao}/posto-avancado, so o gatilho e diferente.
   ========================================================================== */
(function () {
  'use strict';

  var BASES = ['PAFA', 'SCCI']; // mesma lista que AeroportoRepository::BASES/AeroportoController::BASES_VALIDAS - uma base nunca some da lista principal, mesmo sem postoAvancadoDe (ver sincronizarAposAtualizacao())
  var AEROPORTOS = (window.KATABATIC_AEROPORTOS || []).slice();
  var TOTAL_CATALOGO = window.KATABATIC_AEROPORTOS_TOTAL || AEROPORTOS.length;
  var BUSCA_URL = window.KATABATIC_AEROPORTOS_BUSCA_URL || '/aeroportos/buscar';
  var posto = '';

  function tr(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  /* ---------- lista ---------- */
  // Selo "Local" quando o codigo nao e um ICAO oficial (pistas de bush
  // flying sem ICAO nas regioes de missao, ver cabecalho do arquivo) -
  // title explica pra quem passar o mouse, sem poluir a coluna.
  function codigoLocalTag(a) {
    if (a.icaoOficial === false) {
      return ' <span class="tag tag-local" title="' + tr('aeroporto.badge.local.title', 'Código local/FAA — não é um ICAO oficial') + '">' + tr('aeroporto.badge.local', 'Local') + '</span>';
    }
    return '';
  }

  // So aparece pra quem de fato e posto avancado de alguma base - uma
  // base (PAFA/SCCI) nunca tem postoAvancadoDe preenchido (nao e posto
  // avancado de si mesma), entao nunca ganha esse botao - nao tem "posto
  // avancado" pra remover dela.
  function removeButtonHtml(a) {
    if (!a.postoAvancadoDe) return '';
    return '<button class="btn btn-sm" data-icao="' + a.icao + '" data-posto="" type="button">' + tr('aeroporto.mark.clear', 'Remover') + '</button>';
  }

  // Botao "editar heading" compartilhado pela lista principal e pela
  // busca - so um prompt() simples (0-359 ou vazio pra limpar) em vez de
  // um segundo formulario inteiro, ver headingEditButtonHtml/wiring no
  // fim do arquivo. `data-heading-icao` (nao `data-icao`, que o resto do
  // arquivo ja usa pra marcar/remover posto avancado) evita colidir com
  // o listener delegado de posto avancado nas mesmas tabelas.
  function headingCellHtml(a) {
    return null == a.pistaPrincipalHeadingMag
      ? '<span class="sub">—</span>'
      : a.pistaPrincipalHeadingMag + '°';
  }
  function headingEditButtonHtml(a) {
    return '<button class="btn btn-sm" data-heading-icao="' + a.icao + '" type="button">' + tr('aeroporto.heading.edit', 'Definir heading') + '</button>';
  }

  function airportRow(a) {
    var postoTag = a.postoAvancadoDe
      ? '<span class="tag tag-ok">' + a.postoAvancadoDe + '</span>'
      : '<span class="sub">—</span>';
    return '<tr>' +
      '<td class="mono">' + a.icao + codigoLocalTag(a) + '</td>' +
      '<td>' + a.nome + '</td>' +
      '<td>' + a.cidade + '</td>' +
      '<td class="num mono">' + a.lat.toFixed(4) + '</td>' +
      '<td class="num mono">' + a.lon.toFixed(4) + '</td>' +
      '<td>' + postoTag + '</td>' +
      '<td class="num mono">' + headingCellHtml(a) + '</td>' +
      '<td><div class="ap-row-actions">' + removeButtonHtml(a) + headingEditButtonHtml(a) + '</div></td>' +
      '</tr>';
  }

  function renderList() {
    var body = document.getElementById('ap-body');
    body.innerHTML = AEROPORTOS.length
      ? AEROPORTOS.map(airportRow).join('')
      : '<tr><td colspan="8" class="empty">' + tr('aeroporto.list.empty', 'Nenhum aeroporto cadastrado ainda.') + '</td></tr>';
    document.getElementById('ap-count').textContent = AEROPORTOS.length + ' ' + tr('aeroporto.count.suffix', 'aeroportos');
  }
  renderList();

  var totalEl = document.getElementById('ap-total-count');
  if (totalEl) totalEl.textContent = TOTAL_CATALOGO + ' ' + tr('aeroporto.total.suffix', 'no catálogo completo');

  /* ---------- busca no catalogo global ---------- */
  var searchEl = document.getElementById('ap-search');
  var searchWrap = document.getElementById('ap-search-results-wrap');
  var searchBody = document.getElementById('ap-search-body');
  var searchEmptyEl = document.getElementById('ap-search-empty');
  var searchTimer = null;
  var searchSeq = 0; // descarta respostas que chegam fora de ordem (busca rapida, resultado lento de uma tecla anterior)

  function markButtonsHtml(a) {
    var btns = '';
    if (a.postoAvancadoDe !== 'PAFA') btns += '<button class="btn btn-sm" data-icao="' + a.icao + '" data-posto="PAFA" type="button">' + tr('aeroporto.mark.pafa', 'Marcar posto de PAFA') + '</button>';
    if (a.postoAvancadoDe !== 'SCCI') btns += '<button class="btn btn-sm" data-icao="' + a.icao + '" data-posto="SCCI" type="button">' + tr('aeroporto.mark.scci', 'Marcar posto de SCCI') + '</button>';
    if (a.postoAvancadoDe) btns += '<button class="btn btn-sm" data-icao="' + a.icao + '" data-posto="" type="button">' + tr('aeroporto.mark.clear', 'Remover') + '</button>';
    return btns;
  }

  function searchRow(a) {
    var postoTag = a.postoAvancadoDe
      ? '<span class="tag tag-ok">' + a.postoAvancadoDe + '</span>'
      : '<span class="sub">—</span>';
    return '<tr data-row-icao="' + a.icao + '">' +
      '<td class="mono">' + a.icao + codigoLocalTag(a) + '</td>' +
      '<td>' + a.nome + '</td>' +
      '<td>' + a.cidade + '</td>' +
      '<td class="posto-cell">' + postoTag + '</td>' +
      '<td class="num mono">' + headingCellHtml(a) + '</td>' +
      '<td><div class="ap-row-actions">' + markButtonsHtml(a) + headingEditButtonHtml(a) + '</div></td>' +
      '</tr>';
  }

  function renderSearchResults(list) {
    if (!list.length) {
      searchWrap.style.display = 'none';
      searchEmptyEl.style.display = '';
      return;
    }
    searchEmptyEl.style.display = 'none';
    searchWrap.style.display = '';
    searchBody.innerHTML = list.map(searchRow).join('');
  }

  function runSearch(q) {
    var seq = ++searchSeq;
    fetch(BUSCA_URL + '?q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (list) {
        if (seq !== searchSeq) return; // uma busca mais nova ja disparou, ignora esta resposta atrasada
        renderSearchResults(list);
      })
      .catch(function () {
        if (seq !== searchSeq) return;
        renderSearchResults([]);
      });
  }

  if (searchEl) {
    searchEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      var q = searchEl.value.trim();
      if (q.length < 2) {
        searchWrap.style.display = 'none';
        searchEmptyEl.style.display = 'none';
        return;
      }
      searchTimer = setTimeout(function () { runSearch(q); }, 300);
    });
  }

  // Marcar/remover posto avancado - POST compartilhado entre a busca
  // (marcar OU remover, ver markButtonsHtml()) e a lista principal (so
  // remover, ver removeButtonHtml()); os dois delegam o clique no body
  // da tabela em vez de um listener por linha, porque as duas tabelas
  // sao reconstruidas inteiras a cada render (renderSearchResults()/
  // renderList()) e um listener por linha se perderia a cada uma.
  function enviarAtualizacaoPosto(icao, novoPosto, botoesSelector, aoConcluir) {
    document.querySelectorAll(botoesSelector).forEach(function (b) { b.disabled = true; });

    fetch('/aeroportos/' + encodeURIComponent(icao) + '/posto-avancado', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ postoAvancadoDe: novoPosto })
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        if (!result.ok) {
          document.querySelectorAll(botoesSelector).forEach(function (b) { b.disabled = false; });
          showError((result.body && result.body.error) || tr('aeroporto.mark.error', 'Não foi possível atualizar — tente de novo.'));
          return;
        }
        hideError();
        aoConcluir(result.body);
      })
      .catch(function () {
        document.querySelectorAll(botoesSelector).forEach(function (b) { b.disabled = false; });
        showError(tr('aeroporto.mark.error', 'Não foi possível atualizar — verifique sua conexão e tente de novo.'));
      });
  }

  // Mantem lista principal + busca em sincronia depois de qualquer
  // marcar/remover, não importa qual das duas telas disparou - entra na
  // lista principal quando ganha postoAvancadoDe (ou já é base, ver
  // BASES no topo do arquivo), sai quando perde e não é base; a linha
  // de busca, se essa mesma busca ainda estiver na tela, é atualizada
  // in place em vez de recarregada.
  function sincronizarAposAtualizacao(atualizado) {
    AEROPORTOS = AEROPORTOS.filter(function (a) { return a.icao !== atualizado.icao; });
    if (atualizado.postoAvancadoDe || BASES.indexOf(atualizado.icao) !== -1) {
      AEROPORTOS.push(atualizado);
      AEROPORTOS.sort(function (a, b) { return a.icao < b.icao ? -1 : a.icao > b.icao ? 1 : 0; });
    }
    renderList();

    if (searchBody) {
      var row = searchBody.querySelector('tr[data-row-icao="' + atualizado.icao + '"]');
      if (row) row.outerHTML = searchRow(atualizado);
    }
  }

  if (searchBody) {
    searchBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-icao]');
      if (!btn) return;
      enviarAtualizacaoPosto(btn.dataset.icao, btn.dataset.posto || null, '#ap-search-body button[data-icao="' + btn.dataset.icao + '"]', sincronizarAposAtualizacao);
    });
  }

  var listBody = document.getElementById('ap-body');
  listBody.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-icao]');
    if (!btn) return;
    enviarAtualizacaoPosto(btn.dataset.icao, null, '#ap-body button[data-icao="' + btn.dataset.icao + '"]', sincronizarAposAtualizacao);
  });

  /* ---------- editar heading da pista principal ---------- */
  // Compartilhado pela lista principal e pela busca (mesmo par de
  // botoes "Definir heading" desenhados em headingEditButtonHtml()) -
  // um prompt() so, sem formulario dedicado (ver comentario da funcao
  // acima pro porque).
  function enviarHeading(icao, aoConcluir) {
    // So sabe o valor atual pra pre-preencher o prompt() quando o
    // aeroporto ja esta na lista principal (AEROPORTOS) - um resultado
    // so de busca comeca sempre com o prompt em branco, mesmo que ja
    // tenha heading cadastrado (edge case raro, corrigivel digitando de
    // novo ou conferindo a coluna Heading antes de editar).
    var atual = AEROPORTOS.filter(function (a) { return a.icao === icao; })[0];
    var valorAtual = atual && null != atual.pistaPrincipalHeadingMag ? String(atual.pistaPrincipalHeadingMag) : '';
    var resposta = window.prompt(tr('aeroporto.heading.prompt', 'Heading da pista principal (0-359, magnético) — deixe em branco pra limpar:'), valorAtual);
    if (null === resposta) return; // cancelou

    resposta = resposta.trim();
    if ('' !== resposta && (!/^\d+$/.test(resposta) || parseInt(resposta, 10) > 359)) {
      showError(tr('aeroporto.heading.invalid', 'Heading inválido — use de 0 a 359.'));
      return;
    }

    fetch('/aeroportos/' + encodeURIComponent(icao) + '/pista-principal', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pistaPrincipalHeadingMag: '' === resposta ? null : parseInt(resposta, 10) })
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        if (!result.ok) {
          showError((result.body && result.body.error) || tr('aeroporto.heading.error', 'Não foi possível atualizar — tente de novo.'));
          return;
        }
        hideError();
        aoConcluir(result.body);
      })
      .catch(function () {
        showError(tr('aeroporto.heading.error', 'Não foi possível atualizar — verifique sua conexão e tente de novo.'));
      });
  }

  listBody.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-heading-icao]');
    if (!btn) return;
    enviarHeading(btn.dataset.headingIcao, sincronizarAposAtualizacao);
  });
  if (searchBody) {
    searchBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-heading-icao]');
      if (!btn) return;
      enviarHeading(btn.dataset.headingIcao, sincronizarAposAtualizacao);
    });
  }

  /* ---------- form ---------- */
  document.querySelectorAll('#posto-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#posto-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      posto = c.dataset.posto;
    });
  });

  var icaoEl = document.getElementById('f-icao');
  var nomeEl = document.getElementById('f-nome');
  var cidadeEl = document.getElementById('f-cidade');
  var latEl = document.getElementById('f-lat');
  var lonEl = document.getElementById('f-lon');
  var headingEl = document.getElementById('f-heading');
  var saveBtn = document.getElementById('btn-save');
  var saveLabel = saveBtn.textContent;
  var errorEl = document.getElementById('ap-error');

  function updateSaveState() {
    var ok = icaoEl.value.trim() && nomeEl.value.trim() && cidadeEl.value.trim()
      && latEl.value.trim() !== '' && lonEl.value.trim() !== '';
    saveBtn.disabled = !ok;
  }
  [icaoEl, nomeEl, cidadeEl, latEl, lonEl].forEach(function (el) {
    el.addEventListener('input', updateSaveState);
  });

  function showError(message) {
    errorEl.textContent = message;
    errorEl.style.display = '';
    window.scrollTo(0, 0);
  }
  function hideError() {
    errorEl.style.display = 'none';
  }

  saveBtn.addEventListener('click', function () {
    if (saveBtn.disabled) return;

    hideError();
    saveBtn.disabled = true;
    saveBtn.textContent = tr('aeroporto.save.saving', 'Salvando…');

    var payload = {
      icao: icaoEl.value.trim(),
      nome: nomeEl.value.trim(),
      cidade: cidadeEl.value.trim(),
      lat: latEl.value.trim(),
      lon: lonEl.value.trim(),
      postoAvancadoDe: posto,
      pistaPrincipalHeadingMag: headingEl.value.trim()
    };

    function restoreButton() {
      saveBtn.textContent = saveLabel;
      updateSaveState();
    }

    fetch('/aeroportos', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (result) {
        if (!result.ok) {
          var msg = (result.body.errors && result.body.errors[0]) || result.body.error || tr('aeroporto.error.generic', 'Não foi possível salvar o aeroporto — tente novamente.');
          showError(msg);
          restoreButton();
          return;
        }

        AEROPORTOS.push(result.body);
        AEROPORTOS.sort(function (a, b) { return a.icao < b.icao ? -1 : a.icao > b.icao ? 1 : 0; });
        renderList();

        icaoEl.value = ''; nomeEl.value = ''; cidadeEl.value = ''; latEl.value = ''; lonEl.value = ''; headingEl.value = '';
        posto = '';
        document.querySelectorAll('#posto-chips .chip').forEach(function (x, i) { x.classList.toggle('on', i === 0); });
        restoreButton();
      })
      .catch(function () {
        showError(tr('aeroporto.error.generic', 'Não foi possível salvar o aeroporto — verifique sua conexão e tente novamente.'));
        restoreButton();
      });
  });

  document.addEventListener('katabatic:langchange', function () {
    renderList();
  });
})();
