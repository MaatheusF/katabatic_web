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
   ganhou seu proprio jeito de mudar/desmarcar posto avancado por linha -
   antes so dava pra desmarcar indo ate o card de busca e achando o ICAO
   de novo, mesmo pra um aeroporto que ja estava bem na frente na lista.
   enviarAtualizacaoPosto()/sincronizarAposAtualizacao() abaixo sao
   compartilhados entre a lista e a busca - os dois chamam o mesmo
   POST /aeroportos/{icao}/posto-avancado, so o gatilho e diferente.

   Atualizado de novo (pedido em conversa): a coluna de acoes virou um
   <select> compacto (postoControlHtml()) em vez da pilha de ate 8
   botoes de texto ("Marcar posto de PAFA"/"...SCCI"/.../"Remover") que
   cabia numa linha so antes - o select mostra o posto atual (ou o
   proprio "Nenhuma") como valor selecionado, entao a coluna "Posto
   avancado de" que antes so exibia um selo virou o mesmo select (exibe
   E edita, sem repetir a informacao em duas colunas). "Definir heading"
   virou um icone (lapis) em vez de botao com texto - ver
   headingEditButtonHtml(). Uma base (PAFA/SCCI/...) nunca ganha o
   select, so um traco mudo - ela nao pode ser posto avancado de si
   mesma nem de outra (mesma regra que ja existia, so a UI mudou).
   ========================================================================== */
