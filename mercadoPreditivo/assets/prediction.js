(() => {
  const formatNumber = (value, digits = 4) => {
    const num = Number(value || 0);
    return num.toLocaleString('pt-BR', {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    });
  };

  const formatBrl = (value) => {
    const num = Number(value || 0);
    return num.toLocaleString('pt-BR', {
      style: 'currency',
      currency: 'BRL',
    });
  };

  const renderMarkets = async () => {
    const listEl = document.querySelector('[data-market-list]');
    if (!listEl) return;

    listEl.innerHTML = '<p>Carregando mercados...</p>';
    try {
      const res = await fetch('/api/prediction_markets.php');
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Erro ao carregar mercados');
      }

      const markets = data.markets || [];
      if (!markets.length) {
        listEl.innerHTML = '<p>Nenhum mercado disponível ainda.</p>';
        return;
      }

      listEl.innerHTML = markets.map(market => {
        const position = market.my_position;
        return `
          <article class="market-card">
            <h2><a href="/mercadoPreditivo/public/market.php?slug=${encodeURIComponent(market.slug)}">${market.title}</a></h2>
            <p>${market.description || ''}</p>
            <div class="market-meta">
              <span>Status: ${market.status}</span>
              <span>Fecha em: ${market.close_at ?? '—'}</span>
            </div>
            <div class="prices">
              <span>YES: ${formatNumber(market.price_yes, 4)}</span>
              <span>NO: ${formatNumber(market.price_no, 4)}</span>
            </div>
            ${position ? `<div class="positions"><span>Minha posição YES: ${formatNumber(position.shares_yes, 4)}</span><span>NO: ${formatNumber(position.shares_no, 4)}</span></div>` : ''}
          </article>
        `;
      }).join('');
    } catch (err) {
      listEl.innerHTML = `<p>Erro ao carregar mercados: ${err.message}</p>`;
    }
  };

  const renderMarketDetail = async () => {
    const container = document.querySelector('[data-market-detail]');
    if (!container) return;

    const slug = container.getAttribute('data-market-slug');
    if (!slug) {
      container.innerHTML = '<p>Mercado inválido.</p>';
      return;
    }

    const titleEl = container.querySelector('[data-market-title]');
    const descriptionEl = container.querySelector('[data-market-description]');
    const statusEl = container.querySelector('[data-market-status]');
    const closeEl = container.querySelector('[data-market-close]');
    const priceYesEl = container.querySelector('[data-price-yes]');
    const priceNoEl = container.querySelector('[data-price-no]');
    const positionYesEl = container.querySelector('[data-position-yes]');
    const positionNoEl = container.querySelector('[data-position-no]');
    const volumeEl = container.querySelector('[data-market-volume]');
    const tradesEl = container.querySelector('[data-market-trades]');

    const quoteEl = container.querySelector('[data-quote]');
    const messageEl = container.querySelector('[data-trade-message]');
    const balanceEl = container.querySelector('[data-balance]');
    const formEl = container.querySelector('form');

    const fetchDetail = async () => {
      const res = await fetch(`/api/prediction_markets.php?slug=${encodeURIComponent(slug)}`);
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Erro ao carregar mercado');
      }
      return data.market;
    };

    const updateDetail = async () => {
      container.classList.add('is-loading');
      try {
        const market = await fetchDetail();
        titleEl.textContent = market.title;
        descriptionEl.textContent = market.description || '';
        statusEl.textContent = market.status;
        closeEl.textContent = market.close_at ?? '—';
        priceYesEl.textContent = formatNumber(market.price_yes, 4);
        priceNoEl.textContent = formatNumber(market.price_no, 4);
        if (positionYesEl && market.my_position) {
          positionYesEl.textContent = formatNumber(market.my_position.shares_yes, 4);
          positionNoEl.textContent = formatNumber(market.my_position.shares_no, 4);
        }
        if (volumeEl && market.stats) {
          volumeEl.textContent = formatBrl(market.stats.volume_brl || 0);
        }
        if (tradesEl && market.stats) {
          tradesEl.textContent = market.stats.total_trades || 0;
        }
        container.dataset.marketId = market.id;
        if (quoteEl) {
          quoteEl.textContent = '';
        }
      } catch (err) {
        container.innerHTML = `<p>Erro ao carregar mercado: ${err.message}</p>`;
      } finally {
        container.classList.remove('is-loading');
      }
    };

    const updateQuote = async () => {
      if (!quoteEl) return;
      const marketId = container.dataset.marketId;
      if (!marketId) return;

      const outcome = formEl.querySelector('[name="outcome"]').value;
      const side = formEl.querySelector('[name="side"]').value;
      const shares = Number(formEl.querySelector('[name="shares"]').value || 0);
      if (!shares || shares <= 0) {
        quoteEl.textContent = '';
        return;
      }

      quoteEl.textContent = 'Calculando...';
      try {
        const res = await fetch('/api/prediction_quote.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            market_id: Number(marketId),
            outcome,
            side,
            shares,
          }),
        });
        const data = await res.json();
        if (!res.ok) {
          throw new Error(data.error || 'Erro ao cotar');
        }
        quoteEl.textContent = `Delta BRL: ${formatBrl(data.cash_delta_brl)} | Preço YES: ${formatNumber(data.price_yes_after, 4)}`;
      } catch (err) {
        quoteEl.textContent = `Erro ao cotar: ${err.message}`;
      }
    };

    formEl.addEventListener('input', updateQuote);

    formEl.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (messageEl) {
        messageEl.textContent = '';
        messageEl.className = '';
      }

      const marketId = container.dataset.marketId;
      const outcome = formEl.querySelector('[name="outcome"]').value;
      const side = formEl.querySelector('[name="side"]').value;
      const shares = Number(formEl.querySelector('[name="shares"]').value || 0);

      try {
        const res = await fetch('/api/prediction_trade.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            market_id: Number(marketId),
            outcome,
            side,
            shares,
          }),
        });
        const data = await res.json();
        if (!res.ok) {
          throw new Error(data.error || 'Erro ao executar trade');
        }
        if (messageEl) {
          messageEl.textContent = 'Trade executado com sucesso.';
          messageEl.className = 'alert alert-success';
        }
        if (balanceEl && data.balance_brl !== undefined) {
          balanceEl.textContent = formatBrl(data.balance_brl);
        }
        await updateDetail();
      } catch (err) {
        if (messageEl) {
          messageEl.textContent = `Erro: ${err.message}`;
          messageEl.className = 'alert alert-error';
        }
      }
    });

    await updateDetail();
  };

  const renderPositions = async () => {
    const container = document.querySelector('[data-positions]');
    if (!container) return;

    container.innerHTML = '<p>Carregando posições...</p>';
    try {
      const res = await fetch('/api/prediction_positions.php');
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Erro ao carregar posições');
      }
      const positions = data.positions || [];
      if (!positions.length) {
        container.innerHTML = '<p>Você ainda não possui posições.</p>';
        return;
      }
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Mercado</th>
              <th>Status</th>
              <th>YES</th>
              <th>NO</th>
              <th>Preço YES</th>
            </tr>
          </thead>
          <tbody>
            ${positions.map(pos => `
              <tr>
                <td><a href="/mercadoPreditivo/public/market.php?slug=${encodeURIComponent(pos.slug)}">${pos.market_title}</a></td>
                <td>${pos.status}</td>
                <td>${formatNumber(pos.shares_yes, 4)}</td>
                <td>${formatNumber(pos.shares_no, 4)}</td>
                <td>${formatNumber(pos.price_yes, 4)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } catch (err) {
      container.innerHTML = `<p>Erro ao carregar posições: ${err.message}</p>`;
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
    renderMarkets();
    renderMarketDetail();
    renderPositions();
  });
})();
