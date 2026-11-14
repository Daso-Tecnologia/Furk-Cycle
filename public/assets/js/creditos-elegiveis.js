const form = document.querySelector('#sync-form');
const button = document.querySelector('#sync-button');
const result = document.querySelector('#result');

const today = new Date().toISOString().slice(0, 10);
document.querySelector('#final-issue-date').value = today;
document.querySelector('#initial-issue-date').value = `${today.slice(0, 8)}01`;

const token = api.getToken();
if (!token) {
  showResult('Faça login antes de sincronizar os créditos.', 'error');
  if (button) button.disabled = true;
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const jwt = api.getToken();

    if (!jwt) {
      showResult('Faça login antes de sincronizar os créditos.', 'error');
      return;
    }

    if (button) { button.disabled = true; button.textContent = 'Sincronizando…'; }
    showResult('A consulta está em andamento. Dependendo do período, ela pode levar alguns minutos.', '');

    try {
      const body = {
        initialIssueDate: document.querySelector('#initial-issue-date').value,
        finalIssueDate: document.querySelector('#final-issue-date').value,
      };
      const response = await api.post('/api/cycle/credits/sync.php', body);

      if (!response.success) {
        throw new Error(response.message || 'A sincronização não foi concluída.');
      }

      const summary = response.summary;
      renderSummary(summary);

      const summaryHtml = `
        <div class="fade-in">
          <strong>Concluído!</strong> ${summary.total} créditos processados em ${summary.pages} página(s).
          <div id="cardSummary">
            <div class="summary-item"><div class="label">Total</div><div class="value">${summary.total}</div></div>
            <div class="summary-item"><div class="label">Novos</div><div class="value">${summary.inserted}</div></div>
            <div class="summary-item"><div class="label">Atualizados</div><div class="value">${summary.updated}</div></div>
            <div class="summary-item"><div class="label">Sem alteração</div><div class="value">${summary.unchanged}</div></div>
          </div>
        </div>
      `;
      showResult(summaryHtml, 'success', true);
    } catch (error) {
      showResult(error.message || 'Não foi possível sincronizar os créditos.', 'error');
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Sincronizar créditos'; }
    }
  });
}

function renderSummary(summary) {
  const setValue = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value;
  };
  setValue('#cardTotal', summary.total ?? '-');
  setValue('#cardNovos', summary.inserted ?? '-');
  setValue('#cardAtualizados', summary.updated ?? '-');
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