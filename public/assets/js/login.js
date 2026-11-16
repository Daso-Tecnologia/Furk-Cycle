// Tela de login. Movido do <script> embutido do login.html para arquivo próprio,
// para permitir Content-Security-Policy com script-src 'self' (sem 'unsafe-inline').
const form = document.getElementById('loginForm');
const errorDiv = document.getElementById('loginError');
const btnLogin = document.getElementById('btnLogin');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorDiv.style.display = 'none';

  const email = document.getElementById('email').value.trim();
  const senha = document.getElementById('senha').value;

  if (!email || !senha) {
    errorDiv.textContent = 'Informe e-mail e senha.';
    errorDiv.style.display = 'block';
    return;
  }

  btnLogin.disabled = true;
  btnLogin.textContent = 'Entrando...';

  try {
    const data = await api.post('/api/auth/login.php', { email, senha });

    if (data.success && data.token) {
      api.setToken(data.token);
      localStorage.setItem('furki_cycle_usuario', JSON.stringify(data.usuario));
      window.location.href = 'creditos-elegiveis.html';
    } else {
      errorDiv.textContent = data.error || 'Falha no login.';
      errorDiv.style.display = 'block';
    }
  } catch (err) {
    errorDiv.textContent = err.message || 'Erro de conexão. Tente novamente.';
    errorDiv.style.display = 'block';
  } finally {
    btnLogin.disabled = false;
    btnLogin.textContent = 'Entrar';
  }
});
