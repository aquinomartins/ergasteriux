(function () {
  const section = document.querySelector('[data-login-continuity]');
  if (!section) return;

  const form = section.querySelector('[data-role="login-form"]');
  const msg = section.querySelector('[data-role="login-msg"]');
  const sessionBox = section.querySelector('[data-role="session-box"]');
  const sessionName = section.querySelector('[data-role="session-name"]');
  const logoutBtn = section.querySelector('[data-role="logout-btn"]');
  let csrfToken = null;

  const setMessage = (text, type) => {
    if (!msg) return;
    msg.textContent = text || '';
    msg.classList.remove('ok', 'err');
    if (type === 'ok') msg.classList.add('ok');
    if (type === 'err') msg.classList.add('err');
  };

  const setSession = (session) => {
    csrfToken = session?.csrf_token || csrfToken;
    if (csrfToken) window.__csrfToken = csrfToken;
    const logged = !!session?.logged;
    if (form) form.hidden = logged;
    if (sessionBox) sessionBox.hidden = !logged;
    if (sessionName) sessionName.textContent = session?.name || session?.email || 'usuário';
  };

  const fetchJson = async (url, options) => {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = {};
    if (text) {
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('Resposta inválida do servidor.');
      }
    }
    if (!response.ok) {
      throw new Error(data.error || 'Não foi possível concluir a operação.');
    }
    return data;
  };

  const loadSession = async () => {
    try {
      const data = await fetchJson('/api/session.php');
      setSession(data);
    } catch (error) {
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
        const data = await fetchJson('/auth/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}) },
          body: JSON.stringify(payload)
        });
        setSession({ logged: true, name: data?.name, email: payload.email });
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
      } catch (error) {
        // no-op
      }
      setSession({ logged: false });
      setMessage('Sessão encerrada.', 'ok');
    });
  }

  loadSession();
})();
