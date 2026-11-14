const auth = {
    verificar() {
        const token = api.getToken();
        if (!token) {
            location.href = 'login';
            return null;
        }
        return token;
    },

    sair() {
        api.clearToken();
        location.href = 'login';
    },
};

// --- Renovação de sessão por atividade (sliding session) -------------------
// Enquanto o usuário usa o sistema (mouse/teclado/menu/scroll), o token é
// renovado automaticamente; se ficar ocioso, ele expira normalmente.
const SESSION_REFRESH_THRESHOLD = 1800;   // renova quando faltar <= 30 min para expirar
const ACTIVITY_THROTTLE_MS = 5000;        // avalia atividade no máximo a cada 5s
const MIN_REFRESH_INTERVAL_MS = 60000;    // no máximo 1 renovação por minuto
let lastActivityCheck = 0;
let lastRefreshAt = 0;
let refreshing = false;

document.addEventListener('DOMContentLoaded', () => {
    auth.verificar();

    document.querySelectorAll('[data-action="logout"]').forEach((button) => {
        button.addEventListener('click', () => auth.sair());
    });

    const currentPath = window.location.pathname;
    document.querySelectorAll('[data-nav-link]').forEach((link) => {
        const href = link.getAttribute('href');
        if (href && (currentPath === href || currentPath.endsWith(href))) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    renderUserName();
    setupSessionCountdown();
    setupSessionRefresh();
    setupAvisoCargas();
});

// --- Regra "a Recursiva manda" (aviso nas telas) ---------------------------
// Enquanto a Sincronização Consolidada Contínua Recursiva estiver habilitada, as opções de
// CARGA são bloqueadas. O bloqueio de verdade está no servidor (HTTP 409); aqui só avisamos
// o usuário e barramos o clique. Rebooking, Webhook e telas de leitura não são afetados.
const PAGINAS_CARGA = [
    'creditos-elegiveis.html',
    'reembolsos-elegiveis.html',
    'status-dinamicos-leitura.html',
    'rotina-continua.html',
    'sales-leitura.html',
    'sales-rotina-continua.html',
    'sync-consolidada.html',
    'sync-continua.html',
];
const BOTOES_CARGA = '#sync-button, #sales-sync-button, #run-button, #start-button, #resume-button, #retry-button';
const BOTOES_RECURSIVA = '#save-button, #start-button, #stop-button';
const STATUS_POLL_MS = 30000;
const bloqueio = { seletor: null, mensagem: null };

function paginaAtual() {
    const nome = window.location.pathname.split('/').pop();
    return nome === '' ? 'creditos-elegiveis.html' : nome;
}

function usuarioAtual() {
    try {
        return JSON.parse(localStorage.getItem('furki_cycle_usuario') || 'null');
    } catch (e) {
        return null;
    }
}

function renderAviso(mensagem, destacar = false) {
    let el = document.getElementById('aviso-bloqueio');
    if (!el) {
        const container = document.querySelector('main.container') || document.querySelector('main');
        if (!container) return;
        el = document.createElement('div');
        el.id = 'aviso-bloqueio';
        el.className = 'aviso-bloqueio';
        el.setAttribute('role', 'alert');
        container.insertBefore(el, container.firstChild);
    }
    if (!mensagem) {
        el.hidden = true;
        el.textContent = '';
        return;
    }
    el.textContent = mensagem;
    el.hidden = false;
    if (destacar) {
        el.classList.add('destaque');
        setTimeout(() => el.classList.remove('destaque'), 1500);
    }
}

function marcarBotoes(seletor, ligado, mensagem) {
    document.querySelectorAll(seletor).forEach((botao) => {
        botao.classList.toggle('bloqueado', ligado);
        if (ligado) {
            botao.setAttribute('aria-disabled', 'true');
            botao.setAttribute('title', mensagem || '');
        } else {
            botao.removeAttribute('aria-disabled');
            botao.removeAttribute('title');
        }
    });
}

let statusTimer = null;

// Leitura manual interrompível (Vendas / Status Dinâmicos) em andamento: o ADMIN vê o botão na própria faixa.
function atualizarBotaoInterromper(faixa, op) {
    if (!faixa) return;
    let botao = document.getElementById('aviso-interromper');
    const usuario = usuarioAtual();
    const pode = !!(op.leitura_manual && op.leitura_manual.interrompivel && usuario && usuario.role === 'ADMIN' && !faixa.hidden);
    if (!pode) {
        if (botao) botao.remove();
        return;
    }
    if (!botao) {
        botao = document.createElement('button');
        botao.id = 'aviso-interromper';
        botao.type = 'button';
        botao.className = 'btn btn-small btn-warning';
        botao.style.marginLeft = '12px';
        botao.textContent = 'Interromper';
        botao.addEventListener('click', async () => {
            if (!window.confirm('Interromper a leitura? Ela para ao fim da página em andamento; o que já foi lido é mantido.')) return;
            botao.disabled = true;
            try {
                await api.post('/api/cycle/operacoes/interromper.php', {});
                botao.textContent = 'Interrompendo…';
            } catch (e) {
                botao.disabled = false;
                window.alert(e.message || 'Não foi possível interromper.');
            }
            atualizarBloqueioCargas();
        });
        faixa.appendChild(botao);
    }
}

async function atualizarBloqueioCargas() {
    let bloqueado = false;
    try {
        const data = await api.get('/api/cycle/operacoes/status.php');
        const op = data && data.operacoes;
        if (op && op.cargas_bloqueadas && op.mensagem) {
            bloqueado = true;
            bloqueio.seletor = BOTOES_CARGA;
            bloqueio.mensagem = op.aviso || op.mensagem;
            marcarBotoes(BOTOES_CARGA, true, bloqueio.mensagem);
            // A tela da própria rotina em andamento mostra o painel de andamento: não repete a faixa nela.
            const ehDona = Array.isArray(op.paginas) && op.paginas.includes(paginaAtual());
            renderAviso(ehDona ? null : bloqueio.mensagem);
            const faixa = document.getElementById('aviso-bloqueio');
            // Recursiva habilitada = bloqueio de verdade (laranja); uma carga em andamento = informativo (neutro).
            if (faixa) faixa.classList.toggle('aviso-info', !(op.recursiva && op.recursiva.ativa));
            atualizarBotaoInterromper(faixa, op);
        } else {
            bloqueio.mensagem = null;
            marcarBotoes(BOTOES_CARGA, false);
            renderAviso(null);
        }
    } catch (e) {
        // Se a consulta falhar, não mantém uma faixa possivelmente velha: o servidor continua bloqueando por conta própria.
        renderAviso(null);
    }
    // Com uma carga em andamento, consulta mais depressa (5 s) para o aviso sumir logo ao terminar.
    agendarStatus(bloqueado ? 5000 : STATUS_POLL_MS);
}

function agendarStatus(ms) {
    if (statusTimer) clearTimeout(statusTimer);
    statusTimer = setTimeout(atualizarBloqueioCargas, ms);
}

function setupAvisoCargas() {
    const pagina = paginaAtual();
    const ehCarga = PAGINAS_CARGA.includes(pagina);
    const ehRecursiva = pagina === 'sync-rotina.html';
    if (!ehCarga && !ehRecursiva) return;

    // Barra o clique antes dos tratadores da própria tela (fase de captura).
    document.addEventListener('click', (event) => {
        if (!bloqueio.mensagem || !bloqueio.seletor) return;
        const alvo = event.target.closest(bloqueio.seletor);
        if (!alvo) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        renderAviso(bloqueio.mensagem, true);
    }, true);

    if (ehRecursiva) {
        const usuario = usuarioAtual();
        if (!usuario || usuario.role !== 'ADMIN') {
            bloqueio.seletor = BOTOES_RECURSIVA;
            bloqueio.mensagem = 'Somente administradores podem configurar, iniciar ou interromper a Sincronização Consolidada Contínua Recursiva.';
            marcarBotoes(BOTOES_RECURSIVA, true, bloqueio.mensagem);
            renderAviso(bloqueio.mensagem);
        }
        return;
    }

    atualizarBloqueioCargas();
    // Ao voltar para a aba (o navegador atrasa os temporizadores das abas em segundo plano), atualiza na hora.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') atualizarBloqueioCargas();
    });
    window.addEventListener('focus', atualizarBloqueioCargas);
}

