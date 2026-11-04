(() => {
  const state = {
    page: 1,
    size: 50,
    search: '',
    estado: 'todos',
    total: 0,
    rows: [],
    selected: new Set(),
    controlNota: '',
  };

  const $ = (sel) => document.querySelector(sel);
  const body = $('#rb-body');
  const pagination = $('#rb-pagination');
  const count = $('#rb-count');
  const result = $('#rb-result');
  const modal = $('#control-modal');
  const controlBody = $('#control-body');
  const controlPagination = $('#control-pagination');
  const controlSearchBar = $('#control-search-bar');
  const controlToggleSearch = $('#control-toggle-search');
  const gateModal = $('#gate-modal');
  const gateBody = $('#gate-body');
  const gatePagination = $('#gate-pagination');
  const gateSearchBar = $('#gate-search-bar');
  const gateToggleSearch = $('#gate-toggle-search');

  async function loadGrid() {
    const enviados = state.estado === 'enviado';
    $('#rb-preview').hidden = enviados;
    $('#rb-send').hidden = enviados;
    body.innerHTML = '<tr><td colspan="13" class="muted">Carregando registros…</td></tr>';
    try {
      const data = await api.post('/api/cycle/rebooking/list.php', {
        page: state.page,
        size: state.size,
        search: state.search,
        estado: state.estado,
      });
      if (!data.success) throw new Error(data.message || 'Não foi possível consultar o Gate.');
      state.total = data.total;
      state.rows = data.rows;
      renderRegras(data.regras);
      renderRows();
      renderPagination();
    } catch (error) {
      body.innerHTML = `<tr><td colspan="13" class="muted error">${escapeHtml(error.message)}</td></tr>`;
      count.textContent = '0 registros';
      pagination.innerHTML = '';
    }
  }

  // Mostra a janela ativa (editável em Configurações); some quando o GATE é lido pela view.
  function renderRegras(regras) {
    const el = document.getElementById('rb-regras');
    if (!el) return;
    el.hidden = !regras;
    if (regras) {
      el.textContent = `Notas dos últimos ${regras.diasJanela} dias · cliente ${regras.clientes.join(', ')} · fornecedor ${regras.fornecedores.join(', ')} (regras em Configurações).`;
    }
  }

  function renderRows() {
    if (state.rows.length === 0) {
      body.innerHTML = `<tr><td colspan="13" class="muted">${emptyMessage()}</td></tr>`;
      count.textContent = `${state.total} registros`;
      return;
    }

    const html = state.rows.map((row) => {
      const control = row.control;
      const sent = control !== null;
      const rowClass = sent ? 'rb-row-sent' : 'rb-row-pending';
      const dotClass = sent ? 'sent' : 'pending';
      const checked = state.selected.has(row.nota) ? 'checked' : '';
      const processing = control ? String(control.processing_status ?? '') : '';
      const furkStatus = control ? String(control.furk_status ?? '') : '';
      const statusCell = control
        ? `<span class="rb-furk-status">${escapeHtml(furkStatus || '—')}</span> <span class="rb-control-badge ${escapeHtml(processing)}">${escapeHtml(processing || '—')}</span>`
        : '<span class="muted">—</span>';
      const sentAt = control ? escapeHtml(control.furk_request_datetime ?? control.created_at ?? '') : '';
      const furkId = control ? String(control.furk_rebooking_id ?? '') : '';
      return `
        <tr class="${rowClass}" data-nota="${escapeHtml(row.nota)}">
          <td class="col-check"><input type="checkbox" class="rb-row-check" value="${escapeHtml(row.nota)}" ${checked} aria-label="Selecionar nota ${escapeHtml(row.nota)}"></td>
          <td>${escapeHtml(row.nota)}</td>
          <td>${escapeHtml(row.localizador)}</td>
          <td>${escapeHtml(row.tkt_num)}</td>
          <td>${escapeHtml(row.nom_pax)}</td>
          <td>${escapeHtml(row.cod_for)}</td>
          <td>${escapeHtml(row.data_nota)}</td>
          <td>${escapeHtml(row.trecho)}</td>
          <td>${formatNumber(row.total)}</td>
          <td><span class="rb-status-dot ${dotClass}"></span>${sent ? 'Sim' : 'Não'}</td>
          <td>${statusCell}</td>
          <td>${sentAt || '—'}</td>
          <td class="rb-cell-id" title="${escapeHtml(furkId)}">${furkId ? escapeHtml(furkId.slice(0, 8)) : '—'}</td>
        </tr>`;
    }).join('');

    body.innerHTML = html;
    count.textContent = `${state.total} registros`;

    body.querySelectorAll('.rb-row-check').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const nota = checkbox.value;
        if (checkbox.checked) state.selected.add(nota);
        else state.selected.delete(nota);
      });
    });
  }

  function renderPagination() {
    const totalPages = Math.max(1, Math.ceil(state.total / state.size));
    pagination.innerHTML = '';

    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Página ${state.page} de ${totalPages} · ${state.total} registros`;
    pagination.appendChild(info);

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.textContent = '‹';
    prev.disabled = state.page <= 1;
    prev.addEventListener('click', () => { if (state.page > 1) { state.page--; loadGrid(); } });
    pagination.appendChild(prev);

    const start = Math.max(1, state.page - 3);
    const end = Math.min(totalPages, state.page + 3);
    for (let i = start; i <= end; i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(i);
      if (i === state.page) btn.className = 'active';
      btn.addEventListener('click', () => { state.page = i; loadGrid(); });
      pagination.appendChild(btn);
    }

    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = '›';
    next.disabled = state.page >= totalPages;
    next.addEventListener('click', () => { if (state.page < totalPages) { state.page++; loadGrid(); } });
    pagination.appendChild(next);
  }

  function applyFilters() {
    state.search = $('#rb-search').value.trim();
    state.estado = $('#rb-estado').value;
    state.size = parseInt($('#rb-size').value, 10) || 50;
    state.page = 1;
    state.selected.clear();
    $('#rb-select-page').checked = false;
    loadGrid();
  }

  function selectedNotas() {
    return Array.from(state.selected);
  }

  function requireSelection() {
    const notas = selectedNotas();
    if (notas.length === 0) {
      showResult('Selecione pelo menos uma nota para processar.', 'error');
      return null;
    }
    return notas;
  }

  function showResult(message, type) {
    result.hidden = false;
    result.className = `panel ${type === 'error' ? 'error' : 'success'}`;
    result.innerHTML = message;
  }

  function confirmSendDialog(text) {
    return new Promise((resolve) => {
      const modal = $('#confirm-modal');
      const ok = $('#confirm-modal-ok');
      const cancel = $('#confirm-modal-cancel');
      const close = $('#confirm-modal-close');
      const textEl = $('#confirm-modal-text');
      const input = $('#confirm-modal-input');

      const clean = () => {
        ok.onclick = null;
        cancel.onclick = null;
        close.onclick = null;
        modal.hidden = true;
      };

      textEl.textContent = text;
      input.hidden = true;

      ok.onclick = () => { clean(); resolve(true); };
      cancel.onclick = () => { clean(); resolve(false); };
      close.onclick = () => { clean(); resolve(false); };

      modal.hidden = false;
    });
  }

  async function runSend(send) {
    const notas = requireSelection();
    if (!notas) return;

    if (send) {
      const confirmed = await confirmSendDialog(
        `Enviar ${notas.length} nota(s) à Furk? Esta ação cria Rebooking em produção. Deseja continuar?`,
      );
      if (!confirmed) {
        showResult('Envio cancelado.', 'error');
        return;
      }
    }

    const button = send ? $('#rb-send') : $('#rb-preview');
    const originalText = button ? button.textContent : '';
    const busyLabel = send ? 'Enviando…' : 'Processando prévia…';

    if (button) {
      button.disabled = true;
      button.textContent = busyLabel;
    }
    showResult('Aguarde, processando…', 'success');

    try {
      // Cada nota faz várias consultas ao GATE e um POST à Furk; uma única requisição
      // com muitas notas estoura o timeout do servidor web (60 s). Por isso a seleção é
      // processada em partes sequenciais, e o resultado é somado.
      const chunkSize = send ? 5 : 10;
      const chunks = [];
      for (let i = 0; i < notas.length; i += chunkSize) {
        chunks.push(notas.slice(i, i + chunkSize));
      }

      const s = { read: 0, sent: 0, rejected: 0, skippedAlreadyControlled: 0, previewed: 0, dataError: 0, networkError: 0 };
      const rows = [];
      let success = true;
      let failure = null;

      for (let i = 0; i < chunks.length; i += 1) {
        if (chunks.length > 1) {
          showResult(`Aguarde, processando parte ${i + 1} de ${chunks.length}…`, 'success');
        }
        try {
          const part = await api.post('/api/cycle/rebooking/send.php', {
            notas: chunks[i],
            confirmation: send ? 'ENVIAR REBOOKING' : '',
          });
          Object.keys(s).forEach((key) => { s[key] += Number(part.summary[key] || 0); });
          rows.push(...part.results);
          if (!part.success) success = false;
        } catch (partError) {
          failure = { message: partError.message, failedPart: chunks[i], notProcessed: chunks.slice(i + 1).flat() };
          break;
        }
      }

      let html = failure
        ? '<strong>Processamento interrompido.</strong> '
        : `<strong>${send ? 'Envio' : 'Prévia'} completado.</strong> `;
      html += `Lidas: ${s.read} · Enviadas: ${s.sent} · Rejeitadas: ${s.rejected} · Já controladas: ${s.skippedAlreadyControlled} · Previstas: ${s.previewed} · Erros: ${s.dataError}`;
      if (failure) {
        html += `<br>${escapeHtml(failure.message)}`;
        html += `<br><strong>Parte que falhou</strong> (pode ter sido processada no servidor — confira na tabela de control antes de reenviar): ${escapeHtml(failure.failedPart.join(', '))}`;
        if (failure.notProcessed.length > 0) {
          html += `<br><strong>Não processadas:</strong> ${escapeHtml(failure.notProcessed.join(', '))}`;
        }
      }
      html += '<table class="table"><thead><tr><th>Nota</th><th>Ação</th><th>Detalhe</th></tr></thead><tbody>';
      rows.forEach((r) => {
        const detail = r.error || r.httpStatus || r.furkStatus || r.status || '';
        html += `<tr><td>${escapeHtml(r.nota)}</td><td>${escapeHtml(r.action)}</td><td>${escapeHtml(String(detail))}</td></tr>`;
      });
      html += '</tbody></table>';
      showResult(html, success && !failure ? 'success' : 'error');
      if (result) result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      // Na PRÉVIA, mantém a seleção e NÃO recarrega a grid, para permitir enviar
      // a mesma nota em seguida sem re-selecionar. Só o ENVIO real limpa/recarrega.
      if (send) {
        state.selected.clear();
        $('#rb-select-page').checked = false;
        loadGrid();
      }
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
      if (result) result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = originalText;
      }
    }
  }

  async function runExport() {
    try {
      const data = await api.post('/api/cycle/rebooking/export.php', {
        search: state.search,
        estado: state.estado,
      });
      if (data.success === false) {
        showResult(escapeHtml(data.message || 'Não foi possível exportar.'), 'error');
        return;
      }
      if (data._download) {
        const blob = await data._res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filenameFromDisposition(data._res.headers.get('content-disposition') || 'rebooking.xlsx');
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        showResult('<strong>Exportação lista.</strong> A planilha foi baixada.', 'success');
      }
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
    }
  }

  function filenameFromDisposition(disposition) {
    const match = disposition.match(/filename="?([^";]+)"?/);
    return match ? match[1] : 'rebooking.xlsx';
  }

  async function openControl(nota = '') {
    state.controlNota = nota;
    $('#control-search').value = '';
    controlSearchBar.hidden = true;
    modal.hidden = false;
    $('#control-modal-title').textContent = nota
      ? `Controle de Rebooking — Nota ${nota}`
      : 'Controle de Rebooking';
    await loadControl(1);
  }

  function closeControl() {
    modal.hidden = true;
    state.controlNota = '';
    controlSearchBar.hidden = true;
    $('#control-search').value = '';
  }

  async function loadControl(page) {
    controlBody.innerHTML = '<tr><td colspan="12" class="muted">Carregando…</td></tr>';
    try {
      const data = await api.post('/api/cycle/rebooking/control.php', {
        page,
        size: 25,
        search: $('#control-search').value.trim(),
        nota: state.controlNota,
      });
      if (!data.success) throw new Error(data.message || 'Não foi possível consultar o control.');
      renderControlRows(data.rows);
      renderControlPagination(data.total, page);
    } catch (error) {
      controlBody.innerHTML = `<tr><td colspan="12" class="muted error">${escapeHtml(error.message)}</td></tr>`;
      controlPagination.innerHTML = '';
    }
  }

  function renderControlRows(rows) {
    if (rows.length === 0) {
      const message = state.controlNota
        ? `Nenhum registro de controle para a nota ${escapeHtml(state.controlNota)} — ela ainda não foi enviada à Furk (linha azul).`
        : 'Nenhum registro de controle encontrado.';
      controlBody.innerHTML = `<tr><td colspan="12" class="muted">${message}</td></tr>`;
      return;
    }
    controlBody.innerHTML = rows.map((row) => `
      <tr>
        <td>${row.id}</td>
        <td>${escapeHtml(row.nota)}</td>
        <td>${escapeHtml(row.localizador)}</td>
        <td>${escapeHtml(row.tkt_num)}</td>
        <td>${escapeHtml(row.airline_code)}</td>
        <td>${escapeHtml(row.furk_rebooking_id)}</td>
        <td>${escapeHtml(row.furk_status)}</td>
        <td>${row.http_status ?? ''}</td>
        <td><span class="rb-control-badge ${escapeHtml(row.processing_status)}">${escapeHtml(row.processing_status)}</span></td>
        <td>${escapeHtml(row.furk_request_datetime)}</td>
        <td>${escapeHtml(row.updated_at)}</td>
        <td class="rb-cell-err" title="${escapeHtml(row.error_message)}">${row.error_message ? escapeHtml(String(row.error_message).slice(0, 60)) : '—'}</td>
      </tr>`).join('');
  }

  function renderControlPagination(total, page) {
    const totalPages = Math.max(1, Math.ceil(total / 25));
    controlPagination.innerHTML = '';
    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Página ${page} de ${totalPages} · ${total} registros`;
    controlPagination.appendChild(info);
    const prev = document.createElement('button');
    prev.type = 'button';
    prev.textContent = '‹';
    prev.disabled = page <= 1;
    prev.addEventListener('click', () => { if (page > 1) loadControl(page - 1); });
    controlPagination.appendChild(prev);
    for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(i);
      if (i === page) btn.className = 'active';
      btn.addEventListener('click', () => loadControl(i));
      controlPagination.appendChild(btn);
    }
    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = '›';
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => { if (page < totalPages) loadControl(page + 1); });
    controlPagination.appendChild(next);
  }

  async function openGate() {
    $('#gate-search').value = '';
    gateSearchBar.hidden = true;
    gateModal.hidden = false;
    await loadGate(1);
  }

  function closeGate() {
    gateModal.hidden = true;
    gateSearchBar.hidden = true;
    $('#gate-search').value = '';
  }

  async function loadGate(page) {
    gateBody.innerHTML = '<tr><td colspan="12" class="muted">Carregando…</td></tr>';
    try {
      const data = await api.post('/api/cycle/rebooking/gate.php', {
        page,
        size: 50,
        search: $('#gate-search').value.trim(),
      });
      if (!data.success) throw new Error(data.message || 'Não foi possível consultar o GATE.');
      renderGateRows(data.rows);
      renderGatePagination(data.total, page);
    } catch (error) {
      gateBody.innerHTML = `<tr><td colspan="12" class="muted error">${escapeHtml(error.message)}</td></tr>`;
      gatePagination.innerHTML = '';
    }
  }

  function renderGateRows(rows) {
    if (rows.length === 0) {
      gateBody.innerHTML = '<tr><td colspan="12" class="muted">Nenhum registro no GATE para esta busca.</td></tr>';
      return;
    }
    gateBody.innerHTML = rows.map((row) => `
      <tr>
        <td>${escapeHtml(row.nota)}</td>
        <td>${escapeHtml(row.localizador)}</td>
        <td>${escapeHtml(row.tkt_num)}</td>
        <td>${escapeHtml(row.nom_pax)}</td>
        <td>${escapeHtml(row.cod_for)}</td>
        <td>${escapeHtml(row.data_nota)}</td>
        <td>${escapeHtml(row.trecho)}</td>
        <td>${escapeHtml(row.produto)}</td>
        <td>${formatNumber(row.tarifa_br)}</td>
        <td>${formatNumber(row.Total)}</td>
        <td>${escapeHtml(row.faturado)}</td>
        <td>${escapeHtml(row.fil_agt)}</td>
      </tr>`).join('');
  }

  function renderGatePagination(total, page) {
    const totalPages = Math.max(1, Math.ceil(total / 50));
    gatePagination.innerHTML = '';
    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Página ${page} de ${totalPages} · ${total} registros`;
    gatePagination.appendChild(info);
    const prev = document.createElement('button');
    prev.type = 'button';
    prev.textContent = '‹';
    prev.disabled = page <= 1;
    prev.addEventListener('click', () => { if (page > 1) loadGate(page - 1); });
    gatePagination.appendChild(prev);
    for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(i);
      if (i === page) btn.className = 'active';
      btn.addEventListener('click', () => loadGate(i));
      gatePagination.appendChild(btn);
    }
    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = '›';
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => { if (page < totalPages) loadGate(page + 1); });
    gatePagination.appendChild(next);
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Mensagem do estado vazio, contextual ao filtro de estado ativo. O objetivo é
  // deixar claro que "0 registros" em "Não enviado" não é um erro: pode significar
  // que os bilhetes já foram enviados (e, portanto, saíram da lista de candidatos).
  function emptyMessage() {
    if (state.estado === 'no_enviado') {
      return 'Nenhum registro encontrado. Verifique se os bilhetes já não foram enviados! Use o botão "Ver tabela GATE" para conferir o que existe na origem.';
    }
    if (state.estado === 'enviado') {
      return 'Nenhum registro de envio encontrado para este estado.';
    }
    return 'Nenhum registro encontrado. Ajuste a busca ou use o botão "Ver tabela GATE" para conferir os candidatos da origem.';
  }

  function formatNumber(value) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return Number.isNaN(n) ? String(value) : n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  $('#rb-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') applyFilters(); });
  $('#rb-search-button').addEventListener('click', applyFilters);
  // Recarregar lê de novo os filtros da tela (busca, estado e tamanho): antes repetia a última busca aplicada
  // mesmo com o campo já limpo.
  $('#rb-reload').addEventListener('click', applyFilters);
  $('#rb-estado').addEventListener('change', applyFilters);
  $('#rb-size').addEventListener('change', applyFilters);

  $('#rb-check-all').addEventListener('click', () => {
    state.rows.forEach((row) => state.selected.add(row.nota));
    body.querySelectorAll('.rb-row-check').forEach((checkbox) => { checkbox.checked = true; });
    $('#rb-select-page').checked = true;
  });

  $('#rb-uncheck-all').addEventListener('click', () => {
    state.selected.clear();
    body.querySelectorAll('.rb-row-check').forEach((checkbox) => { checkbox.checked = false; });
    $('#rb-select-page').checked = false;
  });

  $('#rb-select-page').addEventListener('change', (event) => {
    body.querySelectorAll('.rb-row-check').forEach((checkbox) => {
      checkbox.checked = event.target.checked;
      if (event.target.checked) state.selected.add(checkbox.value);
      else state.selected.delete(checkbox.value);
    });
  });

  $('#rb-preview').addEventListener('click', () => runSend(false));
  $('#rb-send').addEventListener('click', () => runSend(true));
  $('#rb-export').addEventListener('click', runExport);
  $('#rb-control').addEventListener('click', () => openControl(''));
  $('#rb-gate').addEventListener('click', () => openGate());

  $('#control-modal-close').addEventListener('click', closeControl);
  modal.addEventListener('click', (event) => { if (event.target === modal) closeControl(); });
  controlToggleSearch.addEventListener('click', () => {
    controlSearchBar.hidden = !controlSearchBar.hidden;
    if (!controlSearchBar.hidden) {
      $('#control-search').focus();
    }
  });
  $('#control-search-button').addEventListener('click', () => loadControl(1));
  $('#control-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') loadControl(1); });

  $('#gate-modal-close').addEventListener('click', closeGate);
  gateModal.addEventListener('click', (event) => { if (event.target === gateModal) closeGate(); });
  gateToggleSearch.addEventListener('click', () => {
    gateSearchBar.hidden = !gateSearchBar.hidden;
    if (!gateSearchBar.hidden) {
      $('#gate-search').focus();
    }
  });
  $('#gate-search-button').addEventListener('click', () => loadGate(1));
  $('#gate-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') loadGate(1); });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeControl();
      closeGate();
    }
  });

  loadGrid();
})();