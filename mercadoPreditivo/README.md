# Mercado Preditivo (ergasterio.com.br)

Este projeto é um exemplo de mercado de previsões com créditos virtuais, usando PHP 8+, MySQL e JavaScript puro.

## Configuração rápida

1. **Crie as tabelas do módulo**
   - Importe o arquivo `database.sql` no mesmo banco do app Ergastério (ou deixe a criação automática via `lib/db.php`).

2. **Conexão compartilhada**
   - O módulo usa a mesma conexão definida em `lib/db.php` do app principal.

3. **Rode o seed**
   - Execute `php seed.php` para criar um admin e mercados de exemplo.

4. **Acesse o sistema**
   - Site público: `http://localhost/public/index.php`
   - Login admin padrão (após rodar o seed):
     - Email: `admin@ergasterio.com.br`
     - Senha: `admin123`

## Fluxo de uso

1. **Usuário** cria conta e faz login.
2. **Mercados** são listados em `/public/index.php`.
3. **Usuário** compra shares na tela do mercado.
4. **Admin** cria/fecha/resolve mercados em `/admin/markets.php`.
5. Ao resolver, o sistema paga 1 crédito por share vencedora e registra em `mp_transactions`.

## Lógica de preço (AMM simplificado)

- Cada mercado mantém duas reservas: `liquidity_yes` e `liquidity_no`.
- O preço de cada lado é calculado assim:
  - `price_yes = liquidity_no / (liquidity_yes + liquidity_no)`
  - `price_no = liquidity_yes / (liquidity_yes + liquidity_no)`
- Quando alguém compra `YES`, aumenta `liquidity_yes`, fazendo o preço do `YES` subir e do `NO` cair.
- O modelo é simples e serve apenas para dinâmica básica de oferta/demanda.