function renderUserName() {
    const el = document.querySelector('[data-user-name]');
    if (!el) return;
    try {
        const user = JSON.parse(localStorage.getItem('furki_cycle_usuario') || 'null');
        if (user && user.name) {
            el.textContent = user.role ? `${user.name} (${user.role})` : user.name;
        }
    } catch (e) {
        // mantém "Usuário autenticado"
    }
}

function decodeJwtExp(token) {
    try {
        const parts = token.split('.');
        if (parts.length < 2) return null;
        const b64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
        const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
        const payload = JSON.parse(atob(padded));
        return payload && typeof payload.exp === 'number' ? payload.exp : null;
    } catch (e) {
        return null;
    }
}

// Lê SEMPRE o token atual (para refletir renovações).
function currentExp() {
    const token = api.getToken();
    return token ? decodeJwtExp(token) : null;
}

function setupSessionCountdown() {
    const container = document.querySelector('.topbar-user');
    if (!container) return;

    const el = document.createElement('span');
    el.className = 'session-expiry';
    el.setAttribute('data-session-expiry', '');
    const logoutButton = container.querySelector('[data-action="logout"]');
    container.insertBefore(el, logoutButton || null);

    const update = () => {
        const exp = currentExp();
        if (!exp) {
            el.textContent = '';
            return;
        }
        const remain = exp - Math.floor(Date.now() / 1000);
        if (remain <= 0) {
            el.textContent = 'Sessão expirada';
            el.classList.add('danger');
            return;
        }
        const m = Math.floor(remain / 60);
        const s = remain % 60;
        el.textContent = `Sessão expira em ${m}m ${String(s).padStart(2, '0')}s`;
        el.classList.toggle('warning', remain < 300);
        el.classList.remove('danger');
    };

    update();
    setInterval(update, 1000);
}

function setupSessionRefresh() {
    const onActivity = () => {
        const now = Date.now();
        if (now - lastActivityCheck < ACTIVITY_THROTTLE_MS) return;
        lastActivityCheck = now;
        maybeRefresh();
    };

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach((evt) => {
        document.addEventListener(evt, onActivity, { passive: true });
    });

    // Tenta renovar já na abertura (caso o token esteja perto de expirar).
    maybeRefresh();
}

async function maybeRefresh() {
    if (refreshing) return;

    const exp = currentExp();
    if (!exp) return;

    const remain = exp - Math.floor(Date.now() / 1000);
    if (remain > SESSION_REFRESH_THRESHOLD) return;

    const now = Date.now();
    if (now - lastRefreshAt < MIN_REFRESH_INTERVAL_MS) return;
    lastRefreshAt = now;

    refreshing = true;
    try {
        await api.refresh();
    } catch (e) {
        // 401 já é tratado pelo api.js (logout). Outros erros são ignorados.
    } finally {
        refreshing = false;
    }
}
