(() => {
  const tableBody = document.getElementById('usersBody');
  const userModal = document.getElementById('userModal');
  const passwordModal = document.getElementById('passwordModal');
  const userError = document.getElementById('userError');
  const passwordError = document.getElementById('passwordError');

  let editingId = null;
  let passwordUserId = null;

  const show = (el) => { el.hidden = false; };
  const hide = (el) => { el.hidden = true; };
  const setError = (el, msg) => {
    el.textContent = msg;
    el.hidden = !msg;
  };

  async function loadUsers() {
    tableBody.innerHTML = '<tr><td colspan="5" class="muted">Carregando…</td></tr>';
    try {
      const data = await api.post('/api/admin/users/list.php');
      if (!data.success) {
        throw new Error(data.message || 'Falha ao listar.');
      }
      renderUsers(data.users || []);
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="5" class="error-msg">${escapeHtml(err.message || 'Erro ao carregar usuários.')}</td></tr>`;
    }
  }

  function renderUsers(users) {
    if (!users.length) {
      tableBody.innerHTML = '<tr><td colspan="5" class="muted">Nenhum usuário cadastrado.</td></tr>';
      return;
    }
    tableBody.innerHTML = users.map((u) => {
      const active = Number(u.is_active) === 1;
      const badge = active
        ? '<span class="status-badge status-active">Ativo</span>'
        : '<span class="status-badge status-inactive">Inativo</span>';
      return `
        <tr>
          <td>${escapeHtml(u.name)}</td>
          <td>${escapeHtml(u.email)}</td>
          <td>${escapeHtml(u.role)}</td>
          <td>${badge}</td>
          <td class="actions">
            <button class="btn btn-small btn-outline" data-action="edit" data-id="${u.id}">Editar</button>
            <button class="btn btn-small btn-outline" data-action="reset" data-id="${u.id}">Senha</button>
          </td>
        </tr>`;
    }).join('');
  }

  function openUserModal(mode, user) {
    editingId = user ? Number(user.id) : null;
    document.getElementById('userModalTitle').textContent = mode === 'edit' ? 'Editar usuário' : 'Novo usuário';
    document.getElementById('uName').value = user ? user.name : '';
    document.getElementById('uEmail').value = user ? user.email : '';
    document.getElementById('uRole').value = user ? user.role : 'ADMIN';
    document.getElementById('uPassword').value = '';
    document.getElementById('uActive').checked = user ? Number(user.is_active) === 1 : true;
    document.getElementById('uPasswordGroup').hidden = mode === 'edit';
    document.getElementById('uActiveGroup').hidden = mode !== 'edit';
    setError(userError, '');
    show(userModal);
  }

  async function saveUser() {
    const name = document.getElementById('uName').value.trim();
    const email = document.getElementById('uEmail').value.trim();
    const role = document.getElementById('uRole').value;
    const password = document.getElementById('uPassword').value;
    const isActive = document.getElementById('uActive').checked;

    if (!name || !email) {
      setError(userError, 'Informe nome e e-mail.');
      return;
    }

    const payload = editingId
      ? { id: editingId, name, email, role, is_active: isActive }
      : { name, email, role, password };

    const endpoint = editingId ? '/api/admin/users/update.php' : '/api/admin/users/create.php';

    try {
      const data = await api.post(endpoint, payload);
      if (!data.success) {
        throw new Error(data.message || 'Falha ao salvar.');
      }
      hide(userModal);
      loadUsers();
    } catch (err) {
      setError(userError, err.message || 'Falha ao salvar usuário.');
    }
  }

  function openPasswordModal(user) {
    passwordUserId = Number(user.id);
    document.getElementById('passwordUser').textContent = `${user.name} (${user.email})`;
    document.getElementById('pPassword').value = '';
    setError(passwordError, '');
    show(passwordModal);
  }

  async function savePassword() {
    const password = document.getElementById('pPassword').value;
    if (!password || password.length < 8) {
      setError(passwordError, 'A nova senha deve ter pelo menos 8 caracteres.');
      return;
    }
    try {
      const data = await api.post('/api/admin/users/reset-password.php', { id: passwordUserId, password });
      if (!data.success) {
        throw new Error(data.message || 'Falha ao redefinir a senha.');
      }
      hide(passwordModal);
    } catch (err) {
      setError(passwordError, err.message || 'Falha ao redefinir a senha.');
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  tableBody.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const id = Number(button.getAttribute('data-id'));
    const action = button.getAttribute('data-action');

    let users = [];
    try {
      const data = await api.post('/api/admin/users/list.php');
      users = data.users || [];
    } catch { /* ignore */ }
    const user = users.find((u) => Number(u.id) === id);

    if (action === 'edit') openUserModal('edit', user);
    if (action === 'reset') openPasswordModal(user);
  });

  document.querySelector('[data-action="new-user"]').addEventListener('click', () => openUserModal('create', null));
  document.querySelector('[data-action="refresh"]').addEventListener('click', loadUsers);
  document.querySelector('[data-action="close-user"]').addEventListener('click', () => hide(userModal));
  document.querySelector('[data-action="save-user"]').addEventListener('click', saveUser);
  document.querySelector('[data-action="close-password"]').addEventListener('click', () => hide(passwordModal));
  document.querySelector('[data-action="save-password"]').addEventListener('click', savePassword);

  userModal.addEventListener('click', (event) => {
    if (event.target === userModal) hide(userModal);
  });
  passwordModal.addEventListener('click', (event) => {
    if (event.target === passwordModal) hide(passwordModal);
  });

  loadUsers();
})();
