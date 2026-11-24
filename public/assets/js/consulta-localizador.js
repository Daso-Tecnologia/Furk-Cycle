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
    showResult('Consultando o banco do Furk Cycle e, se necessário, a Furk. Aguarde…', '');

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

function fmtDate(value) {
  // 2025-10-01 ou 2025-10-01 08:30:00 / 2025-10-01T08:30:00 -> 01/10/2025 [08:30]
  const m = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return value ? String(value) : '-';
  return `${m[3]}/${m[2]}/${m[1]}${m[4] ? ` ${m[4]}:${m[5]}` : ''}`;
}

function renderSegments(segments) {
  if (!Array.isArray(segments) || segments.length === 0) {
    return '<p class="lookup-scanned">Este bilhete não tem trechos registrados.</p>';
  }
  const rows = segments.map((s) => `
    <tr>
      <td>${escapeHtml(s.order ?? '-')}</td>
      <td>${escapeHtml(s.origin || '-')}</td>
      <td>${escapeHtml(s.destination || '-')}</td>
      <td>${escapeHtml(s.airline_code || '-')}</td>
      <td>${escapeHtml(s.flight_number || '-')}</td>
      <td>${escapeHtml(fmtDate(s.departure))}</td>
      <td>${escapeHtml(fmtDate(s.arrival))}</td>
      <td>${escapeHtml(s.flight_status || '-')}</td>
      <td>${escapeHtml(s.fare_class || '-')}</td>
    </tr>`).join('');
  return `
    <p class="lookup-scanned"><strong>Trechos do bilhete</strong></p>
    <table class="lookup-table">
      <thead><tr><th>Trecho</th><th>Origem</th><th>Destino</th><th>Cia.</th><th>Voo</th><th>Partida</th><th>Chegada</th><th>Status do voo</th><th>Classe</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

function renderResult(resultData) {
  const status = resultData.status || '';
  const message = resultData.message || '';
  const matches = Array.isArray(resultData.matches) ? resultData.matches : [];
  const fromLocal = resultData.source === 'BANCO_LOCAL';
  const sourceLabel = fromLocal ? 'Banco local' : 'API da Furk';

  if (status === 'ENCONTRADO' && matches.length > 0) {
    const blocks = matches.map((match) => `
      <table class="lookup-table">
        <thead>
          <tr>
            <th>Companhia</th>
            <th>Bilhete</th>
            <th>Localizador</th>
            <th>Pedido NDC</th>
            <th>Tipo</th>
            <th>Emissão</th>
            <th>Status</th>
            <th>Atualizado em</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>${escapeHtml(match.airline_code || '-')}</td>
            <td class="match">${escapeHtml(match.ticket_number || match.matched || '-')}</td>
            <td>${escapeHtml(match.locator_code || '-')}</td>
            <td>${escapeHtml(match.ndc_order_id || '-')}</td>
            <td>${escapeHtml(match.transaction_type || '-')}</td>
            <td>${escapeHtml(fmtDate(match.issue_date))}${match.in_period === false ? ' (fora do período informado)' : ''}</td>
            <td>${escapeHtml(match.status || '-')}</td>
            <td>${escapeHtml(fmtDate(match.updated_at))}</td>
          </tr>
        </tbody>
      </table>
      ${renderSegments(match.segments)}
    `).join('');

    const footer = fromLocal
      ? `<p class="lookup-scanned">Fonte: banco local do Furk Cycle (dados já importados da Furk). ${matches.length} registro(s) para este localizador.</p>`
      : `<p class="lookup-scanned">Fonte: API da Furk · ${resultData.total_elements ?? 0} registro(s) no período · ${resultData.records_scanned ?? 0} examinados · ${resultData.total_pages ?? 0} página(s).</p>`;

    const html = `
      <div class="fade-in">
        <div class="lookup-status">
          <span class="badge badge-success">ENCONTRADO</span>
          <span class="badge badge-warning">${escapeHtml(sourceLabel)}</span>
          <span>${escapeHtml(message)}</span>
        </div>
        ${blocks}
        ${footer}
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
    <p class="lookup-scanned">Fonte da consulta: ${escapeHtml(sourceLabel)} · ${resultData.total_elements ?? 0} registro(s) no período · ${resultData.records_scanned ?? 0} examinados · ${resultData.total_pages ?? 0} página(s).</p>
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
