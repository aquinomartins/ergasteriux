(function () {
  const byId = (id) => document.getElementById(id);
  const section = document.querySelector('[data-login-continuity]') || document;

  const form = byId('loginForm') || section.querySelector('[data-role="login-form"]');
  const msg = byId('authMsg') || section.querySelector('[data-role="login-msg"]');
  const openAuthBtn = byId('authOverlayButton') || byId('openAuthBtn');
  const sessionBox = byId('loggedBox') || section.querySelector('[data-role="session-box"]');
  const sessionName = byId('profileName') || section.querySelector('[data-role="session-name"]');
  const sessionEmail = byId('profileEmail');
  const sessionAvatar = byId('profileAvatar');
  const logoutBtn = byId('logoutBtn') || section.querySelector('[data-role="logout-btn"]');
  let csrfToken = null;

  const setMessage = (text, type) => {
    if (!msg) return;
    msg.textContent = text || '';
    msg.classList.remove('ok', 'err');
    if (type === 'ok') msg.classList.add('ok');
    if (type === 'err') msg.classList.add('err');
  };

  const updateAvatar = (name, email, avatarUrl) => {
    if (!sessionAvatar) return;
    const fallbackName = String(name || email || 'Usuário').trim();
    const initials = fallbackName.split(/\s+/).slice(0, 2).map((p) => p.charAt(0)).join('').toUpperCase() || 'U';
    sessionAvatar.src = avatarUrl || `https://ui-avatars.com/api/?name=${encodeURIComponent(fallbackName)}&background=1f2937&color=ffffff&size=64`;
    sessionAvatar.alt = `Avatar de ${fallbackName}`;
    sessionAvatar.dataset.initials = initials;
  };

  const setSession = (session) => {
    csrfToken = session?.csrf_token || csrfToken;
    if (csrfToken) window.__csrfToken = csrfToken;

    const user = session?.user || {};
    const logged = !!session?.logged;

    if (form) form.hidden = logged;
    if (openAuthBtn) openAuthBtn.hidden = logged;
    if (sessionBox) sessionBox.hidden = !logged;

    if (sessionName) sessionName.textContent = user?.name || session?.name || user?.email || session?.email || 'Usuário';
    if (sessionEmail) sessionEmail.textContent = user?.email || session?.email || '';
    updateAvatar(user?.name || session?.name, user?.email || session?.email, user?.avatar_url || null);
  };

  const fetchJson = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'include', ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || 'Não foi possível concluir a operação.');
    }
    return data;
  };

  const loadSession = async () => {
    try {
      const data = await fetchJson('/api/session.php');
      setSession(data);
    } catch (_error) {
      setSession({ logged: false });
    }
  };

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setMessage('Entrando...', null);
      const fd = new FormData(form);
      const payload = {
        email: String(fd.get('email') || '').trim(),
        password: String(fd.get('password') || '')
      };

      try {
        await fetchJson('/auth/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}) },
          body: JSON.stringify(payload)
        });
        await loadSession();
        setMessage('Login realizado com sucesso.', 'ok');
      } catch (error) {
        setMessage(error.message, 'err');
      }
    });
  }

  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      setMessage('Saindo...', null);
      try {
        await fetchJson('/auth/logout.php', { method: 'POST', headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {} });
      } catch (_error) {
        // no-op
      }
      await loadSession();
      setMessage('Sessão encerrada.', 'ok');
    });
  }

  loadSession();
})();
