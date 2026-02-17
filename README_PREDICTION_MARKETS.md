# Mercados preditivos — módulo canônico (2026)

## Decisão oficial
A base oficial para evolução do produto é:
- Endpoints canônicos: `api/markets/*`
- Serviço canônico: `lib/market_service.php`
- Contrato principal: campos `yes/no` no JSON (com alias `SIM/NAO` durante transição)

Os endpoints `api/prediction_*`, `api/admin_prediction_*`, `api/resolve_prediction_market.php` e `mercadoPreditivo/api/*` ficam em **compatibilidade temporária**.

## Matriz de equivalência (canônico vs legado)

| Ação | Canônico | Compatível legado | Status |
|---|---|---|---|
| Listar mercados | `GET /api/markets/list.php` | `GET /api/prediction_markets.php`, `GET /api/prediction_markets.php?mode=book` | Deprecado |
| Detalhe de mercado | `GET /api/markets/get.php?id={id}` | `GET /mercadoPreditivo/api/get_market.php?id={id}` | Deprecado |
| Simular cotação | `GET /api/markets/quote.php?market_id={id}&side=yes|no&shares={n}` | `POST /api/prediction_quote.php` | Deprecado |
| Comprar posição | `POST /api/markets/buy.php` | `POST /api/prediction_trade.php`, `POST /mercadoPreditivo/api/buy_shares.php` | Deprecado |
| Criar mercado (admin) | `POST /api/markets/create.php` | `POST /api/admin_prediction_market_create.php` | Deprecado |
| Resolver mercado (admin) | `POST /api/markets/resolve.php` | `POST /api/resolve_prediction_market.php` | Deprecado |
| Cancelamento administrativo | transição para `POST /api/markets/resolve.php` com política de encerramento | `POST /api/admin_prediction_market_cancel.php` | Deprecado |

## Compatibilidade e deprecation
Os endpoints legados agora retornam sinais de depreciação:
- Header `Deprecation: true`
- Header `Sunset: 2026-12-31T23:59:59Z`
- Header `Link: </api/markets/...>; rel="successor-version"`
- Header `Warning: 299 ...`

## Contrato de dados unificado
### Entrada (canônica)
```json
{ "side": "yes|no" }
```

### Entrada (compatível durante transição)
Também aceitamos `SIM/NAO`.

### Saída
Endpoints canônicos passam a incluir aliases para migração:
- preço: `p_sim/p_nao` **e** `price_yes/price_no`
- liquidez: `q_sim/q_nao` **e** `q_yes/q_no`
- lado: `side` (`yes|no`) **e** `side_legacy` (`SIM|NAO`)

## Plano de migração de dados e remoção de código morto
1. **Janela de transição (até 2026-12-31)**
   - Front e integrações externas usam apenas `api/markets/*`.
   - Endpoints legados continuam respondendo com deprecation headers.
2. **Backfill/auditoria**
   - Validar equivalência de preço e posição entre respostas antigas e canônicas.
   - Congelar criação de novos consumidores do namespace legado.
3. **Cutover**
   - Remover chamadas legadas do front.
   - Encerrar escrita nas tabelas antigas do fluxo `prediction_*`.
4. **Limpeza final**
   - Remover endpoints legados e serviços não referenciados.
   - Manter apenas `api/markets/*` + `lib/market_service.php`.
