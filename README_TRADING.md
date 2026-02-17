# Trading Add-on (multiusuário + P2P)

## Fluxo único oficial
O módulo de mercados binários passa a usar **apenas o namespace canônico** para evolução:
- `api/markets/*`
- `lib/market_service.php`

Para trading geral (livro de ordens), continuam válidos:
- `api/register.php`
- `api/orders.php`
- `api/trades.php`

## Endpoints de mercado recomendados
- `GET /api/markets/list.php`
- `GET /api/markets/get.php?id=...`
- `GET /api/markets/quote.php?market_id=...&side=yes|no&shares=...`
- `POST /api/markets/buy.php`
- `POST /api/markets/create.php` (admin)
- `POST /api/markets/resolve.php` (admin)
- `POST /api/markets/claim.php`

## Compatibilidade temporária
Endpoints antigos de prediction markets permanecem por transição, mas com headers de depreciação e sunset.

## Convenções de contrato JSON
- Canônico: `yes/no`
- Compatível na transição: `SIM/NAO`
- Respostas novas incluem aliases (`price_yes/price_no` e `p_sim/p_nao`) para facilitar migração gradual do front.
