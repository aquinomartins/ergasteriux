if (window.__mercadoLmsrSpaBootstrapped) {
  if (typeof window.refreshMercadoLmsr === 'function') window.refreshMercadoLmsr();
} else {
window.__mercadoLmsrSpaBootstrapped = true;
const state = {
      markets: [],
      selectedMarket: null,
      selectedMarketId: null,
      filter: 'all',
      search: '',
      quote: null,
      lastUpdated: null,
      isLoadingMarkets: false,
      isLoadingDetail: false
    };

    const POLL_INTERVAL_MS = 7000;
    let listPollTimer = null;
    let detailPollTimer = null;
    const submittingForms = new WeakSet();

    let overviewEl = null;
    let listEl = null;
    let detailEl = null;
    let resolveFormEl = null;
    let claimFormEl = null;
    let resolveDialogEl = null;
    let resolveSummaryEl = null;

    // SPA reinjeta o HTML da view; por isso as refs de DOM precisam ser rebindadas no retorno.
    const bindDom = () => {
      overviewEl = document.getElementById('marketOverview');
      listEl = document.getElementById('marketList');
      detailEl = document.getElementById('marketDetail');
      resolveFormEl = document.querySelector('[data-role="resolve-market-form"]');
      claimFormEl = document.querySelector('[data-role="claim-market-form"]');
      resolveDialogEl = document.getElementById('resolveConfirmDialog');
      resolveSummaryEl = resolveDialogEl?.querySelector('[data-role="resolve-confirm-summary"]') || null;
    };

    const domReady = () => {
      if (!overviewEl || !listEl || !detailEl) bindDom();
      return Boolean(overviewEl && listEl && detailEl);
    };

    const renderMarketListSkeleton = (count = 4) => Array.from({ length: count }, () => `
      <article class="market-card market-card--skeleton" aria-hidden="true">
        <div class="market-skeleton-line market-skeleton-line--title"></div>
        <div class="market-skeleton-line"></div>
        <div class="market-skeleton-line market-skeleton-line--short"></div>
        <div class="market-card__meta">
          <span class="market-skeleton-pill"></span>
          <span class="market-skeleton-pill"></span>
          <span class="market-skeleton-pill"></span>
        </div>
      </article>
    `).join('');

    const renderMarketDetailSkeleton = () => `
      <div class="market-detail-skeleton" aria-hidden="true">
        <div class="market-skeleton-line market-skeleton-line--title"></div>
        <div class="market-skeleton-line"></div>
        <div class="market-skeleton-line market-skeleton-line--short"></div>
        <div class="market-skeleton-block"></div>
      </div>
    `;

    const formatNumber = (value, digits = 2) => {
      const parsed = Number(value);
      if (!Number.isFinite(parsed)) return '—';
      return parsed.toLocaleString('pt-BR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    };

    const formatPrice = (value) => formatNumber(value, 4);

    const formatDateTime = (value) => {
      if (!value) return '—';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString('pt-BR');
    };


    let csrfToken = window.__csrfToken || null;

    const getCsrfToken = async () => {
      if (window.__csrfToken) {
        csrfToken = window.__csrfToken;
      }
      if (csrfToken) return csrfToken;
      const sessionData = await fetchJson('/api/session.php');
      csrfToken = sessionData?.csrf_token || null;
      if (csrfToken) window.__csrfToken = csrfToken;
      return csrfToken;
    };

    const withCsrfHeaders = async (headers = {}) => {
      const token = await getCsrfToken();
      return token ? { ...headers, 'X-CSRF-Token': token } : headers;
    };

    const fetchJson = async (url, options) => {
      const res = await fetch(url, options);
      const text = await res.text();
      let data = null;
      if (text) {
        try {
          data = JSON.parse(text);
        } catch (error) {
          throw new Error('Resposta inválida do servidor.');
        }
      }
      if (!res.ok) {
        const message = data?.error || `Erro ao acessar o servidor (${res.status}).`;
        throw new Error(message);
      }
      return data;
    };

    const setFormSubmitting = (form, isSubmitting, label = 'Processando...') => {
      if (!form) return;
      const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (!submitBtn) return;

      const wasSubmitting = submittingForms.has(form);
      if (isSubmitting && wasSubmitting) return;

      if (isSubmitting) {
        submittingForms.add(form);
        if (!submitBtn.dataset.originalLabel) {
          submitBtn.dataset.originalLabel = submitBtn.tagName === 'INPUT'
            ? submitBtn.value
            : submitBtn.textContent;
        }
        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');
        form.setAttribute('aria-busy', 'true');
        if (submitBtn.tagName === 'INPUT') {
          submitBtn.value = label;
        } else {
          submitBtn.textContent = label;
        }
        return;
      }

      submittingForms.delete(form);
      submitBtn.disabled = false;
      submitBtn.classList.remove('is-loading');
      form.removeAttribute('aria-busy');
      const originalLabel = submitBtn.dataset.originalLabel;
      if (originalLabel) {
        if (submitBtn.tagName === 'INPUT') {
          submitBtn.value = originalLabel;
        } else {
          submitBtn.textContent = originalLabel;
        }
      }
    };

    const findMarketById = (marketId) => {
      if (!marketId) return null;
      const numericId = Number(marketId);
      if (!numericId) return null;
      return state.markets.find(market => Number(market.id) === numericId) || null;
    };

    const getCurrentMarket = () => state.selectedMarket || findMarketById(state.selectedMarketId);

    const getActionEligibility = (market) => {
      if (!market) {
        return {
          resolveEligible: false,
          claimEligible: false,
          resolveStatusLabel: '—',
          claimStatusLabel: '—'
        };
      }
      const resolveEligible = market.status === 'open';
      const claimEligible = market.status === 'resolved';
      return {
        resolveEligible,
        claimEligible,
        resolveStatusLabel: market.status,
        claimStatusLabel: claimEligible ? 'pending claim' : market.status
      };
    };

    const syncActionForms = () => {
      const market = getCurrentMarket();
      const marketId = market?.id || state.selectedMarketId || '';
      const eligibility = getActionEligibility(market);

      if (resolveFormEl) {
        resolveFormEl.market_id.value = marketId;
        const targetEl = resolveFormEl.querySelector('[data-role="resolve-target"]');
        const submitEl = resolveFormEl.querySelector('[data-role="resolve-submit"]');
        if (targetEl) {
          targetEl.textContent = market
            ? `Mercado #${market.id} (${market.title}) • Status atual: ${eligibility.resolveStatusLabel} • Elegível para resolver: open`
            : 'Selecione um mercado para resolver.';
        }
        if (submitEl) submitEl.disabled = submittingForms.has(resolveFormEl) || !eligibility.resolveEligible;
      }

      if (claimFormEl) {
        claimFormEl.market_id.value = marketId;
        const targetEl = claimFormEl.querySelector('[data-role="claim-target"]');
        const submitEl = claimFormEl.querySelector('[data-role="claim-submit"]');
        if (targetEl) {
          targetEl.textContent = market
            ? `Mercado #${market.id} (${market.title}) • Status atual: ${eligibility.claimStatusLabel} • Elegível para reclamar: resolved/pending claim`
            : 'Selecione um mercado para reclamar payout.';
        }
        if (submitEl) submitEl.disabled = submittingForms.has(claimFormEl) || !eligibility.claimEligible;
      }
    };

    const renderOverview = () => {
      const total = state.markets.length;
      const open = state.markets.filter(market => market.status === 'open').length;
      const resolved = state.markets.filter(market => market.status === 'resolved').length;
      const updated = state.lastUpdated ? formatDateTime(state.lastUpdated) : '—';
      overviewEl.innerHTML = `
        <div class="market-overview__grid">
          <div class="market-stat"><span class="subtle">Mercados cadastrados</span><strong class="heading heading--lg">${total}</strong></div>
          <div class="market-stat"><span class="subtle">Abertos</span><strong class="heading heading--lg">${open}</strong></div>
          <div class="market-stat"><span class="subtle">Resolvidos</span><strong class="heading heading--lg">${resolved}</strong></div>
          <div class="market-stat"><span class="subtle">Última atualização</span><strong class="heading heading--lg">${updated}</strong></div>
        </div>
      `;
    };

    const renderMarketList = () => {
      const filtered = state.markets.filter(market => {
        const matchesFilter = state.filter === 'all' || market.status === state.filter;
        const matchesSearch = !state.search || market.title.toLowerCase().includes(state.search);
        return matchesFilter && matchesSearch;
      });

      if (!filtered.length) {
        listEl.innerHTML = '<p class="hint">Nenhum mercado encontrado.</p>';
        return;
      }

      listEl.innerHTML = filtered.map(market => {
        const isActive = state.selectedMarketId === market.id;
        const eligibility = getActionEligibility(market);
        return `
          <article class="market-card${isActive ? ' is-active' : ''}" data-market-id="${market.id}">
            <div>
              <h3 class="heading heading--md">${market.title}</h3>
              <p class="subtle">${market.description || 'Sem descrição.'}</p>
            </div>
            <div class="market-card__meta subtle">
              <span class="pill">${market.status}</span>
              <span>SIM ${formatPrice(market.p_sim)}</span>
              <span>NÃO ${formatPrice(market.p_nao)}</span>
            </div>
            <p class="subtle">Resolver: ${eligibility.resolveStatusLabel} (elegível: open) • Reclamar: ${eligibility.claimStatusLabel} (elegível: resolved/pending claim)</p>
            <div class="market-detail__actions">
              <button type="button" class="ghost" data-action="resolve-selected" data-market-id="${market.id}" ${eligibility.resolveEligible ? '' : 'disabled'}>Resolver</button>
              <button type="button" class="ghost" data-action="claim-selected" data-market-id="${market.id}" ${eligibility.claimEligible ? '' : 'disabled'}>Reclamar</button>
            </div>
          </article>
        `;
      }).join('');
    };

    const renderMarketDetail = () => {
      if (!state.selectedMarket) {
        detailEl.innerHTML = '<p class="hint">Selecione um mercado para ver detalhes.</p>';
        return;
      }

      const market = state.selectedMarket;
      const position = market.my_position;
      const trades = Array.isArray(market.last_trades) ? market.last_trades : [];
      const eligibility = getActionEligibility(market);

      detailEl.innerHTML = `
        <header class="market-detail__header">
          <div>
            <h2 class="heading heading--lg">${market.title}</h2>
            <p class="subtle">${market.description || 'Sem descrição.'}</p>
          </div>
          <div class="market-detail__meta subtle">
            <span>Status: <strong>${market.status}</strong></span>
            <span>SIM ${formatPrice(market.p_sim)} • NÃO ${formatPrice(market.p_nao)}</span>
            <span>Criado em ${formatDateTime(market.created_at)}</span>
            <span>Resolver: ${eligibility.resolveStatusLabel} (elegível: open)</span>
            <span>Reclamar: ${eligibility.claimStatusLabel} (elegível: resolved/pending claim)</span>
          </div>
          <div class="market-detail__actions">
            <button type="button" class="ghost" data-action="resolve-selected" ${eligibility.resolveEligible ? '' : 'disabled'}>Resolver</button>
            <button type="button" class="ghost" data-action="claim-selected" ${eligibility.claimEligible ? '' : 'disabled'}>Reclamar</button>
          </div>
        </header>
        <section class="market-detail__grid">
          <div class="card">
            <h3 class="heading heading--md">Negociação</h3>
            <form data-role="trade-form">
              <label>Lado
                <select name="side" required>
                  <option value="yes">Comprar YES (SIM)</option>
                  <option value="no">Comprar NO (NÃO)</option>
                </select>
              </label>
              <label>Quantidade
                <input type="number" name="shares" min="1" value="1" required />
              </label>
              <div class="market-detail__actions">
                <button type="button" class="ghost" data-action="preview">Prévia</button>
                <button type="submit">Comprar</button>
              </div>
              <p class="msg" data-role="trade-msg"></p>
              <div class="market-quote" data-role="quote"></div>
            </form>
          </div>
          <div class="card">
            <h3 class="heading heading--md">Minha posição</h3>
            ${position ? `
              <p class="subtle">SIM: ${position.SIM.shares} cotas • Média ${formatPrice(position.SIM.avg_cost)}</p>
              <p class="subtle">NÃO: ${position.NAO.shares} cotas • Média ${formatPrice(position.NAO.avg_cost)}</p>
            ` : '<p class="hint subtle">Sem posições ainda. Faça sua primeira compra com poucas cotas para iniciar seu portfólio com tranquilidade.</p>'}
            <h3 class="heading heading--md">Trades recentes</h3>
            ${trades.length ? `
              <ul class="market-trade-list">
                ${trades.map(trade => `
                  <li>#${trade.id} ${trade.side} • ${trade.shares} cotas • ${formatPrice(trade.total_paid)}</li>
                `).join('')}
              </ul>
            ` : '<p class="hint subtle">Sem negociações recentes.</p>'}
          </div>
        </section>
      `;
      syncActionForms();
    };

    const refreshMarketDetail = async (marketId) => {
      if (!domReady()) return;
      if (!marketId) return;
      if (state.isLoadingDetail) return;
      state.isLoadingDetail = true;
      try {
        const data = await fetchJson(`/api/markets/get.php?id=${marketId}`);
        if (!data?.market) throw new Error('Mercado não encontrado.');
        state.selectedMarket = data.market;
        state.selectedMarketId = data.market.id;
        const index = state.markets.findIndex(market => Number(market.id) === Number(data.market.id));
        if (index >= 0) {
          state.markets[index] = { ...state.markets[index], ...data.market };
        }
        renderOverview();
        renderMarketList();
        renderMarketDetail();
        syncActionForms();
      } catch (error) {
        detailEl.innerHTML = `<p class="msg err">Erro ao carregar o mercado: ${error.message}</p>`;
      } finally {
        state.isLoadingDetail = false;
      }
    };

    const loadMarkets = async ({ soft = false, includeDetail = true } = {}) => {
      if (!domReady()) return;
      if (state.isLoadingMarkets) return;
      state.isLoadingMarkets = true;
      if (!soft) {
        listEl.innerHTML = renderMarketListSkeleton();
        detailEl.innerHTML = renderMarketDetailSkeleton();
      }
      try {
        const data = await fetchJson('/api/markets/list.php');
        state.markets = Array.isArray(data?.markets) ? data.markets : [];
        state.lastUpdated = new Date().toISOString();
        if (!state.selectedMarketId && state.markets.length) {
          state.selectedMarketId = state.markets[0].id;
        }
        renderOverview();
        renderMarketList();
        if (state.selectedMarketId && includeDetail) {
          await refreshMarketDetail(state.selectedMarketId);
        } else {
          renderMarketDetail();
          syncActionForms();
        }
      } catch (error) {
        listEl.innerHTML = `<p class="msg err">Erro ao carregar mercados: ${error.message}</p>`;
        renderOverview();
      } finally {
        state.isLoadingMarkets = false;
      }
    };

    const syncAfterMarketAction = async (marketId) => {
      await refreshMarketDetail(marketId);
      await loadMarkets({ soft: true });
    };

    const requestResolveConfirmation = (market, outcome) => new Promise((resolve) => {
      const outcomeLabel = outcome === 'yes' ? 'YES (SIM)' : 'NO (NÃO)';
      const summary = `Mercado #${market.id} — ${market.title}. Resultado final: ${outcomeLabel}. Impacto: negociação será encerrada e payouts poderão ser reclamados pelos vencedores.`;
      if (!resolveDialogEl || typeof resolveDialogEl.showModal !== 'function') {
        resolve(window.confirm(`${summary}

Confirma a resolução?`));
        return;
      }
      if (resolveSummaryEl) resolveSummaryEl.textContent = summary;
      const close = (confirmed) => {
        resolveDialogEl.close();
        resolve(confirmed);
      };
      const confirmBtn = resolveDialogEl.querySelector('[data-role="resolve-confirm"]');
      const cancelBtn = resolveDialogEl.querySelector('[data-role="resolve-cancel"]');
      const onConfirm = () => close(true);
      const onCancel = () => close(false);
      confirmBtn?.addEventListener('click', onConfirm, { once: true });
      cancelBtn?.addEventListener('click', onCancel, { once: true });
      resolveDialogEl.addEventListener('cancel', onCancel, { once: true });
      resolveDialogEl.showModal();
    });

    const handlePreview = async (form, msgEl, quoteEl) => {
      const side = form.side.value;
      const shares = Number(form.shares.value);
      if (!shares || shares <= 0) {
        msgEl.textContent = 'Informe uma quantidade válida.';
        msgEl.classList.add('err');
        return;
      }
      msgEl.textContent = 'Calculando...';
      msgEl.classList.remove('err');
      quoteEl.innerHTML = '';
      try {
        const data = await fetchJson(`/api/markets/quote.php?market_id=${state.selectedMarketId}&side=${side}&shares=${shares}`);
        state.quote = data.quote;
        quoteEl.innerHTML = `
          <div><strong>Custo:</strong> ${formatPrice(state.quote.delta_cost)}</div>
          <div><strong>Taxa:</strong> ${formatPrice(state.quote.fee)}</div>
          <div><strong>Total:</strong> ${formatPrice(state.quote.total)}</div>
        `;
        msgEl.textContent = 'Prévia atualizada.';
      } catch (error) {
        msgEl.textContent = `Erro ao calcular: ${error.message}`;
        msgEl.classList.add('err');
      }
    };

    const handleBuy = async (form, msgEl, quoteEl) => {
      const side = form.side.value;
      const shares = Number(form.shares.value);
      const idempotencyKey = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      msgEl.textContent = 'Enviando ordem...';
      msgEl.classList.remove('err');
      setFormSubmitting(form, true, 'Comprando...');
      try {
        const data = await fetchJson('/api/markets/buy.php', {
          method: 'POST',
          headers: await withCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ market_id: state.selectedMarketId, side, shares, idempotency_key: idempotencyKey })
        });
        msgEl.textContent = `Compra realizada! Total pago: ${formatPrice(data.total_paid)}`;
        quoteEl.innerHTML = '';
        state.quote = null;
        await syncAfterMarketAction(state.selectedMarketId);
      } catch (error) {
        msgEl.textContent = `Erro ao comprar: ${error.message}`;
        msgEl.classList.add('err');
      } finally {
        setFormSubmitting(form, false);
      }
    };

    const handleCreateMarket = async (form, msgEl) => {
      msgEl.textContent = 'Criando mercado...';
      msgEl.classList.remove('err');
      setFormSubmitting(form, true, 'Criando...');
      const payload = {
        title: form.title.value.trim(),
        description: form.description.value.trim(),
        risk_max: form.risk_max.value ? Number(form.risk_max.value) : undefined,
        fee_rate: form.fee_rate.value ? Number(form.fee_rate.value) / 100 : undefined,
        buffer_rate: form.buffer_rate.value ? Number(form.buffer_rate.value) / 100 : undefined
      };
      try {
        const data = await fetchJson('/api/markets/create.php', {
          method: 'POST',
          headers: await withCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify(payload)
        });
        msgEl.textContent = `Mercado criado! ID ${data.market_id}. Colateral exigido: ${formatPrice(data.required_collateral)}`;
        form.reset();
        await loadMarkets();
      } catch (error) {
        msgEl.textContent = `Erro: ${error.message}`;
        msgEl.classList.add('err');
      } finally {
        setFormSubmitting(form, false);
      }
    };

    const handleResolveMarket = async (form, msgEl) => {
      const marketId = Number(form.market_id.value || state.selectedMarketId);
      const market = getCurrentMarket();
      if (!marketId || !market) {
        msgEl.textContent = 'Selecione um mercado válido para resolver.';
        msgEl.classList.add('err');
        return;
      }
      if (market.status !== 'open') {
        msgEl.textContent = `Resolução indisponível: status atual ${market.status}.`;
        msgEl.classList.add('err');
        return;
      }
      const confirmed = await requestResolveConfirmation(market, form.outcome.value);
      if (!confirmed) {
        msgEl.textContent = 'Resolução cancelada.';
        msgEl.classList.remove('err');
        return;
      }
      msgEl.textContent = 'Resolvendo mercado...';
      msgEl.classList.remove('err');
      setFormSubmitting(form, true, 'Resolvendo...');
      const payload = {
        market_id: marketId,
        outcome: form.outcome.value
      };
      try {
        await fetchJson('/api/markets/resolve.php', {
          method: 'POST',
          headers: await withCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify(payload)
        });
        msgEl.textContent = 'Mercado resolvido com sucesso!';
        await syncAfterMarketAction(marketId);
      } catch (error) {
        msgEl.textContent = `Erro: ${error.message}`;
        msgEl.classList.add('err');
      } finally {
        setFormSubmitting(form, false);
        syncActionForms();
      }
    };

    const handleClaimMarket = async (form, msgEl) => {
      const marketId = Number(form.market_id.value || state.selectedMarketId);
      const market = getCurrentMarket();
      if (!marketId || !market) {
        msgEl.textContent = 'Selecione um mercado válido para reclamar payout.';
        msgEl.classList.add('err');
        return;
      }
      if (market.status !== 'resolved') {
        msgEl.textContent = `Claim indisponível: status atual ${market.status}.`;
        msgEl.classList.add('err');
        return;
      }
      msgEl.textContent = 'Solicitando payout...';
      msgEl.classList.remove('err');
      setFormSubmitting(form, true, 'Reclamando...');
      const payload = { market_id: marketId };
      try {
        const data = await fetchJson('/api/markets/claim.php', {
          method: 'POST',
          headers: await withCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify(payload)
        });
        msgEl.textContent = `Payout liberado: ${formatPrice(data.amount)}`;
        await syncAfterMarketAction(marketId);
      } catch (error) {
        msgEl.textContent = `Erro: ${error.message}`;
        msgEl.classList.add('err');
      } finally {
        setFormSubmitting(form, false);
        syncActionForms();
      }
    };

    const startPolling = () => {
      if (listPollTimer || detailPollTimer) return;
      listPollTimer = window.setInterval(() => {
        loadMarkets({ soft: true, includeDetail: false });
      }, POLL_INTERVAL_MS);
      detailPollTimer = window.setInterval(() => {
        if (state.selectedMarketId) {
          refreshMarketDetail(state.selectedMarketId);
        }
      }, POLL_INTERVAL_MS);
    };

    const stopPolling = () => {
      if (listPollTimer) {
        window.clearInterval(listPollTimer);
        listPollTimer = null;
      }
      if (detailPollTimer) {
        window.clearInterval(detailPollTimer);
        detailPollTimer = null;
      }
    };

    document.addEventListener('visibilitychange', () => {
      if (!domReady()) {
        stopPolling();
        return;
      }
      if (document.visibilityState === 'hidden') {
        stopPolling();
        return;
      }
      startPolling();
      loadMarkets({ soft: true, includeDetail: false });
      if (state.selectedMarketId) refreshMarketDetail(state.selectedMarketId);
    });

    document.addEventListener('click', (event) => {
      const actionBtn = event.target.closest('[data-action]');
      if (actionBtn?.dataset.action === 'refresh') {
        loadMarkets();
        return;
      }

      if (actionBtn?.dataset.action === 'resolve-selected' || actionBtn?.dataset.action === 'claim-selected') {
        const marketId = Number(actionBtn.dataset.marketId || state.selectedMarketId);
        if (marketId) {
          state.selectedMarketId = marketId;
          refreshMarketDetail(marketId);
          const targetForm = actionBtn.dataset.action === 'resolve-selected' ? resolveFormEl : claimFormEl;
          targetForm?.requestSubmit();
        }
        return;
      }

      const card = event.target.closest('[data-market-id]');
      if (card) {
        const marketId = Number(card.dataset.marketId);
        if (marketId) {
          state.selectedMarketId = marketId;
          refreshMarketDetail(marketId);
          renderMarketList();
          syncActionForms();
        }
      }

      const filterBtn = event.target.closest('[data-filter]');
      if (filterBtn) {
        state.filter = filterBtn.dataset.filter || 'all';
        document.querySelectorAll('[data-filter]').forEach(btn => btn.classList.remove('is-active'));
        filterBtn.classList.add('is-active');
        renderMarketList();
      }
    });

    document.addEventListener('input', (event) => {
      if (event.target?.dataset?.role === 'search') {
        state.search = event.target.value.trim().toLowerCase();
        renderMarketList();
      }
    });

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      if (form.matches('[data-role="trade-form"]')) {
        event.preventDefault();
        const msgEl = form.querySelector('[data-role="trade-msg"]');
        const quoteEl = form.querySelector('[data-role="quote"]');
        handleBuy(form, msgEl, quoteEl);
      }

      if (form.matches('[data-role="create-market-form"]')) {
        event.preventDefault();
        const msgEl = form.querySelector('[data-role="create-market-msg"]');
        handleCreateMarket(form, msgEl);
      }

      if (form.matches('[data-role="resolve-market-form"]')) {
        event.preventDefault();
        const msgEl = form.querySelector('[data-role="resolve-market-msg"]');
        handleResolveMarket(form, msgEl);
      }

      if (form.matches('[data-role="claim-market-form"]')) {
        event.preventDefault();
        const msgEl = form.querySelector('[data-role="claim-market-msg"]');
        handleClaimMarket(form, msgEl);
      }
    });

    document.addEventListener('click', (event) => {
      const previewBtn = event.target.closest('[data-action="preview"]');
      if (previewBtn) {
        const form = previewBtn.closest('form');
        const msgEl = form.querySelector('[data-role="trade-msg"]');
        const quoteEl = form.querySelector('[data-role="quote"]');
        handlePreview(form, msgEl, quoteEl);
      }
    });

    bindDom();
    loadMarkets();
    if (document.visibilityState === 'visible') {
      startPolling();
    }


window.refreshMercadoLmsr = () => {
  bindDom();
  loadMarkets();
  if (document.visibilityState === 'visible' && domReady()) startPolling();
};
window.teardownMercadoLmsr = () => {
  stopPolling();
};
}
