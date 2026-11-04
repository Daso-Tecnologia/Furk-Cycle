(() => {
  const result = document.querySelector('#result');
  const startButton = document.querySelector('#start-button');
  const stopButton = document.querySelector('#stop-button');
  const saveButton = document.querySelector('#save-button');
  const form = document.querySelector('#config-form');

  const setValue = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value ?? '-';
  };

  function renderJob(job) {
    if (!job) return;
    const config = job.config || {};
    const estado = job.estado || 'IDLE';

    setValue('#cardEstado', estado);
    setValue('#cardHeartbeat', job.heartbeat || '-');
    setValue('#cardProxima', job.proxima_execucao || '-');

    if (config.data_base) document.querySelector('#data-base').value = config.data_base;
    if (config.frequencia_min) document.querySelector('#frequencia-min').value = config.frequencia_min;
    if (config.dias_recuo) document.querySelector('#dias-recuo').value = String(config.dias_recuo);
    if (config.dias_final) document.querySelector('#dias-final').value = String(config.dias_final);

    const isRunning = estado === 'RUNNING';
    startButton.disabled = isRunning;
    stopButton.disabled = !isRunning;
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

  async function loadStatus() {
    try {
      const token = api.getToken();
      if (!token) {
        showResult('Faça login antes de gerenciar a rotina.', 'error');
        return;
      }
      const response = await api.get('/api/cycle/dynamic-status/job.php');
      if (!response.success) throw new Error(response.message || 'Não foi possível consultar a rotina.');
      renderJob(response.job);
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
    return api.post('/api/cycle/dynamic-status/job.php', { action, ...body });
  }

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      saveButton.disabled = true;
      saveButton.textContent = 'Salvando…';
      try {
        const response = await sendAction('configure', {
          dataBase: document.querySelector('#data-base').value,
          frequenciaMin: parseInt(document.querySelector('#frequencia-min').value, 10),
          diasRecuo: parseInt(document.querySelector('#dias-recuo').value, 10),
          diasFinal: parseInt(document.querySelector('#dias-final').value, 10),
        });
        if (!response.success) throw new Error(response.message || 'Não foi possível salvar a configuração.');
        renderJob(response.job);
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
    startButton.disabled = true;
    try {
      const response = await sendAction('start');
      if (!response.success) throw new Error(response.message || 'Não foi possível iniciar a rotina.');
      renderJob(response.job);
      showResult('Rotina iniciada. O worker deve estar agendado para executar os ciclos.', 'success');
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

  loadStatus();
  setInterval(loadStatus, 30000);
})();