(() => {
  const form = document.querySelector('#sync-form');
  const runButton = document.querySelector('#run-button');
  const initial = document.querySelector('#initial-date');
  const final = document.querySelector('#final-date');
  const cardEstado = document.querySelector('#cardEstado');
  const cardTabela = document.querySelector('#cardTabela');
  const cardHeartbeat = document.querySelector('#cardHeartbeat');
  const progress = document.querySelector('#progress');
  const progressTitle = document.querySelector('#progress-title');
  const progressBody = document.querySelector('#progress-body');
  const progressBar = document.querySelector('#progress-bar');
  const result = document.querySelector('#result');
  const stopButton = document.querySelector('#stop-button');
  let pollTimer = null;

  const setValue = (el, value) => { if (el) el.textContent = value ?? '-'; };

  // Somente administradores interrompem (a API também exige ADMIN).
  function isAdmin() {
    try {
      const user = JSON.parse(localStorage.getItem('furki_cycle_usuario') || 'null');
      return !!user && user.role === 'ADMIN';
    } catch (e) {
      return false;
    }
  }

  const TABELAS = { sales: 'Vendas', creditos: 'Créditos', refunds: 'Reembolsos', bilhetes: 'Status Dinâmicos' };

  function fmtDia(iso) {
    return iso && /^\d{4}-\d{2}-\d{2}$/.test(iso) ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : '';
  }

  function toDate(text) {
    if (!text) return null;
    const d = new Date(String(text).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function fmtHora(text) {
    const d = toDate(text);
    return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
  }

  function fmtDataHora(text) {
    const d = toDate(text);
    return d ? `${d.toLocaleDateString('pt-BR')} às ${fmtHora(text)}` : '';
  }

  function fmtDuracao(job) {
    const a = toDate(job.created_at);
    const b = toDate(job.heartbeat);
    if (!a || !b || b < a) return '';
    const s = Math.round((b - a) / 1000);
    return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60 ? `${s % 60} s` : ''}`.trim();
  }

  // "Passo 2 de 4: Créditos (bloco 01/10–05/10)"
  function descreverPasso(prog) {
    if (!prog || !prog.tabela) return '';
    const passo = Math.min(Number(prog.passo ?? 0) + 1, Number(prog.passos ?? 4));
    const bloco = prog.bloco_inicio && prog.bloco_fim ? ` (bloco ${fmtDia(prog.bloco_inicio)}–${fmtDia(prog.bloco_fim)})` : '';
    return `Passo ${passo} de ${Number(prog.passos ?? 4)}: ${TABELAS[prog.tabela] || prog.tabela}${bloco}`;
  }

  function setPainel(tipo, titulo, corpo, percent) {
    progress.hidden = false;
    progress.className = `panel painel-carga${tipo ? ` painel-${tipo}` : ''}`;
    progressTitle.textContent = titulo;
    progressBody.textContent = corpo;
    if (percent === null || percent === undefined) {
      progressBar.parentElement.hidden = true;
    } else {
      progressBar.parentElement.hidden = false;
      progressBar.style.width = `${Math.max(2, Math.min(100, percent))}%`;
    }
  }

  function showResult(html, type) {
    result.hidden = !html;
    result.className = `panel ${type === 'error' ? 'error' : 'success'}`;
    result.innerHTML = html || '';
  }

  function renderJob(job) {
    if (!job || !job.id) {
      setValue(cardEstado, 'Nenhuma execução');
      setValue(cardTabela, '-');
      setValue(cardHeartbeat, '-');
      return;
    }

    const prog = job.progresso || {};
    const periodo = `${fmtDia(job.initial_date)} a ${fmtDia(job.final_date)}`;
    const quem = prog.iniciado_por ? ` por ${prog.iniciado_por}` : '';
    const inicio = fmtHora(job.created_at);
    const interrompida = job.estado === 'STOP_REQUESTED' && prog.status === 'interrompido';
    const manual = job.estado === 'ERROR' && String(job.erro || '').startsWith('Interrompido manualmente');
    const emAndamento = job.estado === 'QUEUED' || job.estado === 'RUNNING' || (job.estado === 'STOP_REQUESTED' && !interrompida);

    setValue(cardHeartbeat, fmtDataHora(job.heartbeat || job.updated_at) || '-');
    stopButton.hidden = !(isAdmin() && (job.estado === 'RUNNING' || job.estado === 'QUEUED'));
    stopButton.disabled = false;
    showResult('', 'success');

    if (job.estado === 'QUEUED') {
      setValue(cardEstado, 'Na fila');
      setValue(cardTabela, 'Aguardando o início');
      setPainel('', 'Sincronização na fila', `Período ${periodo}. O processamento começa em até 1 minuto. Você pode fechar esta página: a sincronização continua em segundo plano.`, 0);
    } else if (job.estado === 'RUNNING') {
      setValue(cardEstado, 'Em andamento');
      setValue(cardTabela, descreverPasso(prog) || 'Iniciando');
      const retomada = Number(prog.retomadas || 0) > 0 ? ' O processamento foi retomado de onde parou.' : '';
      setPainel('', `Sincronização em andamento · ${job.percent ?? 0}%`,
        `Período ${periodo}, iniciada${quem}${inicio ? ` às ${inicio}` : ''}. ${descreverPasso(prog)}.${retomada} Você pode fechar esta página: a sincronização continua em segundo plano.`, job.percent ?? 0);
    } else if (job.estado === 'STOP_REQUESTED' && !interrompida) {
      setValue(cardEstado, 'Interrompendo…');
      setValue(cardTabela, descreverPasso(prog) || '-');
      setPainel('neutro', 'Interrompendo…', 'A parada foi pedida e acontece ao fim do passo em andamento (alguns instantes). O que já foi importado é mantido.', null);
    } else if (interrompida || manual) {
      setValue(cardEstado, 'Interrompida');
      setValue(cardTabela, '-');
      const quando = fmtDataHora(job.heartbeat || job.updated_at);
      const continuar = interrompida && prog.cursor ? ` Para continuar, inicie um novo período a partir de ${fmtDia(prog.cursor)}.` : '';
      setPainel('neutro', 'Última sincronização: interrompida', `Período ${periodo}. Interrompida por um administrador${quando ? ` em ${quando}` : ''}. O que já foi importado foi mantido.${continuar}`, null);
    } else if (job.estado === 'COMPLETED') {
      setValue(cardEstado, 'Concluída');
      setValue(cardTabela, '-');
      const dur = fmtDuracao(job);
      setPainel('ok', 'Última sincronização: concluída', `Período ${periodo}${dur ? `, em ${dur}` : ''}.`, 100);
      showResult(renderSummary(job), 'success');
    } else if (job.estado === 'ERROR') {
      setValue(cardEstado, 'Não concluída');
      setValue(cardTabela, '-');
      setPainel('neutro', 'Última sincronização: não concluída', `Período ${periodo}. Tente novamente com um período menor; se acontecer de novo, avise o suporte informando o motivo abaixo.`, null);
      showResult(`<strong>Motivo:</strong> ${escapeHtml(job.erro || 'não informado')}`, 'error');
    }

    if (emAndamento) startPolling(job.id); else stopPolling();
  }

  function renderSummary(job) {
    const s = job.resumo || {};
    const rotulos = { total: 'Registros', inserted: 'Novos', updated: 'Atualizados', pages: 'Páginas' };
    let html = '<strong>Resumo da sincronização</strong><ul style="margin:8px 0 0;padding-left:18px">';
    for (const [key, val] of Object.entries(s)) {
      if (typeof val !== 'object' || val === null) continue;
      const total = Number(val.total ?? val.tickets ?? 0);
      const novos = val.inserted !== undefined ? ` (${Number(val.inserted)} novos, ${Number(val.updated || 0)} atualizados)` : '';
      html += `<li>${escapeHtml(TABELAS[key] || key)}: <strong>${total}</strong> registros${novos}</li>`;
    }
    return `${html}</ul>`;
  }

  function startPolling(id) {
    stopPolling();
    pollTimer = setInterval(async () => {
      try {
        const data = await api.get(`/api/cycle/sync/job.php?id=${id}`);
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

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    runButton.disabled = true;
    runButton.textContent = 'Iniciando…';
    try {
      const data = await api.post('/api/cycle/sync/job.php', {
        action: 'start',
        initialDate: initial.value,
        finalDate: final.value,
      });
      if (!data.success) throw new Error(data.message || 'Não foi possível iniciar.');
      renderJob(data.job);
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
    } finally {
      runButton.disabled = false;
      runButton.textContent = 'Executar sincronização';
    }
  });

  stopButton.addEventListener('click', async () => {
    if (!window.confirm('Interromper a sincronização? Ela para ao fim do passo em andamento; o que já foi importado é mantido.')) return;
    stopButton.disabled = true;
    try {
      const data = await api.post('/api/cycle/sync/job.php', { action: 'stop' });
      if (!data.success) throw new Error(data.message || 'Não foi possível interromper.');
      renderJob(data.job);
    } catch (error) {
      stopButton.disabled = false;
      showResult(escapeHtml(error.message), 'error');
    }
  });

  // Ao voltar para a aba, atualiza na hora (o navegador atrasa os temporizadores das abas em segundo plano).
  document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState !== 'visible') return;
    try {
      const data = await api.get('/api/cycle/sync/job.php');
      if (data.success) renderJob(data.job);
    } catch (e) { /* ignore */ }
  });

  // Carrega o último job ao abrir a tela
  (async () => {
    try {
      const data = await api.get('/api/cycle/sync/job.php');
      if (data.success) renderJob(data.job);
    } catch (e) { /* ignore */ }
  })();
})();
