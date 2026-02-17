// assets/market.js
// Atualiza dados do mercado e envia compras via fetch.

const priceYesEl = document.getElementById('price-yes');
const priceNoEl = document.getElementById('price-no');
const positionYesEl = document.getElementById('position-yes');
const positionNoEl = document.getElementById('position-no');
const balanceEl = document.getElementById('user-balance');
const statusEl = document.getElementById('market-status');
const buyForm = document.getElementById('buy-form');
const buyMessage = document.getElementById('buy-message');
const buyButton = document.getElementById('buy-button');

function formatNumber(value, decimals = 4) {
    const number = Number(value) || 0;
    return number.toLocaleString('pt-BR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

async function fetchMarket() {
    try {
        const response = await fetch(`/api/get_market.php?id=${window.MARKET_ID}`);
        const data = await response.json();

        if (data.prices) {
            priceYesEl.textContent = formatNumber(data.prices.yes);
            priceNoEl.textContent = formatNumber(data.prices.no);
        }

        if (data.position) {
            positionYesEl.textContent = formatNumber(data.position.yes);
            positionNoEl.textContent = formatNumber(data.position.no);
        }

        if (data.balance && balanceEl) {
            balanceEl.textContent = Number(data.balance).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        if (data.market && statusEl) {
            statusEl.textContent = data.market.status;
        }
    } catch (error) {
        console.error('Erro ao buscar mercado', error);
    }
}

async function buyShares(event) {
    event.preventDefault();

    if (!buyForm || !buyButton) {
        return;
    }

    buyButton.disabled = true;
    buyMessage.hidden = true;

    const formData = new FormData(buyForm);

    try {
        const response = await fetch('/api/buy_shares.php', {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Erro ao comprar shares.');
        }

        buyMessage.textContent = 'Compra realizada com sucesso!';
        buyMessage.className = 'alert alert-success';
        buyMessage.hidden = false;
        buyForm.reset();

        if (data.prices) {
            priceYesEl.textContent = formatNumber(data.prices.yes);
            priceNoEl.textContent = formatNumber(data.prices.no);
        }
        if (data.position) {
            positionYesEl.textContent = formatNumber(data.position.yes);
            positionNoEl.textContent = formatNumber(data.position.no);
        }
        if (data.balance && balanceEl) {
            balanceEl.textContent = Number(data.balance).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }
    } catch (error) {
        buyMessage.textContent = error.message;
        buyMessage.className = 'alert alert-error';
        buyMessage.hidden = false;
    } finally {
        buyButton.disabled = false;
    }
}

if (buyForm) {
    buyForm.addEventListener('submit', buyShares);
}

fetchMarket();
setInterval(fetchMarket, 5000);
