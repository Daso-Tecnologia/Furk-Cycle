(() => {
  const statusBody = document.getElementById('statusBody');
  const eventsBody = document.getElementById('eventsBody');
  const searchInput = document.getElementById('search');

  const fmt = (value) => {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d.getTime()) ? String(value) : d.toLocaleString('pt-BR');
  };

  async function loadStatus() {
    statusBody.innerHTML = '<p class="muted">Carregando…</p>';
    try {
      const data = await api.post('/api/cycle/webhook/status.php');
      if (!data.success) {
        throw new Error(data.message || 'Falha ao consultar status.');
      }
      const badge = data.registered
        ? '<span class="badge-registered">REGISTERED</span>'
        : '<span class="badge-unregistered">NOT_REGISTERED</span>';
      statusBody.innerHTML = `
        <dl class="status-row">
          <dt>Status</dt><dd>${badge}</dd>
          <dt>URL</dt><dd>${escapeHtml(data.registeredUrl || '—')}</dd>
          <dt>Status original do ticket</dt><dd>${escapeHtml(data.ticketOriginalStatus || '—')}</dd>
        </dl>`;
    } catch (err) {
      statusBody.innerHTML = `<p class="error-msg">${err.message || 'Erro ao consultar status.'}</p>`;
    }
  }

  async function loadEvents() {
    eventsBody.innerHTML = '<tr><td colspan="7" class="muted">Carregando…</td></tr>';
    try {
      const data = await api.post('/api/cycle/webhook/events.php', {
        page: 1,
        size: 50,
        search: searchInput.value.trim(),
      });
      if (!data.success) {
        throw new Error(data.message || 'Falha ao listar eventos.');
      }
      renderEvents(data.rows || []);
    } catch (err) {
      eventsBody.innerHTML = `<tr><td colspan="7" class="error-msg">${escapeHtml(err.message || 'Erro ao carregar eventos.')}</td></tr>`;
    }
  }

  function renderEvents(rows) {
    if (!rows.length) {
      eventsBody.innerHTML = '<tr><td colspan="8" class="muted">Nenhum evento de webhook registrado.</td></tr>';
      return;
    }
    eventsBody.innerHTML = rows.map((r) => `
      <tr>
        <td>${escapeHtml(r.nota || '—')}</td>
        <td>${escapeHtml(r.furk_rebooking_id || '—')}</td>
        <td>${escapeHtml(r.furk_status || '—')}</td>
        <td>${escapeHtml(r.last_webhook_status || '—')}</td>
        <td>${escapeHtml(r.processing_status || '—')}</td>
        <td>${escapeHtml(fmt(r.last_webhook_datetime))}</td>
        <td>${escapeHtml(fmt(r.last_checked_at))}</td>
        <td>${escapeHtml(fmt(r.next_check_at))}</td>
      </tr>`).join('');
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function filenameFromDisposition(disposition) {
    const match = disposition.match(/filename="?([^";]+)"?/);
    return match ? match[1] : 'webhook.xlsx';
  }

  async function exportXlsx() {
    try {
      const data = await api.post('/api/cycle/webhook/export.php', {
        search: searchInput.value.trim(),
      });
      if (data._download) {
        const blob = await data._res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filenameFromDisposition(data._res.headers.get('content-disposition') || 'webhook.xlsx');
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } else {
        eventsBody.innerHTML = `<tr><td colspan="8" class="error-msg">${escapeHtml(data.message || 'Não foi possível exportar.')}</td></tr>`;
      }
    } catch (err) {
      eventsBody.innerHTML = `<tr><td colspan="8" class="error-msg">${escapeHtml(err.message || 'Erro ao exportar.')}</td></tr>`;
    }
  }

  document.querySelector('[data-action="refresh-status"]').addEventListener('click', loadStatus);
  document.querySelector('[data-action="refresh"]').addEventListener('click', loadEvents);
  document.querySelector('[data-action="search"]').addEventListener('click', loadEvents);
  document.querySelector('[data-action="export"]').addEventListener('click', exportXlsx);
  searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') loadEvents(); });

  loadStatus();
  loadEvents();
})();
