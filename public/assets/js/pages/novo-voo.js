/* ==========================================================================
   Katabatic — novo-voo.js
   Registro de voo: alterna entre os modos "importar telemetria" e
   "registro manual", le de verdade o upload_payload.json que o script de
   captura grava (ver NovoVooController::importarPreview()/
   importarPublicar() e docblock da classe) e preenche o formulario a
   partir dele. Porte quase literal do JS do mockup original
   (katabatic-novo-voo.html), so trocando o array `AIRCRAFT` embutido por
   window.KATABATIC_AIRCRAFT (injetado pelo NovoVooController) e removendo
   o toggle de tema, que agora e compartilhado (ver theme-toggle.js,
   carregado antes deste em app_base.html.twig).

   "Salvar rascunho" agora persiste de verdade (POST /novo-voo/rascunho,
   ver App\Entity\VooRascunho e docblock de NovoVooController) - ao
   carregar a pagina, se o piloto tinha um rascunho salvo,
   applyRascunho() abaixo pre-preenche o formulario sozinho. Publicar,
   nos dois modos, grava de verdade e apaga o rascunho.
   ========================================================================== */
(function () {
  'use strict';

  var AIRCRAFT = window.KATABATIC_AIRCRAFT || [];

  // Formatacao de duracao derivada (preview do import) - mesmo estilo
  // "MM:SS sem rollover de hora" que voo.js usa pros KPIs do relatorio
  // (mmss()), pra ficar consistente com como a duracao de um voo com
  // telemetria sempre aparece no site.
  function mmss(s) {
    s = Math.max(0, Math.round(s));
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  }

  // Le o dicionario EN da pagina (window.KATABATIC_I18N_EN, definido no
  // page_javascripts de novo_voo/index.html.twig) - mesmo padrao do
  // helper equivalente em voo.js.
  function L(key, ptFallback) {
    var en = window.KATABATIC_I18N_EN || {};
    return (window.katabaticLang && window.katabaticLang() === 'en' && en[key]) ? en[key] : ptFallback;
  }

  /* ---------- modo importar / manual ---------- */
  var mode = 'import';
  function setMode(m) {
    mode = m;
    document.getElementById('mode-import').classList.toggle('on', m === 'import');
    document.getElementById('mode-manual').classList.toggle('on', m === 'manual');
    document.getElementById('card-import').style.display = m === 'import' ? '' : 'none';
    document.getElementById('card-manual').style.display = m === 'manual' ? '' : 'none';
    updatePublishState();
  }
  document.getElementById('mode-import').addEventListener('click', function () { setMode('import'); });
  document.getElementById('mode-manual').addEventListener('click', function () { setMode('manual'); });

  /* ---------- callsign -> sugere tipo ---------- */
  document.getElementById('f-call').addEventListener('input', function (e) {
    var n = e.target.value.replace(/\D/g, '').slice(0, 3);
    e.target.value = n;
    var first = n[0];
    var map = { '1': 'Carga', '2': 'Pessoal', '4': 'Pesquisa', '9': 'Reposicionamento', '5': 'Medvec' };
    if (first && map[first]) {
      document.querySelectorAll('#tipo-chips .chip').forEach(function (c) {
        c.classList.toggle('on', c.dataset.tipo === map[first]);
      });
    }
  });
  document.querySelectorAll('#tipo-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#tipo-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
    });
  });

  /* ---------- aeronave -> mostra ficha + sugere origem ---------- */
  document.getElementById('f-aircraft').addEventListener('change', function (e) {
    var box = document.getElementById('aircraft-pick');
    if (e.target.value === '') { box.style.display = 'none'; updateOcorrenciasHelicoptero(null); return; }
    var a = AIRCRAFT[+e.target.value];
    box.style.display = 'flex';
    box.innerHTML = '<span class="dot" style="background:' + a.dot + '"></span>' +
      '<div><b>' + a.reg + '</b><span>' + a.tipo + '</span></div>' +
      '<span class="pos">' + a.status + ' · ' + a.pos + '</span>';
    var origEl = document.getElementById('f-orig');
    if (!origEl.value) {
      origEl.value = a.pos;
      document.getElementById('orig-hint').innerHTML = L('novovoo.orighint.autofilled', 'Preenchido com a posição atual da aeronave — ajuste se decolou de outro lugar.');
    }
    updateOcorrenciasHelicoptero(a.categoria);
  });

  // Chips de ocorrencia especificos de helicoptero (Autorrotacao/LTE/
  // Vortex ring state, ver NovoVooController::OCORRENCIA_TAGS) so
  // aparecem quando a aeronave selecionada e categoria 'Helicoptero'
  // (Aeronave::$tipo -> TipoAeronave::$categoria, casamento fraco por
  // string ja resolvido no servidor em aircraftViewModel()). Trocar de
  // aeronave pra uma que nao e helicoptero desmarca qualquer um desses
  // tres que estivesse ligado, senao um voo de aviao poderia sair com
  // "LTE" marcado sem o piloto ter acesso ao chip pra desmarcar de novo.
  var OCOR_HELI_IDS = ['ocor-autorrotacao', 'ocor-lte', 'ocor-vrs'];
  function updateOcorrenciasHelicoptero(categoria) {
    var mostrar = categoria === 'Helicoptero';
    OCOR_HELI_IDS.forEach(function (id) {
      var chip = document.getElementById(id);
      chip.style.display = mostrar ? '' : 'none';
      if (!mostrar && chip.classList.contains('on')) chip.click();
    });
  }

  /* ---------- data/hora padrao = agora, so relevante no modo manual ---------- */
  (function () {
    var now = new Date();
    document.getElementById('f-date').value = now.toISOString().slice(0, 10);
    document.getElementById('f-time').value = now.toISOString().slice(11, 16);
  })();
  document.getElementById('mode-manual').addEventListener('click', function () {
    document.getElementById('f-date').readOnly = false;
    document.getElementById('f-time').readOnly = false;
    document.getElementById('date-lock-tag').style.display = 'none';
    document.getElementById('date-unlock').style.display = 'none';
  });

  /* ---------- contador do relato ---------- */
  function updateReportCount() {
    var n = document.getElementById('f-report').value.length;
    document.getElementById('report-count').textContent = window.katabaticLang && window.katabaticLang() === 'en' ?
      n + ' character' + (n === 1 ? '' : 's') : n + ' caractere' + (n === 1 ? '' : 's');
  }
  document.getElementById('f-report').addEventListener('input', updateReportCount);

  /* ---------- slider manual ---------- */
  document.getElementById('f-diff-manual').addEventListener('input', function (e) {
    document.getElementById('diff-manual-val').textContent = e.target.value;
  });

  /* ---------- ocorrencia (multi-select, "Nenhuma" e exclusivo) ----------
     Um voo pode ter mais de uma ocorrencia (overspeed E quique no mesmo
     pouso, por exemplo) - diferente de tipo/visibilidade, que sao
     single-select. "Nenhuma" nao combina com as outras: marcar "Nenhuma"
     desmarca tudo mais, e marcar qualquer outra desmarca "Nenhuma". */
  document.querySelectorAll('#ocor-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      var chips = document.querySelectorAll('#ocor-chips .chip');
      if (c.dataset.ocor === 'Nenhuma') {
        chips.forEach(function (x) { x.classList.toggle('on', x === c); });
        return;
      }
      c.classList.toggle('on');
      var noneChip = document.querySelector('#ocor-chips .chip[data-ocor="Nenhuma"]');
      var anyOn = Array.prototype.some.call(chips, function (x) { return x !== noneChip && x.classList.contains('on'); });
      noneChip.classList.toggle('on', !anyOn);
    });
  });

  /* ---------- visibilidade ---------- */
  document.querySelectorAll('#vis-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#vis-chips .chip').forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
    });
  });

  /* ---------- trava de data/hora apos import ---------- */
  document.getElementById('date-unlock').addEventListener('click', function () {
    document.getElementById('f-date').readOnly = false;
    document.getElementById('f-time').readOnly = false;
    document.getElementById('date-lock-tag').style.display = 'none';
    this.style.display = 'none';
  });

  /* ---------- dropzone: importação de verdade do upload_payload.json ----------
     O piloto seleciona (clique ou arrasta) o upload_payload.json que
     katabatic_capture.py sempre grava na pasta da gravação (ver docblock
     de NovoVooController) - o navegador lê/valida o JSON localmente,
     manda pro backend calcular um preview de verdade (duração/distância/
     temp. mínima/dificuldade, via TelemetryDeriver) sem persistir nada,
     e guarda o payload em memória (importedPayload) pra reenviar junto
     no Publicar - ver importPreview()/collectImportPublishPayload() abaixo. */
  var imported = false;
  var importedPayload = null;
  var dropzone = document.getElementById('dropzone');
  var dropInput = document.getElementById('dropzone-input');

  dropzone.addEventListener('click', function () { dropInput.click(); });
  dropzone.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropInput.click(); }
  });
  dropInput.addEventListener('change', function () {
    if (this.files && this.files[0]) handleImportedFile(this.files[0]);
    this.value = ''; // permite selecionar o mesmo arquivo de novo (ex.: reimportar após corrigir a gravação)
  });
  ['dragover', 'dragenter'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.add('over'); });
  });
  ['dragleave', 'dragend'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.remove('over'); });
  });
  dropzone.addEventListener('drop', function (e) {
    e.preventDefault();
    dropzone.classList.remove('over');
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) handleImportedFile(f);
  });

  function dropzoneState(state, title, sub) {
    dropzone.classList.remove('done', 'error');
    if (state) dropzone.classList.add(state);
    var icon = state === 'done'
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>'
      : state === 'error'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5"/><path d="M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"/></svg>';
    dropzone.innerHTML = icon + '<b>' + title + '</b><span>' + sub + '</span>';
  }

  function handleImportedFile(file) {
    imported = false;
    importedPayload = null;
    document.getElementById('import-result').innerHTML = '';
    updatePublishState();
    dropzoneState('', L('novovoo.dropzone.reading', 'Lendo arquivo…'), file.name);

    var reader = new FileReader();
    reader.onerror = function () {
      dropzoneState('error', L('novovoo.dropzone.readerror', 'Não deu para ler o arquivo'), L('novovoo.dropzone.click', 'clique para selecionar o upload_payload.json'));
    };
    reader.onload = function () {
      var parsed;
      try {
        parsed = JSON.parse(String(reader.result));
      } catch (e) {
        dropzoneState('error', L('novovoo.dropzone.badjson', 'Arquivo não é um JSON válido'), L('novovoo.dropzone.click', 'clique para selecionar o upload_payload.json'));
        return;
      }
      var samples = Array.isArray(parsed.samples) ? parsed.samples : [];
      if (!samples.length || !parsed.started_at) {
        dropzoneState('error', L('novovoo.dropzone.badshape', 'Não parece o upload_payload.json'), L('novovoo.dropzone.badshape.hint', 'confira se não é o session.json — selecione o arquivo certo'));
        return;
      }
      importPreview(parsed, file.name);
    };
    reader.readAsText(file);
  }

  function importPreview(payload, fileName) {
    var aircraftSel = document.getElementById('f-aircraft');
    var selectedReg = AIRCRAFT[+aircraftSel.value] ? AIRCRAFT[+aircraftSel.value].reg : '';
    fetch(window.KATABATIC_NOVOVOO_IMPORTAR_PREVIEW_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payload: payload, aeronaveReg: selectedReg })
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        if (!result.ok) {
          dropzoneState('error', L('novovoo.dropzone.previewerror', 'Não foi possível ler a telemetria'), (result.body && result.body.errors && result.body.errors[0]) || L('novovoo.publish.generr', 'Não foi possível publicar — verifique sua conexão e tente novamente.'));
          return;
        }
        applyImported(payload, fileName, result.body);
      })
      .catch(function () {
        dropzoneState('error', L('novovoo.dropzone.previewerror', 'Não foi possível ler a telemetria'), L('novovoo.publish.generr', 'Não foi possível publicar — verifique sua conexão e tente novamente.'));
      });
  }

  function applyImported(payload, fileName, preview) {
    imported = true;
    importedPayload = payload;
    dropzoneState('done', L('novovoo.dropzone.imported', 'Importado com sucesso'), fileName + ' · ' + L('novovoo.dropzone.clicktoswap', 'clique para trocar o arquivo'));

    var samples = Array.isArray(payload.samples) ? payload.samples : [];
    var env = Array.isArray(payload.env) ? payload.env : [];
    var events = Array.isArray(payload.events) ? payload.events : [];

    document.getElementById('import-result').innerHTML =
      '<div class="filelist">' +
        fileRow(fileName, samples.length + ' ' + L('novovoo.import.samples', 'amostras') + ' · ' + env.length + ' ' + L('novovoo.import.readings', 'leituras') + ' · ' + events.length + ' ' + L('novovoo.import.events', 'eventos')) +
      '</div>' +
      '<div class="preview">' +
        prevCell(mmss(preview.durSeg), L('novovoo.import.duration', 'Duração')) +
        prevCell(preview.distNm.toFixed(1) + '<span style="font-size:11px"> nm</span>', L('novovoo.import.distance', 'Distância')) +
        prevCell(preview.oatMinC.toFixed(1) + '°', L('novovoo.import.mintemp', 'Temp. mínima')) +
        '<div><div class="diff-mini"><b>' + preview.difficulty + '</b></div><span>' + L('novovoo.import.calcdiff', 'Dificuldade calculada') + '</span></div>' +
      '</div>';

    // preenche callsign/tipo/rota se ainda vazios, a partir do arquivo -
    // gravações feitas sem --tipo/--origem/--destino (o caso mais comum
    // de precisar desta tela, ver docblock de NovoVooController) chegam
    // com esses campos vazios no payload, então só preenche quando o
    // arquivo de fato trouxe algo.
    var callsignNum = (payload.callsign || '').replace(/\D/g, '').slice(0, 3);
    if (callsignNum && !document.getElementById('f-call').value) {
      document.getElementById('f-call').value = callsignNum;
    }
    if (payload.tipo_operacao) {
      document.querySelectorAll('#tipo-chips .chip').forEach(function (c) {
        c.classList.toggle('on', c.dataset.tipo === payload.tipo_operacao);
      });
    }
    if (payload.origem && !document.getElementById('f-orig').value) document.getElementById('f-orig').value = payload.origem;
    if (payload.destino && !document.getElementById('f-dest').value) document.getElementById('f-dest').value = payload.destino;

    // data/hora vem do arquivo, não de "agora" — e trava pra não ficar errada
    var startedAt = new Date(payload.started_at);
    if (!isNaN(startedAt.getTime())) {
      document.getElementById('f-date').value = startedAt.toISOString().slice(0, 10);
      document.getElementById('f-time').value = startedAt.toISOString().slice(11, 16);
      document.getElementById('f-date').readOnly = true;
      document.getElementById('f-time').readOnly = true;
      document.getElementById('date-lock-tag').style.display = '';
      document.getElementById('date-unlock').style.display = '';
    }

    // tenta casar a matrícula do arquivo (ATC ID) com a frota
    var tailFromFile = (payload.ident && payload.ident.tail_number) || '';
    var warnBox = document.getElementById('tail-mismatch');
    var match = tailFromFile
      ? AIRCRAFT.findIndex(function (a) { return a.reg.replace('-', '').toUpperCase() === tailFromFile.replace('-', '').toUpperCase(); })
      : -1;
    if (match >= 0) {
      document.getElementById('f-aircraft').value = String(match);
      document.getElementById('f-aircraft').dispatchEvent(new Event('change'));
      warnBox.style.display = 'none';
    } else if (tailFromFile) {
      warnBox.style.display = 'flex';
      warnBox.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>' +
        '<p>' + L('novovoo.tailmismatch', 'O arquivo trouxe a matrícula <b>' + tailFromFile + '</b>, que não corresponde a nenhuma aeronave da frota — parece ser o callsign configurado no lugar do <code>ATC ID</code>. Selecione a aeronave manualmente e confira essa configuração no simulador antes do próximo voo.').replace('{tail}', tailFromFile) + '</p>';
    } else {
      warnBox.style.display = 'none';
    }

    updatePublishState();
  }
  function fileRow(name, note) {
    return '<div class="fileitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6L9 17l-5-5"/></svg>' +
      '<b>' + name + '</b><span>' + note + '</span></div>';
  }
  function prevCell(val, label) {
    return '<div><b>' + val + '</b><span>' + label + '</span></div>';
  }

  /* ---------- validacao de campos, nos dois modos ---------- */
  var DURATION_RE = /^\d{1,3}:[0-5]\d$/;

  // Campos minimos pro backend aceitar (mesma validacao, so que no
  // cliente, pra nao deixar "Publicar" habilitado sem chance de dar
  // certo - a validacao de verdade continua sendo a do servidor).
  function manualFieldsOk() {
    var call = document.getElementById('f-call').value;
    var aircraft = document.getElementById('f-aircraft').value;
    var orig = document.getElementById('f-orig').value.trim();
    var dest = document.getElementById('f-dest').value.trim();
    var date = document.getElementById('f-date').value;
    var time = document.getElementById('f-time').value;
    var duration = document.getElementById('f-duration').value.trim();
    return /^\d{1,3}$/.test(call) && aircraft !== '' && orig !== '' && dest !== '' &&
      date !== '' && time !== '' && DURATION_RE.test(duration);
  }

  // Igual manualFieldsOk() acima: so garante que da pra tentar publicar,
  // a validacao de verdade continua sendo a do servidor
  // (NovoVooController::importarPublicar()).
  function importFieldsOk() {
    return imported && document.getElementById('f-aircraft').value !== '';
  }

  /* ---------- habilita "Publicar" ---------- */
  function updatePublishState() {
    var ok = mode === 'manual' ? manualFieldsOk() : importFieldsOk();
    document.getElementById('btn-publish').disabled = !ok;
  }
  updatePublishState();

  ['f-call', 'f-orig', 'f-dest', 'f-date', 'f-time', 'f-duration'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', updatePublishState);
  });
  document.getElementById('f-aircraft').addEventListener('change', updatePublishState);

  function showFormError(messages) {
    var box = document.getElementById('nv-error');
    box.innerHTML = messages.length > 1
      ? '<ul>' + messages.map(function (m) { return '<li>' + m + '</li>'; }).join('') + '</ul>'
      : messages[0];
    box.style.display = '';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideFormError() {
    document.getElementById('nv-error').style.display = 'none';
  }

  function collectManualPayload() {
    var m = document.getElementById('f-duration').value.trim().match(DURATION_RE) ? document.getElementById('f-duration').value.trim() : '';
    var ocorrencias = Array.prototype.map.call(
      document.querySelectorAll('#ocor-chips .chip.on[data-ocor]:not([data-ocor="Nenhuma"])'),
      function (c) { return c.dataset.ocor; }
    );
    var visChip = document.querySelector('#vis-chips .chip.on');
    var tipoChip = document.querySelector('#tipo-chips .chip.on');
    return {
      callsignNum: document.getElementById('f-call').value,
      tipoOperacao: tipoChip ? tipoChip.dataset.tipo : '',
      aeronaveReg: AIRCRAFT[+document.getElementById('f-aircraft').value] ? AIRCRAFT[+document.getElementById('f-aircraft').value].reg : '',
      origem: document.getElementById('f-orig').value.trim(),
      destino: document.getElementById('f-dest').value.trim(),
      data: document.getElementById('f-date').value,
      hora: document.getElementById('f-time').value,
      duracao: m,
      condicao: document.getElementById('f-cond').value,
      ocorrencias: ocorrencias,
      dificuldade: +document.getElementById('f-diff-manual').value,
      objetivo: document.getElementById('f-obj').value.trim(),
      relato: document.getElementById('f-report').value.trim(),
      simbrief: document.getElementById('f-simbrief').value.trim(),
      visibilidade: visChip ? visChip.dataset.vis : 'publico'
    };
  }

  function collectImportPublishPayload() {
    var tipoChip = document.querySelector('#tipo-chips .chip.on');
    var visChip = document.querySelector('#vis-chips .chip.on');
    return {
      payload: importedPayload,
      callsignNum: document.getElementById('f-call').value,
      tipoOperacao: tipoChip ? tipoChip.dataset.tipo : '',
      aeronaveReg: AIRCRAFT[+document.getElementById('f-aircraft').value] ? AIRCRAFT[+document.getElementById('f-aircraft').value].reg : '',
      origem: document.getElementById('f-orig').value.trim(),
      destino: document.getElementById('f-dest').value.trim(),
      objetivo: document.getElementById('f-obj').value.trim(),
      relato: document.getElementById('f-report').value.trim(),
      simbrief: document.getElementById('f-simbrief').value.trim(),
      visibilidade: visChip ? visChip.dataset.vis : 'publico'
    };
  }

  /* ---------- rascunho: salvar/restaurar/descartar de verdade ----------
     "Salvar rascunho" grava o mesmo formato que collectManualPayload()
     monta (mais `mode`) via POST /novo-voo/rascunho - os campos vivem
     nos cards de Identificação/Rota/Missão, compartilhados pelos dois
     modos, então funciona igual em "Importar telemetria" ou "Registro
     manual" (só o payload de telemetria importado em si não é salvo,
     ver docblock de VooRascunho). */
  var hasDraft = false;

  function findAircraftIndex(reg) {
    if (!reg) return -1;
    for (var i = 0; i < AIRCRAFT.length; i++) {
      if (AIRCRAFT[i].reg === reg) return i;
    }
    return -1;
  }

  function setDraftStatus(text) {
    document.getElementById('draft-status').textContent = text;
  }

  function showDiscardButton(show) {
    document.getElementById('btn-draft-discard').style.display = show ? '' : 'none';
  }

  function applyRascunho(d) {
    if (d.callsignNum) document.getElementById('f-call').value = d.callsignNum;
    if (d.tipoOperacao) {
      document.querySelectorAll('#tipo-chips .chip').forEach(function (c) {
        c.classList.toggle('on', c.dataset.tipo === d.tipoOperacao);
      });
    }
    var idx = findAircraftIndex(d.aeronaveReg);
    if (idx >= 0) {
      document.getElementById('f-aircraft').value = String(idx);
      document.getElementById('f-aircraft').dispatchEvent(new Event('change'));
    }
    if (d.origem) document.getElementById('f-orig').value = d.origem;
    if (d.destino) document.getElementById('f-dest').value = d.destino;
    if (d.data) document.getElementById('f-date').value = d.data;
    if (d.hora) document.getElementById('f-time').value = d.hora;
    if (d.duracao) document.getElementById('f-duration').value = d.duracao;
    if (d.condicao) document.getElementById('f-cond').value = d.condicao;
    if (Array.isArray(d.ocorrencias)) {
      document.querySelectorAll('#ocor-chips .chip').forEach(function (c) {
        c.classList.toggle('on', c.dataset.ocor === 'Nenhuma' ? d.ocorrencias.length === 0 : d.ocorrencias.indexOf(c.dataset.ocor) >= 0);
      });
    }
    if (typeof d.dificuldade === 'number') {
      document.getElementById('f-diff-manual').value = d.dificuldade;
      document.getElementById('diff-manual-val').textContent = d.dificuldade;
    }
    if (d.objetivo) document.getElementById('f-obj').value = d.objetivo;
    if (d.relato) {
      document.getElementById('f-report').value = d.relato;
      updateReportCount();
    }
    if (d.simbrief) document.getElementById('f-simbrief').value = d.simbrief;
    if (d.visibilidade) {
      document.querySelectorAll('#vis-chips .chip').forEach(function (c) {
        c.classList.toggle('on', c.dataset.vis === d.visibilidade);
      });
    }
    setMode(d.mode === 'manual' ? 'manual' : 'import');
    updatePublishState();
  }

  document.getElementById('btn-draft').addEventListener('click', function () {
    var btn = this;
    var originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = L('novovoo.draft.saving', 'Salvando…');
    var payload = collectManualPayload();
    payload.mode = mode;
    fetch(window.KATABATIC_NOVOVOO_SALVAR_RASCUNHO_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        btn.disabled = false;
        btn.textContent = originalLabel;
        if (!result.ok) {
          setDraftStatus(L('novovoo.draft.saveerror', 'Não foi possível salvar o rascunho — tente de novo.'));
          return;
        }
        hasDraft = true;
        showDiscardButton(true);
        setDraftStatus(L('novovoo.draft.saved', 'Rascunho salvo às') + ' ' + result.body.salvoEm);
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = originalLabel;
        setDraftStatus(L('novovoo.draft.saveerror', 'Não foi possível salvar o rascunho — tente de novo.'));
      });
  });

  document.getElementById('btn-draft-discard').addEventListener('click', function () {
    if (!window.confirm(L('novovoo.draft.discard.confirm', 'Descartar o rascunho salvo? O formulário nesta tela não é afetado.'))) return;
    fetch(window.KATABATIC_NOVOVOO_DESCARTAR_RASCUNHO_URL, { method: 'DELETE' })
      .then(function () {
        hasDraft = false;
        showDiscardButton(false);
        setDraftStatus('');
      });
  });
  document.getElementById('btn-publish').addEventListener('click', function () {
    var isManual = mode === 'manual';
    if (isManual && !manualFieldsOk()) {
      showFormError([L('novovoo.publish.required', 'Preencha callsign, aeronave, rota, data/hora e duração para publicar.')]);
      return;
    }
    if (!isManual && !importFieldsOk()) {
      showFormError([L('novovoo.publish.importrequired', 'Importe o upload_payload.json e selecione a aeronave para publicar.')]);
      return;
    }
    hideFormError();
    var btn = this;
    var originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = L('novovoo.publish.publishing', 'Publicando…');
    fetch(isManual ? window.KATABATIC_NOVOVOO_PUBLICAR_URL : window.KATABATIC_NOVOVOO_IMPORTAR_PUBLICAR_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(isManual ? collectManualPayload() : collectImportPublishPayload())
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (result) {
        if (!result.ok) {
          showFormError((result.body && result.body.errors) || [L('novovoo.publish.generr', 'Não foi possível publicar — verifique sua conexão e tente novamente.')]);
          btn.disabled = false;
          btn.textContent = originalLabel;
          return;
        }
        window.location.href = window.KATABATIC_PORTAL_URL;
      })
      .catch(function () {
        showFormError([L('novovoo.publish.generr', 'Não foi possível publicar — verifique sua conexão e tente novamente.')]);
        btn.disabled = false;
        btn.textContent = originalLabel;
      });
  });

  // novo-voo.js nao tinha nenhum tratamento de 'katabatic:langchange'.
  // A maior parte da tela e formulario estatico (ja coberto por
  // data-i18n no template), mas o contador de caracteres do relato
  // precisa recalcular o texto na troca de idioma mesmo sem o usuario
  // ter digitado nada ainda.
  document.addEventListener('katabatic:langchange', function () {
    updateReportCount();
  });

  // Restaura o rascunho do piloto (se existir) - window.KATABATIC_NOVOVOO_RASCUNHO
  // vem de NovoVooController::index(), que já busca no banco (ver
  // docblock da classe). Fica no fim do arquivo de propósito: precisa
  // rodar depois que setMode()/os listeners de f-aircraft/updatePublishState
  // já existem, porque applyRascunho() os aciona.
  (function () {
    var rascunho = window.KATABATIC_NOVOVOO_RASCUNHO;
    if (!rascunho) return;
    hasDraft = true;
    applyRascunho(rascunho);
    showDiscardButton(true);
    var salvoEm = window.KATABATIC_NOVOVOO_RASCUNHO_SALVO_EM;
    setDraftStatus(L('novovoo.draft.restored', 'Rascunho restaurado (salvo às') + (salvoEm ? ' ' + salvoEm + ')' : ')'));
  })();
})();
