const form = document.querySelector('#lookup-form');
const button = document.querySelector('#lookup-button');
const result = document.querySelector('#result');

const today = new Date().toISOString().slice(0, 10);
document.querySelector('#final-issue-date').value = today;
document.querySelector('#initial-issue-date').value = `${today.slice(0, 4)}-01-01`;

const token = api.getToken();
if (!token) {
  showResult('Faça login antes de consultar um localizador.', 'error');
  if (button) button.disabled = true;
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const jwt = api.getToken();

    if (!jwt) {
      showResult('Faça login antes de consultar um localizador.', 'error');
      return;
    }

    const locator = document.querySelector('#locator-value').value.trim();

    if (!locator) {
      showResult('Informe o localizador a consultar.', 'error');
      return;
    }

    if (button) { button.disabled = true; button.textContent = 'Consultando…'; }
    showResult('A consulta está em andamento. Dependendo do período, ela pode levar alguns minutos.', '');

    try {
      const body = {
        initialIssueDate: document.querySelector('#initial-issue-date').value,
        finalIssueDate: document.querySelector('#final-issue-date').value,
        locator,
      };
      const response = await api.post('/api/cycle/locator/query.php', body);

      if (!response.success) {
        throw new Error(response.message || 'A consulta não foi concluída.');
      }

      renderResult(response.result);
    } catch (error) {
      showResult(error.message || 'Não foi possível consultar o localizador.', 'error');
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Consultar'; }
    }
  });
}

function renderResult(resultData) {
  const status = resultData.status || '';
  const message = resultData.message || '';
  const matches = Array.isArray(resultData.matches) ? resultData.matches : [];

  if (status === 'ENCONTRADO' && matches.length > 0) {
    const rows = matches.map((match) => `
      <tr>
        <td>${escapeHtml(match.searched || '-')}</td>
        <td class="match">${escapeHtml(match.matched || '-')}</td>
        <td>${escapeHtml(match.airline_code || '-')}</td>
        <td>${escapeHtml(match.locator_code || '-')}</td>
        <td>${escapeHtml(match.ndc_order_id || '-')}</td>
        <td>${escapeHtml(match.transaction_type || '-')}</td>
        <td>${escapeHtml(match.issue_date || '-')}</td>
        <td>${escapeHtml(match.status || '-')}</td>
        <td>${escapeHtml(match.updated_at || '-')}</td>
      </tr>
    `).join('');

    const html = `
      <div class="fade-in">
        <div class="lookup-status">
          <span class="badge badge-success">ENCONTRADO</span>
          <span>${escapeHtml(message)}</span>
        </div>
        <table class="lookup-table">
          <thead>
            <tr>
              <th>Chave pesquisada</th>
              <th>Chave encontrada (match)</th>
              <th>Companhia</th>
              <th>Locator</th>
              <th>NDC Order</th>
              <th>Trans.</th>
              <th>Emissão</th>
              <th>Status</th>
              <th>Atualizado</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
        <p class="lookup-scanned">${resultData.total_elements ?? 0} registro(s) no período · ${resultData.records_scanned ?? 0} examinados · ${resultData.total_pages ?? 0} página(s).</p>
      </div>
    `;
    showResult(html, 'found', true);
    return;
  }

  const config = {
    NAO_ENCONTRADO: ['badge-error', 'not-found'],
    INCONCLUSIVO: ['badge-warning', 'inconclusive'],
  }[status] || ['badge-warning', 'inconclusive'];

  const html = `
    <div class="lookup-status">
      <span class="badge ${config[0]}">${escapeHtml(status.replace('_', ' '))}</span>
      <span>${escapeHtml(message)}</span>
    </div>
    <p class="lookup-scanned">${resultData.total_elements ?? 0} registro(s) no período · ${resultData.records_scanned ?? 0} examinados · ${resultData.total_pages ?? 0} página(s).</p>
  `;
  showResult(html, config[1], true);
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
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}