const form = document.querySelector('#sync-form');
const button = document.querySelector('#sync-button');
const result = document.querySelector('#result');

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
  showResult('Faça login antes de importar os bilhetes.', 'error');
  if (button) button.disabled = true;
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const jwt = api.getToken();

    if (!jwt) {
      showResult('Faça login antes de importar os bilhetes.', 'error');
      return;
    }

    if (button) { button.disabled = true; button.textContent = 'Importando…'; }
    showResult('Lendo os bilhetes na Furk… Esta leitura dura no máximo ~45 segundos; se o período for maior, ela para sozinha ao fim de uma parte e informa até onde foi.', '');

    try {
      const body = {
        initialIssueDate: document.querySelector('#initial-issue-date').value,
        finalIssueDate: document.querySelector('#final-issue-date').value,
        daysPerPart: parseInt(document.querySelector('#days-per-part').value, 10),
      };
      const response = await api.post('/api/cycle/dynamic-status/import.php', body);

      if (!response.success) {
        throw new Error(response.message || 'A importação não foi concluída.');
      }

      const summary = response.summary;
      renderSummary(summary);

      let aviso = '';
      if (summary.parcial) {
        const motivo = summary.motivo === 'interrompido' ? 'a pedido de um administrador' : 'ao atingir o tempo máximo da leitura manual';
        const ate = summary.ate_data ? `${summary.ate_data.slice(8, 10)}/${summary.ate_data.slice(5, 7)}` : '';
        // A parte concluída termina no dia "ate_data": o próximo começa no dia seguinte.
        const proximo = summary.ate_data ? new Date(`${summary.ate_data}T12:00:00`) : null;
        if (proximo) proximo.setDate(proximo.getDate() + 1);
        const proximoIso = proximo ? proximo.toISOString().slice(0, 10) : null;
        const proximoTxt = proximoIso ? `${proximoIso.slice(8, 10)}/${proximoIso.slice(5, 7)}` : '';
        aviso = `<p><strong>Leitura parcial:</strong> parou ${motivo}. Os dias até ${ate} já estão gravados. `
          + `${proximoIso ? `Para completar, o campo "Data inicial" foi ajustado para ${proximoTxt}: clique em Importar de novo. ` : ''}`
          + 'Para períodos longos, use a Sincronização Consolidada.</p>';
        if (proximoIso) document.querySelector('#initial-issue-date').value = proximoIso;
      }

      const summaryHtml = `
        <div class="fade-in">
          ${aviso}
          <strong>${summary.parcial ? 'Parcial.' : 'Concluído!'}</strong> ${summary.tickets} bilhetes e ${summary.segments} segmentos gravados em ${summary.parts} parte(s) (${summary.pages} página(s)).
          <div id="cardSummary">
            <div class="summary-item"><div class="label">Bilhetes</div><div class="value">${summary.tickets}</div></div>
            <div class="summary-item"><div class="label">Esperados</div><div class="value">${summary.expected}</div></div>
            <div class="summary-item"><div class="label">Segmentos</div><div class="value">${summary.segments}</div></div>
            <div class="summary-item"><div class="label">Removidos</div><div class="value">${summary.deleted_tickets + summary.deleted_segments}</div></div>
          </div>
        </div>
      `;
      showResult(summaryHtml, 'success', true);
    } catch (error) {
      showResult(error.message || 'Não foi possível importar os bilhetes.', 'error');
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Importar bilhetes'; }
    }
  });
}

function renderSummary(summary) {
  const setValue = (id, value) => {
    const el = document.querySelector(id);
    if (el) el.textContent = value;
  };
  setValue('#cardTickets', summary.tickets ?? '-');
  setValue('#cardSegments', summary.segments ?? '-');
  setValue('#cardDeleted', (summary.deleted_tickets ?? 0) + (summary.deleted_segments ?? 0));
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