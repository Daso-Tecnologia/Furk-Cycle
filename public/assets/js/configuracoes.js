(() => {
  const campos = {
    diasJanela: document.getElementById('cfgDias'),
    clientes: document.getElementById('cfgClientes'),
    fornecedores: document.getElementById('cfgFornecedores'),
    produtos: document.getElementById('cfgProdutos'),
    formasNota: document.getElementById('cfgFormas'),
    cadastros: document.getElementById('cfgCadastros'),
  };
  const erro = document.getElementById('cfgError');
  const ok = document.getElementById('cfgOk');
  const acoes = document.getElementById('cfgAcoes');
  const somenteLeitura = document.getElementById('cfgSomenteLeitura');
  const historico = document.getElementById('cfgHistorico');
  const ajudaDias = document.getElementById('cfgDiasAjuda');

  const ROTULOS = {
    'rebooking.gate.dias_janela': 'Janela de dias',
    'rebooking.gate.clientes': 'Clientes',
    'rebooking.gate.fornecedores': 'Fornecedores',
    'rebooking.gate.produtos': 'Produtos',
    'rebooking.gate.formas_nota': 'Formas de pagamento',
    'rebooking.gate.cadastros': 'Cadastros',
  };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
  const mostrar = (el, texto) => {
    el.textContent = texto || '';
    el.hidden = !texto;
  };

  function preencher(filtro) {
    campos.diasJanela.value = filtro.diasJanela;
    for (const chave of ['clientes', 'fornecedores', 'produtos', 'formasNota', 'cadastros']) {
      campos[chave].value = (filtro[chave] || []).join(', ');
    }
  }

  function coletar() {
    const dados = {};
    for (const [chave, input] of Object.entries(campos)) {
      dados[chave] = input.value;
    }
    return dados;
  }

  function dataBr(texto) {
    const d = new Date(String(texto).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? String(texto || '') : d.toLocaleString('pt-BR');
  }

  function renderHistorico(itens) {
    if (!itens.length) {
      historico.innerHTML = '<tr><td colspan="5" class="muted">Nenhuma alteração registrada (valem os padrões).</td></tr>';
      return;
    }
    historico.innerHTML = itens.map((h) => `<tr>
      <td>${escapeHtml(dataBr(h.alterado_em))}</td>
      <td>${escapeHtml(h.alterado_por_nome || '—')}</td>
      <td>${escapeHtml(ROTULOS[h.chave] || h.chave)}</td>
      <td>${escapeHtml(h.valor_anterior || '—')}</td>
      <td>${escapeHtml(h.valor_novo)}</td>
    </tr>`).join('');
  }

  let padroes = null;

  function aplicar(data) {
    padroes = data.defaults;
    preencher(data.filter);
    renderHistorico(data.history || []);
    const lim = data.limits || {};
    ajudaDias.textContent = `Entre ${lim.minDias} e ${lim.maxDias} dias. Padrão: ${data.defaults.diasJanela}.`;
    const podeEditar = data.canEdit === true;
    acoes.hidden = !podeEditar;
    somenteLeitura.hidden = podeEditar;
    for (const input of Object.values(campos)) {
      input.disabled = !podeEditar;
    }
  }

  async function carregar() {
    mostrar(erro, '');
    try {
      aplicar(await api.get('/api/admin/settings/rebooking.php'));
    } catch (err) {
      historico.innerHTML = '';
      mostrar(erro, err.message || 'Não foi possível carregar as configurações.');
    }
  }

  async function salvar(corpo) {
    mostrar(erro, '');
    mostrar(ok, '');
    try {
      const data = await api.post('/api/admin/settings/rebooking.php', corpo);
      aplicar(data);
      mostrar(ok, data.message || 'Salvo.');
    } catch (err) {
      mostrar(erro, err.message || 'Não foi possível salvar.');
    }
  }

  document.addEventListener('click', (event) => {
    const botao = event.target.closest('[data-action]');
    if (!botao) return;
    if (botao.dataset.action === 'cfg-save') {
      salvar(coletar());
    } else if (botao.dataset.action === 'cfg-defaults') {
      if (padroes && window.confirm('Voltar todas as regras do Rebooking ao padrão?')) {
        salvar({ restaurarPadrao: true });
      }
    }
  });

  carregar();
})();
