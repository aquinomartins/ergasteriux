(function(){
  let logoutHandler = null;

  function formatBRL(value){
    return Number(value || 0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' });
  }

  function renderGuestHeader(){
    return `
      <a href="#" class="topbar-btn" data-action="how-it-works">Como funciona?</a>
      <button type="button" class="topbar-btn" data-action="login">Entrar</button>
      <button type="button" class="topbar-btn primary" data-action="signup">Criar conta</button>
    `;
  }

  function renderLoggedHeader(user, balances){
    const name = user?.name || user?.email || 'Usuário';
    const avatar = user?.avatar_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=1f2937&color=ffffff&size=64`;
    return `
      <span class="topbar-chip">Portfólio: ${formatBRL(balances?.portfolio)}</span>
      <span class="topbar-chip">Cash: ${formatBRL(balances?.cash)}</span>
      <button type="button" class="topbar-btn primary" data-action="deposit">Depositar</button>
      <button type="button" class="topbar-icon-btn" aria-label="Notificações">🔔</button>
      <div class="topbar-user" id="topbarUserMenu">
        <button type="button" class="topbar-avatar-btn" data-action="toggle-user-menu"><img src="${avatar}" alt="Avatar"/><span>${name}</span></button>
        <div class="topbar-dropdown">
          <button type="button" data-action="profile">Perfil</button>
          <button type="button" data-action="assets">Meus Ativos</button>
          <button type="button" data-action="settings">Configurações</button>
          <button type="button" data-action="logout">Sair</button>
        </div>
      </div>
    `;
  }

  function updateMobileBlocks(session, balances){
    const shortcuts = document.getElementById('topbarMobileShortcuts');
    const account = document.getElementById('topbarMobileAccount');
    if (!shortcuts || !account) return;
    const logged = !!session?.logged;
    shortcuts.innerHTML = logged
      ? `<button class="topbar-btn" data-view="user_assets">Portfólio ${formatBRL(balances?.portfolio)}</button><button class="topbar-btn" data-view="user_assets">Cash ${formatBRL(balances?.cash)}</button><button class="topbar-btn primary" data-action="deposit">Depositar</button>`
      : '<button class="topbar-btn" data-action="how-it-works">Como funciona?</button>';
    account.innerHTML = logged
      ? '<button class="topbar-btn" data-action="profile">Perfil</button><button class="topbar-btn" data-action="logout">Sair</button>'
      : '<button class="topbar-btn" data-action="login">Entrar</button><button class="topbar-btn primary" data-action="signup">Criar conta</button>';
  }

  function bindActions(){
    document.addEventListener('click', async (event)=>{
      const actionEl = event.target.closest('[data-action]');
      if (!actionEl) return;
      const action = actionEl.dataset.action;
      if (action === 'toggle-user-menu') {
        const menu = document.getElementById('topbarUserMenu');
        menu?.classList.toggle('open');
        return;
      }
      if (action === 'logout') {
        event.preventDefault();
        if (typeof logoutHandler === 'function') await logoutHandler();
        return;
      }
      document.dispatchEvent(new CustomEvent('topbar:action', { detail:{ action } }));
    });

    document.addEventListener('click', (event)=>{
      const menu = document.getElementById('topbarUserMenu');
      if (menu && !menu.contains(event.target)) menu.classList.remove('open');
    });

    const searchBtn = document.getElementById('topbarSearchBtn');
    const searchLabel = document.querySelector('.topbar-search');
    if (searchBtn && searchLabel){
      searchBtn.addEventListener('click', ()=>searchLabel.classList.toggle('mobile-open'));
    }
  }

  function init(){ bindActions(); }

  function update(session, balances){
    const right = document.getElementById('topbarRight');
    if (!right) return;
    right.innerHTML = session?.logged ? renderLoggedHeader(session.user || session, balances || {}) : renderGuestHeader();
    updateMobileBlocks(session, balances || {});
  }

  function setLogoutHandler(handler){ logoutHandler = handler; }

  window.Topbar = { init, update, setLogoutHandler };
})();
