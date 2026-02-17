# Ergastério 2026

Documentação central do Ergastério, consolidando a mecânica principal do sistema e apontando para módulos específicos quando necessário.

## Mecânica unificada do Ergastério

- **Usuários**
  - Conjunto de agentes individuais (participantes/atores) que interagem entre si e com os ativos do sistema.

- **Ações**
  - Mintar NFTs.
  - Excluir NFTs.
  - Trocar NFTs.
  - Negociar NFTs.
  - Negociar BTC.
  - Prover ou retirar liquidez.
  - Operar moldura/chassi/galeria.
  - Participar de mercados preditivos.
  - Formar coalizões.
  - Estabelecer parcerias.

- **Payoff**
  - **R$** é a moeda universal de troca no sistema.

## Rotas e F5 (SPA fallback)

Ao usar navegação client-side em uma SPA, atualizar a página (`F5`) em rotas “bonitas” como `/mercados-lmsr` pode causar erro no servidor se o Apache tentar buscar um arquivo físico com esse nome.

Para evitar isso, o projeto agora inclui um `.htaccess` na raiz com fallback para `index.html`:

- Se `REQUEST_FILENAME` já existir (arquivo/pasta real), não reescreve.
- Se a rota começar por `api/`, `auth/`, `uploads/`, `db/`, `lib/`, `tools/`, `views/`, `cmss/`, `mechanics/`, `mercadoPreditivo/` ou `mercados_lmsr/`, não reescreve.
- Qualquer outra rota é redirecionada internamente para `index.html`.

No front-end (`app.js`), o roteador também lê `window.location.pathname` no carregamento inicial e trata `popstate` (voltar/avançar), mantendo a interceptação de cliques via `data-view`.

### Deploy e debug rápido

- Garanta que o `.htaccess` esteja no mesmo diretório do `index.html` no servidor.
- Se o app estiver em subdiretório, habilite `RewriteBase /` conforme necessário no próprio `.htaccess`.
- Para debugar problemas de rota:
  - Verifique o `error_log` do Apache (rewrite/500).
  - No navegador, abra a aba **Network** e confira se `/api/*` retorna JSON (e não HTML da SPA).

## Auth flow

- `GET /api/session.php`: retorna sempre JSON no formato `{ logged, user, csrf_token, ... }`, onde `user` inclui `id`, `name`, `email` e `avatar_url` (quando autenticado).
- `POST /auth/login.php`: recebe JSON `{ "email": "...", "password": "..." }`. Em sucesso, inicializa sessão PHP regenerando ID e retorna `{ ok:true, user_id, name, email, avatar_url, csrf_token }`.
- `POST /auth/logout.php`: encerra a sessão atual, limpa `$_SESSION`, invalida cookie da sessão e retorna `{ ok:true }`.
- Para login/logout, há modo CSRF compatível: se `X-CSRF-Token` for enviado, ele é validado; se não for enviado, a chamada segue funcionando por compatibilidade.
