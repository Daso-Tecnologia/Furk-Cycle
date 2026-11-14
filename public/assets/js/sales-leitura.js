const form = document.querySelector('#sales-sync-form');
const button = document.querySelector('#sales-sync-button');
const result = document.querySelector('#sales-result');

// Período padrão curto (ontem e os 2 dias anteriores): a leitura manual cabe numa requisição. A Furk está em D-1.
function isoDia(deslocamento) {
  const d = new Date();
  d.setDate(d.getDate() + deslocamento);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
document.querySelector('#final-issue-date').value = isoDia(-1);
document.querySelector('#initial-issue-date').value = isoDia(-3);

const token = api.getToken();
if (!token) {
  showResult('Faça login antes de sincronizar as vendas.', 'error');
  if (button) button.disabled = true;
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const jwt = api.getToken();

    if (!jwt) {
      showResult('Faça login antes de sincronizar as vendas.', 'error');
      return;
    }

    if (button) { button.disabled = true; button.textContent = 'Sincronizando…'; }
    showResult('Lendo as vendas na Furk… Esta leitura dura no máximo ~45 segundos; se o período for maior, ela para sozinha e informa até onde foi.', '');

    try {
      const body = {
        initialIssueDate: document.querySelector('#initial-issue-date').value,
        finalIssueDate: document.querySelector('#final-issue-date').value,
      };
      const response = await api.post('/api/cycle/sales/sync.php', body);

      if (!response.success) {
        throw new Error(response.message || 'A sincronização não foi concluída.');
      }

      const summary = response.summary;
      renderSummary(summary);

      let aviso = '';
      if (summary.parcial) {
        const motivo = summary.motivo === 'interrompido' ? 'a pedido de um administrador' : 'ao atingir o tempo máximo da leitura manual';
        const ate = summary.ate_data ? `${summary.ate_data.slice(8, 10)}/${summary.ate_data.slice(5, 7)}` : '';
        aviso = `<p><strong>Leitura parcial:</strong> parou ${motivo}. O que foi lido já está gravado${ate ? ` (emissões até ${ate})` : ''}. `
          + `${summary.ate_data ? `Para completar, o campo "Data inicial" foi ajustado para ${ate}: clique em Sincronizar de novo. ` : ''}`
          + 'Para períodos longos, use a Sincronização Consolidada.</p>';
        if (summary.ate_data) document.querySelector('#initial-issue-date').value = summary.ate_data;
      }

      const summaryHtml = `
        <div class="fade-in">
          ${aviso}
          <strong>${summary.parcial ? 'Parcial.' : 'Concluído!'}</strong> ${summary.total} vendas processadas em ${summary.pages} página(s).
          <div id="sales-cardSummary">
            <div class="summary-item"><div class="label">Total</div><div class="value">${summary.total}</div></div>
            <div class="summary-item"><div class="label">Inseridos</div><div class="value">${summary.inserted}</div></div>
            <div class="summary-item"><div class="label">Atualizados</div><div class="value">${summary.updated}</div></div>
            <div class="summary-item"><div class="label">Sem alteração</div><div class="value">${summary.unchanged}</div></div>
          </div>
        </div>
      `;
      showResult(summaryHtml, 'success', true);
    } catch (error) {
      showResult(error.message || 'Não foi possível sincronizar as vendas.', 'error');
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Sincronizar vendas'; }
    }
  });
}

function renderSummary(summary) {
  const setValue = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value;
  };
  setValue('#cardTotal', summary.total ?? '-');
  setValue('#cardInseridos', summary.inserted ?? '-');
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
