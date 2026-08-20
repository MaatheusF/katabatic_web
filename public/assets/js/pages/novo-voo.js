/* ==========================================================================
   Katabatic — novo-voo.js
   Registro de voo: alterna entre os modos "importar telemetria" e
   "registro manual", simula a leitura da pasta de captura (dropzone) e
   preenche o formulario a partir dela. Porte quase literal do JS do
   mockup original (katabatic-novo-voo.html), so trocando o array
   `AIRCRAFT` embutido por window.KATABATIC_AIRCRAFT (injetado pelo
   NovoVooController) e removendo o toggle de tema, que agora e
   compartilhado (ver theme-toggle.js, carregado antes deste em
   app_base.html.twig).

   Publicar/Salvar rascunho ainda sao mock (alert) - viram POST de
   verdade quando o Logbook for tabela real.
   ========================================================================== */
(function () {
  'use strict';

  var AIRCRAFT = window.KATABATIC_AIRCRAFT || [];

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
    var map = { '1': 'Carga', '2': 'Pessoal', '4': 'Pesquisa', '9': 'Reposicionamento' };
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
    if (e.target.value === '') { box.style.display = 'none'; return; }
    var a = AIRCRAFT[+e.target.value];
    box.style.display = 'flex';
    box.innerHTML = '<span class="dot" style="background:' + a.dot + '"></span>' +
      '<div><b>' + a.reg + '</b><span>' + a.tipo + '</span></div>' +
      '<span class="pos">' + a.status + ' · ' + a.pos + '</span>';
    var origEl = document.getElementById('f-orig');
    if (!origEl.value) {
      origEl.value = a.pos;
      document.getElementById('orig-hint').innerHTML = 'Preenchido com a posição atual da aeronave — ajuste se decolou de outro lugar.';
    }
  });

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
  document.getElementById('f-report').addEventListener('input', function (e) {
    document.getElementById('report-count').textContent = e.target.value.length + ' caracteres';
  });

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

  /* ---------- dropzone: simulacao de importacao ---------- */
  var imported = false;
  document.getElementById('dropzone').addEventListener('click', function () {
    if (imported) return;
    var dz = this;
    dz.innerHTML = '<b>Lendo arquivos…</b><span>calculando índice de dificuldade a partir da telemetria</span>';
    setTimeout(function () {
      imported = true;
      dz.classList.add('done');
      dz.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>' +
        '<b>Importado com sucesso</b><span>20260819_033457_KBT118 · clique para trocar o arquivo</span>';

      document.getElementById('import-result').innerHTML =
        '<div class="filelist">' +
          fileRow('samples.csv', '345 amostras') +
          fileRow('env.csv', '35 leituras') +
          fileRow('events.csv', '6 eventos') +
          fileRow('session.json', 'metadados da sessão') +
        '</div>' +
        '<div class="preview">' +
          prevCell('5:44', 'Duração') +
          prevCell('12,9<span style="font-size:11px"> nm</span>', 'Distância') +
          prevCell('-20,7°', 'Temp. mínima') +
          '<div><div class="diff-mini"><b>64</b></div><span>Dificuldade calculada</span></div>' +
        '</div>';

      // preenche data/rota se ainda vazios, a partir do "arquivo"
      if (!document.getElementById('f-orig').value) document.getElementById('f-orig').value = 'UEEE';
      if (!document.getElementById('f-dest').value) document.getElementById('f-dest').value = 'UEEE';

      // data/hora vem do session.json real, nao de "agora" — e trava para nao ficar errada
      document.getElementById('f-date').value = '2026-08-19';
      document.getElementById('f-time').value = '03:34';
      document.getElementById('f-date').readOnly = true;
      document.getElementById('f-time').readOnly = true;
      document.getElementById('date-lock-tag').style.display = '';
      document.getElementById('date-unlock').style.display = '';

      // tenta casar a matricula do arquivo (ATC ID) com a frota
      var tailFromFile = 'KBT118'; // exatamente o que seus testes gravaram — nao e uma matricula real
      var match = AIRCRAFT.findIndex(function (a) { return a.reg.replace('-', '').toUpperCase() === tailFromFile.replace('-', '').toUpperCase(); });
      var warnBox = document.getElementById('tail-mismatch');
      if (match >= 0) {
        document.getElementById('f-aircraft').value = String(match);
        document.getElementById('f-aircraft').dispatchEvent(new Event('change'));
        warnBox.style.display = 'none';
      } else {
        warnBox.style.display = 'flex';
        warnBox.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>' +
          '<p>O arquivo trouxe a matrícula <b>' + tailFromFile + '</b>, que não corresponde a nenhuma aeronave da frota — parece ser o callsign configurado no lugar do <code>ATC ID</code>. Selecione a aeronave manualmente e confira essa configuração no simulador antes do próximo voo.</p>';
      }

      updatePublishState();
    }, 900);
  });
  function fileRow(name, note) {
    return '<div class="fileitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6L9 17l-5-5"/></svg>' +
      '<b>' + name + '</b><span>' + note + '</span></div>';
  }
  function prevCell(val, label) {
    return '<div><b>' + val + '</b><span>' + label + '</span></div>';
  }

  /* ---------- habilita "Publicar" ---------- */
  function updatePublishState() {
    var ok = mode === 'manual' ? true : imported;
    document.getElementById('btn-publish').disabled = !ok;
  }
  updatePublishState();

  document.getElementById('btn-draft').addEventListener('click', function () {
    alert('Rascunho salvo (mock) — retome depois pelo Logbook.');
  });
  document.getElementById('btn-publish').addEventListener('click', function () {
    alert('Voo publicado (mock) — apareceria agora no Logbook.');
  });
})();
