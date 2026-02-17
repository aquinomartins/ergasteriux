# Segurança de Credenciais

## Ação imediata (manual)
As credenciais anteriormente versionadas devem ser tratadas como comprometidas.

1. Rotacione no provedor de banco de dados os usuários/senhas expostos.
2. Atualize o ambiente de deploy com os novos valores (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `MERCADO_PREDITIVO_USER_PASSWORD`).
3. Invalide/remova as credenciais antigas.
4. Reinicie a aplicação para recarregar variáveis de ambiente.

## Revisão de histórico git
Sugestão de auditoria local para identificar possíveis segredos em commits anteriores:

```bash
git log -p -- lib/db.php lib/config.php
```

Se houver exposição adicional, reescreva histórico com ferramenta apropriada (`git filter-repo`/BFG), force push e revogue os segredos antigos.

## Procedimentos de deploy para evitar reincidência
- Nunca salvar segredos no repositório.
- Gerenciar segredos apenas em variáveis de ambiente/cofre de segredos.
- Manter `.env` fora do controle de versão e versionar somente `.env.example`.
- Validar variáveis obrigatórias em bootstrap (falha explícita quando ausentes).
- Adotar scanner de segredos em CI (ex.: gitleaks/trufflehog).
