# Nota de segurança (versão vitrine)

## O que foi removido/substituído
Esta versão é sanitizada. **Não** fazem parte do repositório: arquivos `.env` e variações (produção e teste), cópias de configuração de servidor, configuração real do webhook, pacotes de deploy (`.zip`), `vendor/`, `composer.phar`, documentação operacional interna, dumps e dados de bilhetes/passageiros (dado pessoal — LGPD). Nomes de bancos, hosts, IPs, códigos de agência e logotipos foram trocados por placeholders.

Todos os segredos (banco, GATE, credenciais da Furk, `JWT_SECRET`, token do webhook) são lidos **somente** de variáveis de ambiente ou de um arquivo de configuração **fora da pasta pública**.

## Controles presentes no código
- **JWT HS256** com algoritmo fixo, validação de `iss`, `exp` obrigatório e `sub`/`role` exigidos.
- **Autorização por perfil de usuário** em todas as operações.
- **Senhas** com `password_hash`/`password_verify`.
- **Bloqueio de força bruta** por e-mail e por IP (`LoginThrottle`).
- **SQL** com *prepared statements*; identificadores dinâmicos validados por regex.
- **Webhook**: chamadas autenticadas e configuração fora do docroot.
- **TLS**: verificação de certificado habilitada nos clientes HTTP (`VERIFYPEER=true`, `VERIFYHOST=2`).
- **Cabeçalhos**: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`; CORS desligado por padrão.
- **Erros**: `display_errors` apenas com `APP_DEBUG=true`.

## Recomendações para quem for implantar
Servir somente por HTTPS (HSTS), adicionar `Content-Security-Policy`, considerar *cookie* `HttpOnly` no lugar de `localStorage` para o token, usar `JWT_SECRET` com no mínimo 32 caracteres, manter `.env` e a configuração do webhook fora da raiz web e rotacionar periodicamente credenciais e segredos.

## Escopo da revisão
A revisão desta vitrine foi **estática** (leitura de código e busca de segredos), feita com o Claude Sonnet 5.5. Não houve teste de invasão nem auditoria de dependências. Execute `composer audit` e um teste de segurança antes de qualquer exposição pública da aplicação real.

## Reportar vulnerabilidade
Envie os detalhes de forma privada para o contato do projeto, sem abrir *issue* pública.