(function () {
  'use strict';

  var BASES = ['PAFA', 'SCCI', 'SLLP', 'VNKT', 'WAJW', 'VQPR']; // mesma lista que AeroportoRepository::BASES/AeroportoController::BASES_VALIDAS (bases sazonais, ver README) - uma base nunca some da lista principal, mesmo sem postoAvancadoDe (ver sincronizarAposAtualizacao())
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

  // Select compacto de "posto avancado de" - substitui a pilha de
  // botoes de texto que essa coluna tinha antes (ver cabecalho do
  // arquivo). Mostra e edita ao mesmo tempo: a opcao marcada `selected`
  // e o posto atual, trocar a opcao ja dispara o POST (ver listener de
  // 'change' mais abaixo). `data-prev` guarda o valor de antes da troca,
  // pra reverter visualmente se o POST falhar (enviarAtualizacaoPosto()
  // não sabe desfazer sozinho um <select> - um <button> não precisava
  // disso, não muda de estado visual sozinho ao clicar).
  //
  // Uma base nunca ganha esse controle - ela não pode ser posto avançado
  // de si mesma nem de outra (mesma regra que `removeButtonHtml()` já
  // aplicava, só que agora é checada aqui em vez de implícita em
  // "postoAvancadoDe nunca vem preenchido pra uma base").
  function isBase(icao) { return BASES.indexOf(icao) !== -1; }
  function postoControlHtml(a) {
    if (isBase(a.icao)) return '<span class="sub">—</span>';

    var current = a.postoAvancadoDe || '';
    var opts = '<option value="">' + tr('aeroporto.posto.none', 'Nenhuma') + '</option>';
    BASES.forEach(function (b) {
      opts += '<option value="' + b + '"' + (current === b ? ' selected' : '') + '>' + b + '</option>';
    });
    return '<select class="posto-select" data-icao="' + a.icao + '" data-prev="' + current + '" aria-label="' + tr('aeroporto.posto', 'Posto avançado de') + '">' + opts + '</select>';
  }

  // Botao "editar heading" compartilhado pela lista principal e pela
  // busca - so um prompt() simples (0-359 ou vazio pra limpar) em vez de
  // um segundo formulario inteiro, ver enviarHeading()/wiring no fim do
  // arquivo. Virou icone (lapis) em vez de texto - `title`/`aria-label`
  // carregam o rotulo pra quem passa o mouse/usa leitor de tela.
  // `data-heading-icao` (nao `data-icao`, que o select de posto avancado
  // usa) evita colidir com o listener delegado do select nas mesmas
  // tabelas.
  function headingCellHtml(a) {
    return null == a.pistaPrincipalHeadingMag
      ? '<span class="sub">—</span>'
      : a.pistaPrincipalHeadingMag + '°';
  }
  function headingEditButtonHtml(a) {
    var label = tr('aeroporto.heading.edit', 'Definir heading');
    return '<button class="ap-icon-btn" data-heading-icao="' + a.icao + '" type="button" title="' + label + '" aria-label="' + label + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>' +
      '</button>';
  }

  // Coluna de acoes compartilhada pelas duas tabelas - select de posto
  // avancado (ou traco mudo, se for base) + icone de heading.
  function actionsCellHtml(a) {
    return '<div class="ap-row-actions">' + postoControlHtml(a) + headingEditButtonHtml(a) + '</div>';
  }

  function airportRow(a) {
    return '<tr>' +
      '<td class="mono">' + a.icao + codigoLocalTag(a) + '</td>' +
      '<td>' + a.nome + '</td>' +
      '<td>' + a.cidade + '</td>' +
      '<td class="num mono">' + a.lat.toFixed(4) + '</td>' +
      '<td class="num mono">' + a.lon.toFixed(4) + '</td>' +
      '<td class="num mono">' + headingCellHtml(a) + '</td>' +
      '<td>' + actionsCellHtml(a) + '</td>' +
      '</tr>';
  }

  function renderList() {
    var body = document.getElementById('ap-body');
    body.innerHTML = AEROPORTOS.length
      ? AEROPORTOS.map(airportRow).join('')
      : '<tr><td colspan="7" class="empty">' + tr('aeroporto.list.empty', 'Nenhum aeroporto cadastrado ainda.') + '</td></tr>';
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

  function searchRow(a) {
    return '<tr data-row-icao="' + a.icao + '">' +
      '<td class="mono">' + a.icao + codigoLocalTag(a) + '</td>' +
      '<td>' + a.nome + '</td>' +
      '<td>' + a.cidade + '</td>' +
      '<td class="num mono">' + headingCellHtml(a) + '</td>' +
      '<td>' + actionsCellHtml(a) + '</td>' +
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

  // Marcar/remover posto avancado - POST compartilhado entre a busca e a
  // lista principal (mesmo <select>, ver postoControlHtml()); os dois
  // delegam o evento no body da tabela em vez de um listener por linha,
  // porque as duas tabelas sao reconstruidas inteiras a cada render
  // (renderSearchResults()/renderList()) e um listener por linha se
  // perderia a cada uma. `aoErro`, se passado, roda além do banner de
  // erro padrão - usado pelo <select> pra voltar visualmente ao valor
  // anterior quando o POST falha (um <button> não precisava disso, não
  // muda de estado visual sozinho ao clicar).
  function enviarAtualizacaoPosto(icao, novoPosto, elementosSelector, aoConcluir, aoErro) {
    document.querySelectorAll(elementosSelector).forEach(function (b) { b.disabled = true; });

    fetch('/aeroportos/' + encodeURIComponent(icao) + '/posto-avancado', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ postoAvancadoDe: novoPosto })
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        if (!result.ok) {
          document.querySelectorAll(elementosSelector).forEach(function (b) { b.disabled = false; });
          if (aoErro) aoErro();
          showError((result.body && result.body.error) || tr('aeroporto.mark.error', 'Não foi possível atualizar — tente de novo.'));
          return;
        }
        hideError();
        aoConcluir(result.body);
      })
      .catch(function () {
        document.querySelectorAll(elementosSelector).forEach(function (b) { b.disabled = false; });
        if (aoErro) aoErro();
        showError(tr('aeroporto.mark.error', 'Não foi possível atualizar — verifique sua conexão e tente de novo.'));
      });
  }

  // Disparado pelo 'change' do <select> de posto avancado, nas duas
  // tabelas (ver listeners logo abaixo). `sel.dataset.prev` guarda o
  // valor de antes da troca (gravado por postoControlHtml() a cada
  // render) - se o POST falhar, volta o <select> pra ele.
  function onPostoSelectChange(sel, escopoSelector) {
    var icao = sel.dataset.icao;
    var novoPosto = sel.value || null;
    var prev = sel.dataset.prev;
    enviarAtualizacaoPosto(
      icao, novoPosto,
      escopoSelector + ' [data-icao="' + icao + '"]',
      sincronizarAposAtualizacao,
      function () { sel.value = prev; }
    );
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
    searchBody.addEventListener('change', function (e) {
      var sel = e.target.closest('select[data-icao]');
      if (!sel) return;
      onPostoSelectChange(sel, '#ap-search-body');
    });
  }

  var listBody = document.getElementById('ap-body');
  listBody.addEventListener('change', function (e) {
    var sel = e.target.closest('select[data-icao]');
    if (!sel) return;
    onPostoSelectChange(sel, '#ap-body');
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
