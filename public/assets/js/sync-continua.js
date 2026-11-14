(() => {
  const form = document.querySelector('#sync-form');
  const runButton = document.querySelector('#run-button');
  const stopButton = document.querySelector('#stop-button');
  const resumeButton = document.querySelector('#resume-button');
  const retryButton = document.querySelector('#retry-button');
  const initial = document.querySelector('#initial-date');
  const final = document.querySelector('#final-date');
  const blockDays = document.querySelector('#block-days');
  const cardEstado = document.querySelector('#cardEstado');
  const cardCursor = document.querySelector('#cardCursor');
  const cardBloco = document.querySelector('#cardBloco');
  const cardTabela = document.querySelector('#cardTabela');
  const cardHeartbeat = document.querySelector('#cardHeartbeat');
  const progress = document.querySelector('#progress');
  const progressFill = document.querySelector('#progress-fill');
  const progressBody = document.querySelector('#progress-body');
  const result = document.querySelector('#result');
  const logPanel = document.querySelector('#log-panel');
  const logBody = document.querySelector('#log-body');
  const endpoint = '/api/cycle/sync/continuo.php';
  const STALE_SECONDS = 180;
  let pollTimer = null;
  let currentJob = null;

  const setValue = (el, value) => { if (el) el.textContent = value ?? '-'; };

  // Campos de data como TEXTO com máscara (dd/mm/aaaa) e botão de calendário: digitar "30032025" vira 30/03/2025.
  // O seletor nativo <input type="date"> (segmentos dia/mês/ano) mandava a digitação para o ano ("0025", "0002").
  if (!window.DateField) throw new Error('date-field.js não foi carregado.');
  window.DateField.attach(initial);
  window.DateField.attach(final);
  const MIN_ISO = window.DateField.MIN_ISO;

  // Validação local (mesmo conjunto do backend), para o usuário ver na hora.
  function validateInputs() {
    if (window.DateField.invalid(initial) || window.DateField.invalid(final)) {
      return 'Data inválida: use o formato dia/mês/ano com 4 dígitos no ano (ex.: 29/03/2025).';
    }
    const ini = window.DateField.iso(initial);
    const fim = window.DateField.iso(final);
    const block = Number(blockDays.value);
    if (!ini || !fim) return 'Informe as datas inicial e final.';
    if (ini < MIN_ISO || fim < MIN_ISO) return 'Confira o ano das datas: digite os 4 dígitos (a partir de 2020).';
    if (ini > fim) return 'A data inicial não pode ser posterior à data final.';
    if (!(block >= 1 && block <= 10)) return 'O tamanho do bloco deve ser entre 1 e 10 dias.';
    return null;
  }

  function showResult(message, type, asHtml = false) {
    result.hidden = false;
    result.className = `panel ${type === 'error' ? 'error' : 'success'}`;
    if (asHtml) result.innerHTML = message; else result.textContent = message;
  }

  function renderJob(job) {
    currentJob = job && job.id ? job : null;

    if (!currentJob) {
      setValue(cardEstado, 'Nenhum job');
      logPanel.hidden = true;
      updateButtons();
      return;
    }

    const prog = currentJob.progresso || {};
    setValue(cardEstado, currentJob.estado);
    setValue(cardCursor, `${currentJob.cursor_date || '-'} / ${currentJob.cap_date || '-'}`);
    setValue(cardBloco, currentJob.bloco_inicio ? `${currentJob.bloco_inicio} → ${currentJob.bloco_fim}` : '-');
    setValue(cardTabela, currentJob.tabela_atual || prog.tabela || '-');
    setValue(cardHeartbeat, currentJob.heartbeat || '-');
    progressFill.style.width = `${currentJob.percent ?? 0}%`;

    renderLogs(currentJob.logs || []);
    updateButtons();

    const estado = currentJob.estado;
    if (estado === 'RUNNING' || estado === 'QUEUED') {
      progress.hidden = false;
      progressBody.textContent = `Processando ${currentJob.tabela_atual || '…'} — bloco ${currentJob.bloco_inicio || '-'} → ${currentJob.bloco_fim || '-'} (${currentJob.percent ?? 0}%)`
        + staleHint(currentJob);
      startPolling(currentJob.id);
    } else if (estado === 'PAUSED') {
      progress.hidden = false;
      progressBody.textContent = `Pausada no cursor ${currentJob.cursor_date}. Retome para continuar.`;
      stopPolling();
    } else if (estado === 'STOP_REQUESTED') {
      progress.hidden = false;
      progressBody.textContent = `Parada solicitada. O worker pausará ao fim do bloco atual (cursor ${currentJob.cursor_date}).`;
      stopPolling();
    } else if (estado === 'COMPLETED') {
      progress.hidden = false;
      progressBody.textContent = 'Concluído.';
      showResult(renderSummary(currentJob), 'success', true);
      stopPolling();
    } else if (estado === 'ERROR') {
      progress.hidden = false;
      progressBody.textContent = 'Erro.';
      showResult(`<strong>Erro na varredura.</strong><br>${escapeHtml(currentJob.erro || 'Falha desconhecida.')}`, 'error', true);
      stopPolling();
    }
  }

  function staleHint(job) {
    if (job.estado !== 'RUNNING' || !job.heartbeat) return '';
    const ts = Date.parse((job.heartbeat || '').replace(' ', 'T'));
    if (Number.isNaN(ts)) return '';
    const age = Math.round((Date.now() - ts) / 1000);
    if (age <= STALE_SECONDS) return '';
    return ` — ⚠️ sem sinal há ${Math.round(age / 60)} min (verifique se o worker está agendado/rodando no servidor)`;
  }

  function renderLogs(logs) {
    if (!logs.length) {
      logPanel.hidden = true;
      return;
    }
    logPanel.hidden = false;
    logBody.innerHTML = logs.map((l) => {
      const linhas = lineCount(l.resumo);
      const cls = l.status === 'ERRO' ? ' class="log-erro"' : '';
      return `<tr${cls}>`
        + `<td>${escapeHtml(`${l.bloco_inicio || ''} → ${l.bloco_fim || ''}`)}</td>`
        + `<td>${escapeHtml(l.tabela || '')}</td>`
        + `<td>${escapeHtml(l.status || '')}</td>`
        + `<td>${escapeHtml(String(linhas))}</td>`
        + `<td>${l.duracao_s != null ? escapeHtml(String(l.duracao_s)) : '-'}</td>`
        + `<td>${escapeHtml(l.erro || '')}</td>`
        + '</tr>';
    }).join('');
  }

  function lineCount(resumo) {
    if (!resumo || typeof resumo !== 'object') return 0;
    let total = 0;
    for (const v of Object.values(resumo)) {
      if (typeof v === 'number') total += v;
    }
    return total;
  }

  function updateButtons() {
    const estado = currentJob ? currentJob.estado : 'NONE';
    const running = estado === 'RUNNING' || estado === 'QUEUED';
    // Proteção contra disparo duplicado: bloqueia "Iniciar" enquanto há varredura ativa.
    runButton.disabled = running;
    stopButton.disabled = !running;
    resumeButton.disabled = !(estado === 'PAUSED' || estado === 'ERROR' || estado === 'STOP_REQUESTED');
    const hasErrors = currentJob && (currentJob.logs || []).some((l) => l.status === 'ERRO');
    retryButton.disabled = running || !hasErrors;
  }

  function renderSummary(job) {
    const s = job.resumo || {};
    const labels = { sales: 'Sales', creditos: 'Créditos', refunds: 'Refunds', bilhetes: 'Bilhetes' };
    let html = `<strong>Varredura concluída.</strong><p class="muted">Período ${escapeHtml(job.initial_date)} a ${escapeHtml(job.final_date)} (até ${escapeHtml(job.cap_date)}).</p>`;
    for (const [key, val] of Object.entries(s)) {
      if (typeof val !== 'object' || val === null) continue;
      html += `<div class="panel" style="margin-top:12px"><strong>${escapeHtml(labels[key] || key)}</strong><ul style="margin:8px 0 0;padding-left:18px">`;
      for (const [k, v] of Object.entries(val)) {
        if (k === 'bi_insight') continue;
        html += `<li>${escapeHtml(k)}: <strong>${escapeHtml(String(v))}</strong></li>`;
      }
      html += '</ul></div>';
    }
    return html;
  }

  function startPolling(id) {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
      try {
        const data = await api.post(endpoint, { action: 'status', id });
        if (data.success) renderJob(data.job);
      } catch (e) { /* mantém polling */ }
    }, 5000);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  async function action(name, payload, button, busyText) {
    const original = button.textContent;
    button.disabled = true;
    button.textContent = busyText;
    try {
      const data = await api.post(endpoint, { action: name, ...payload });
      if (!data.success) throw new Error(data.message || 'Não foi possível concluir.');
      renderJob(data.job);
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
    } finally {
      button.textContent = original;
      updateButtons();
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const problema = validateInputs();
    if (problema) {
      showResult(problema, 'error');
      return;
    }
    runButton.disabled = true;
    runButton.textContent = 'Iniciando…';
    try {
      const data = await api.post(endpoint, {
        action: 'start',
        initialDate: window.DateField.iso(initial),
        finalDate: window.DateField.iso(final),
        blockDays: Number(blockDays.value) || 5,
      });
      if (!data.success) throw new Error(data.message || 'Não foi possível iniciar.');
      renderJob(data.job);
      showResult('Varredura agendada. O worker processará em segundo plano.', 'success');
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
    } finally {
      runButton.textContent = 'Iniciar varredura';
      updateButtons();
    }
  });

  stopButton.addEventListener('click', () => action('stop', {}, stopButton, 'Interrompendo…'));
  resumeButton.addEventListener('click', () => action('resume', {}, resumeButton, 'Retomando…'));
  retryButton.addEventListener('click', () => action('retryFailed', {}, retryButton, 'Agendando…'));

  (async () => {
    try {
      const data = await api.post(endpoint, { action: 'status' });
      if (data.success) renderJob(data.job);
    } catch (e) { /* ignore */ }
  })();
})();
