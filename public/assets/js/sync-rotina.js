(() => {
  const result = document.querySelector('#result');
  const startButton = document.querySelector('#start-button');
  const stopButton = document.querySelector('#stop-button');
  const saveButton = document.querySelector('#save-button');
  const form = document.querySelector('#config-form');
  const endpoint = '/api/cycle/sync/rotina.php';

  // Evita que o polling de 30 s sobrescreva uma configuração que o usuário
  // acabou de digitar/selecionar e ainda não salvou.
  let configDirty = false;

  const setValue = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value ?? '-';
  };

  // Retorna a data de hoje no fuso local, no formato AAAA-MM-DD.
  const todayLocal = () => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  };

  // A data base é sempre a data atual e não pode ser alterada.
  const setDataBaseToday = () => {
    const input = document.querySelector('#data-base');
    if (input) input.value = todayLocal();
  };

  function renderJob(job, applyConfig = false) {
    if (!job) return;
    const config = job.config || {};
    const estado = job.estado || 'IDLE';

    setValue('#cardEstado', estado);
    setValue('#cardTabela', job.tabela_atual || '-');
    setValue('#cardJanela', job.janela_inicial && job.janela_final ? `${job.janela_inicial} → ${job.janela_final}` : '-');
    setValue('#cardHeartbeat', job.heartbeat || '-');
    setValue('#cardProxima', job.proxima_execucao || '-');

    // A data base é somente leitura e sempre reflete o dia atual do servidor.
    if (config.data_base) document.querySelector('#data-base').value = config.data_base;

    // Só aplica frequência/recuo no carregamento inicial e após salvar.
    // O polling de 30 s atualiza apenas os cards, sem apagar edições em andamento.
    if (applyConfig) {
      if (config.frequencia_horas) document.querySelector('#frequencia-horas').value = config.frequencia_horas;
      if (config.dias_recuo) document.querySelector('#dias-recuo').value = String(config.dias_recuo);
      configDirty = false;
    }

    if (job.ultimo_resumo) {
      showResult(renderSummary(job.ultimo_resumo), 'success', true);
    }

    const isRunning = estado === 'RUNNING';
    startButton.disabled = isRunning;
    stopButton.disabled = !isRunning;
  }

  function renderSummary(summary) {
    const labels = { sales: 'Sales', creditos: 'Créditos', refunds: 'Refunds', bilhetes: 'Bilhetes' };
    const fieldLabels = {
      total: 'Total', inserted: 'Inseridos', updated: 'Atualizados', pages: 'Páginas',
      tickets: 'Bilhetes', segments: 'Segmentos', deleted_tickets: 'Bilhetes excluídos',
      deleted_segments: 'Segmentos excluídos', expected: 'Esperado', parts: 'Partes',
    };

    let html = '<strong>Último ciclo concluído.</strong>';
    for (const [key, val] of Object.entries(summary)) {
      if (typeof val !== 'object' || val === null) continue;
      html += `<div class="panel" style="margin-top:12px"><strong>${escapeHtml(labels[key] || key)}</strong><ul style="margin:8px 0 0;padding-left:18px">`;
      for (const [k, v] of Object.entries(val)) {
        if (k === 'bi_insight') continue;
        html += `<li>${escapeHtml(fieldLabels[k] || k)}: <strong>${escapeHtml(String(v))}</strong></li>`;
      }
      html += '</ul></div>';
    }

    return html;
  }

  function showResult(message, type, asHtml = false) {
    if (!result) return;
    result.hidden = false;
    result.className = `panel ${type}`;
    if (asHtml) {
      result.innerHTML = message;
    } else {
      result.textContent = message;
    }
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  async function loadStatus(applyConfig = false) {
    try {
      const token = api.getToken();
      if (!token) {
        showResult('Faça login antes de gerenciar a rotina.', 'error');
        return;
      }
      const response = await api.get(endpoint);
      if (!response.success) throw new Error(response.message || 'Não foi possível consultar a rotina.');
      renderJob(response.job, applyConfig);
    } catch (error) {
      showResult(error.message || 'Não foi possível consultar a rotina.', 'error');
    }
  }

  async function sendAction(action, body = {}) {
    const jwt = api.getToken();
    if (!jwt) {
      showResult('Faça login antes de continuar.', 'error');
      return null;
    }
    return api.post(endpoint, { action, ...body });
  }

  if (form) {
    form.addEventListener('input', () => { configDirty = true; });
    form.addEventListener('change', () => { configDirty = true; });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      saveButton.disabled = true;
      saveButton.textContent = 'Salvando…';
      try {
        const response = await sendAction('configure', {
          frequenciaHoras: parseInt(document.querySelector('#frequencia-horas').value, 10),
          diasRecuo: parseInt(document.querySelector('#dias-recuo').value, 10),
        });
        if (!response.success) throw new Error(response.message || 'Não foi possível salvar a configuração.');
        renderJob(response.job, true);
        showResult('Configuração salva.', 'success');
      } catch (error) {
        showResult(error.message || 'Não foi possível salvar a configuração.', 'error');
      } finally {
        saveButton.disabled = false;
        saveButton.textContent = 'Salvar configuração';
      }
    });
  }

  startButton.addEventListener('click', async () => {
    if (configDirty) {
      showResult('Salve a configuração antes de iniciar a rotina.', 'error');
      return;
    }
    startButton.disabled = true;
    try {
      const response = await sendAction('start');
      if (!response.success) throw new Error(response.message || 'Não foi possível iniciar a rotina.');
      renderJob(response.job);
      showResult('Rotina iniciada. O worker deve estar agendado para executar os ciclos das 4 tabelas.', 'success');
    } catch (error) {
      showResult(error.message || 'Não foi possível iniciar a rotina.', 'error');
      startButton.disabled = false;
    }
  });

  stopButton.addEventListener('click', async () => {
    stopButton.disabled = true;
    try {
      const response = await sendAction('stop');
      if (!response.success) throw new Error(response.message || 'Não foi possível interromper a rotina.');
      renderJob(response.job);
      showResult('Rotina interrompida após o ciclo atual.', 'success');
    } catch (error) {
      showResult(error.message || 'Não foi possível interromper a rotina.', 'error');
      stopButton.disabled = false;
    }
  });

  setDataBaseToday();
  loadStatus(true);
  setInterval(loadStatus, 30000);
})();
