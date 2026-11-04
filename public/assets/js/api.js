const api = (() => {
    const TOKEN_KEY = 'furki_cycle_jwt';

    function apiBase() {
        const path = location.pathname;
        const idx = path.lastIndexOf('/');
        const dir = idx >= 0 ? path.slice(0, idx + 1) : '/';
        return dir.replace(/public\/$/, '');
    }

    function apiUrl(url) {
        return apiBase() + url.replace(/^\//, '');
    }

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(token) {
        localStorage.setItem(TOKEN_KEY, token);
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
    }

    function headers() {
        const h = {};
        const token = getToken();
        if (token) h['Authorization'] = 'Bearer ' + token;
        h['Content-Type'] = 'application/json';
        h['Accept'] = 'application/json';
        return h;
    }

    async function request(url, options = {}) {
        let res;
        try {
            res = await fetch(apiUrl(url), options);
        } catch (e) {
            const err = new Error('Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.');
            err.status = 0;
            throw err;
        }

        const contentType = res.headers.get('content-type') || '';
        let data;

        if (contentType.includes('application/json')) {
            const text = await res.text();
            if (text.trim() === '') {
                data = {};
            } else {
                try { data = JSON.parse(text); } catch { data = { raw: text }; }
            }
        } else {
            if (!res.ok) {
                // Resposta de erro não-JSON (ex.: HTML de 504/502/503 do servidor web).
                // Em vez de exibir o HTML, usa uma mensagem amigável.
                await res.text();
                data = { error: friendlyHttpMessage(res.status) };
            } else {
                return { _download: true, _res: res };
            }
        }

        if (!res.ok) {
            const err = new Error(data.error || (data.message ? data.message : 'Erro na requisição'));
            err.status = res.status;
            err.data = data;
            if (res.status === 401) {
                clearToken();
                if (!location.pathname.endsWith('/login')) {
                    location.href = 'login';
                }
            }
            throw err;
        }

        return data;
    }

    function friendlyHttpMessage(status) {
        const map = {
            400: 'Requisição inválida.',
            401: 'Sessão expirada. Faça login novamente.',
            403: 'Acesso não autorizado.',
            404: 'Recurso não encontrado.',
            405: 'Método não permitido.',
            408: 'Tempo da requisição esgotado.',
            500: 'Erro interno do servidor. Tente novamente.',
            502: 'O servidor está temporariamente indisponível. Tente novamente.',
            503: 'O servidor está sobrecarregado. Tente novamente em instantes.',
            504: 'O navegador parou de esperar (limite de 60 s), mas se for uma leitura ela continua no servidor e termina sozinha: acompanhe pelo aviso no topo da tela e use um período menor da próxima vez.',
        };
        return map[status] || `Erro ao processar a requisição (HTTP ${status}).`;
    }

    return {
        getToken,
        setToken,
        clearToken,

        async get(url) {
            return request(url, { method: 'GET', headers: headers() });
        },

        async post(url, body = {}) {
            return request(url, {
                method: 'POST',
                headers: headers(false),
                body: JSON.stringify(body),
            });
        },

        // Renova o JWT (sliding session). Só funciona com token ainda válido.
        async refresh() {
            const data = await request('/api/auth/refresh.php', {
                method: 'POST',
                headers: headers(),
                body: '{}',
            });
            if (data && data.token) {
                setToken(data.token);
            }
            return data;
        },
    };
})();