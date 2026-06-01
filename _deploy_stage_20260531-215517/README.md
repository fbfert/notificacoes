# Tars Notificacoes

Base inicial de uma central de notificacoes focada em SMS, com API JSON, fila em banco, provider mock e painel administrativo simples.

## Stack

- PHP 8.2+
- MySQL ou MariaDB
- Apache
- Sem Laravel e sem dependencias pesadas

## Estrutura

- `app/` - aplicacao
- `config/` - configuracoes
- `database/` - schema SQL
- `cron/` - rotinas CLI
- `public_html/` - unico diretorio publico
- `storage/logs/` - logs

## Homologacao em gateway.tars.art.br

Para instalar em homologacao na VPS, siga o guia completo em [DEPLOY.md](DEPLOY.md).

## Instalacao local

1. Crie o banco:

```sql
CREATE DATABASE tars_notificacoes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Importe o schema:

```bash
mysql -u root -p tars_notificacoes < database/schema.sql
```

3. Copie o ambiente:

```bash
cp .env.example .env
```

4. Ajuste as credenciais do banco e a senha do painel em `.env`.

5. Aponte o `DocumentRoot` do Apache para `public_html/`.

No Windows, use `copy .env.example .env` no lugar de `cp`.

## Apache

Exemplo de VirtualHost:

```apache
<VirtualHost *:80>
    ServerName tars-notificacoes.local
    DocumentRoot "C:/Dropbox/_programacao/Notificacoes/public_html"

    <Directory "C:/Dropbox/_programacao/Notificacoes/public_html">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Ative `mod_rewrite` e reinicie o Apache.

Os diretórios `app/`, `config/`, `database/`, `cron/` e `storage/` possuem `.htaccess` bloqueando acesso direto caso o projeto seja copiado com a estrutura errada.

## Regras de telefone

- O telefone aceita entrada com espaços, parênteses, hífen e `+`.
- A normalizacao remove tudo que nao for digito.
- O formato final armazenado e `55DDDNXXXXXXXX` ou `55DDDNNNNNNNN` conforme o caso.
- Se o numero nao for claramente brasileiro valido, a requisicao e bloqueada.

## Limites

- `daily_limit = NULL` significa ilimitado.
- `monthly_limit = NULL` significa ilimitado.
- `daily_limit = 0` bloqueia todas as tentativas.
- `monthly_limit = 0` bloqueia todas as tentativas.

## API

Endpoint:

- `POST /api/sms/send`

Headers:

- `Authorization: Bearer <api_key_do_projeto>`
- `Content-Type: application/json`

Payload:

```json
{
  "phone": "(11) 99999-9999",
  "message": "Sua mensagem aqui",
  "type": "sms",
  "idempotency_key": "pedido-123"
}
```

Regras:

- apenas SMS nesta etapa
- limite de 160 caracteres
- provider real nao esta habilitado
- toda mensagem e salva no banco
- toda tentativa gera log
- API key do projeto e armazenada apenas como hash

## Criacao de projetos

O painel administrativo permite cadastrar projetos, mas a `api_key` precisa ser informada manualmente e nao e exibida depois de salva. Isso evita expor segredos no painel.

Campos do projeto:

- `name`
- `slug`
- `api_key`
- `daily_limit`
- `monthly_limit`
- `max_attempts`

## Painel administrativo

Protecao simples por `ADMIN_PASSWORD` no `.env`.

- Se `ADMIN_PASSWORD` estiver vazio, o painel fica aberto e o login e bypassado.

Rotas:

- `GET /admin`
- `POST /admin/login`
- `POST /admin/logout`
- `GET /admin/projects`
- `POST /admin/projects`
- `GET /admin/messages`

O painel usa sessao segura, `CSRF` nos formulários e nao exibe a `api_key` de projetos.

## Fila

Processamento via CLI:

```bash
php cron/processar_fila.php
```

O cron faz claim transacional do registro, muda o status para `processing` e evita processar a mesma mensagem em duas execucoes simultaneas.

## Travas de seguranca de envio

Para homologacao em `gateway.tars.art.br`, o ambiente deve subir inicialmente com:

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

Com essas travas:

- qualquer tentativa de envio real permanece bloqueada
- somente numeros listados em `SMS_ALLOWED_TEST_PHONES` sao aceitos quando `SMS_TEST_ONLY=true`
- se `SMS_ALLOWED_TEST_PHONES` estiver vazio com `SMS_TEST_ONLY=true`, o envio e bloqueado
- `SMS_DRIVER` ou `SMS_PROVIDER` diferentes de `mock` nao ativam envio real enquanto `SMS_ALLOW_REAL_SEND=false`

## Testes manuais com curl

Assuma que exista um projeto ativo com uma `api_key` valida.

### 1. API key ausente

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Content-Type: application/json" \
  -d '{"phone":"5549999999999","message":"Teste Tars Notificações","type":"transactional"}'
```

### 2. API key invalida

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Authorization: Bearer invalida" \
  -H "Content-Type: application/json" \
  -d '{"phone":"5549999999999","message":"Teste Tars Notificações","type":"transactional"}'
```

### 2b. Projeto inativo

Marque `active = 0` no projeto e repita um envio valido. A resposta deve vir com `project_inactive`.

### 3. Telefone invalido

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Authorization: Bearer SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"phone":"abc","message":"Teste Tars Notificações","type":"transactional"}'
```

### 4. Mensagem acima de 160 caracteres

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Authorization: Bearer SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"phone":"5549999999999","message":"ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ","type":"transactional"}'
```

### 5. Envio valido

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Authorization: Bearer SUA_CHAVE_DE_TESTE" \
  -H "Content-Type: application/json" \
  -d '{"phone":"5549999999999","message":"Teste Tars Notificações","type":"transactional"}'
```

### 6. Reenvio com idempotency_key

```bash
curl -X POST https://gateway.tars.art.br/api/sms/send \
  -H "Authorization: Bearer SUA_CHAVE_DE_TESTE" \
  -H "Content-Type: application/json" \
  -d '{"phone":"5549999999999","message":"Teste Tars Notificações","type":"transactional","idempotency_key":"pedido-123"}'
```

Rode a mesma requisicao novamente com o mesmo `idempotency_key` e a API deve retornar a mensagem ja criada sem duplicar registro.

### 7. Processamento da fila

```bash
php cron/processar_fila.php
```

### 8. Verificacao no painel

- Abra `https://gateway.tars.art.br/admin`
- Entre com `ADMIN_PASSWORD`
- Verifique projetos e mensagens
- Confirme que a `api_key` nao aparece em nenhum lugar do painel

## Validacao da v0.2

Use esta sequencia antes de subir para homologacao na VPS:

1. Criar um projeto de teste:

```bash
php scripts/create_test_project.php
```

2. Executar a fila mock:

```bash
php cron/processar_fila.php
```

3. Rodar o smoke test da API:

```bash
bash scripts/smoke_test.sh
```

4. Validar as travas de seguranca:

```bash
php scripts/security_v021_tests.php
```

5. Conferir a fila e os status:

```bash
php scripts/check_queue.php
```

6. Validar cenarios estendidos:

```bash
bash scripts/v02_edge_cases.sh
bash scripts/panel_smoke.sh
```

### Variaveis do smoke test

Edite no topo de `scripts/smoke_test.sh` ou exporte antes de executar:

- `BASE_URL=https://gateway.tars.art.br`
- `API_KEY`
- `PHONE_VALIDO`
- `PHONE_INVALIDO`

Os scripts `scripts/v02_edge_cases.sh` e `scripts/panel_smoke.sh` cobrem os cenarios de projeto inativo, opt-out, limites, login/logout e CSRF do painel.

### Respostas HTTP esperadas

- Sem `Authorization`: `401`
- `Authorization` invalido: `401`
- Sem `Content-Type: application/json`: `415`
- JSON invalido: `400`
- `phone` ausente: `422`
- `phone` invalido: `422`
- `message` ausente: `422`
- `message` vazia: `422`
- `message` maior que 160 caracteres: `422`
- `type` invalido: `422`
- envio valido: `202`
- reenvio com a mesma `idempotency_key`: `200`

## Observacoes

- O provider real de SMS nao foi implementado.
- WhatsApp nao foi implementado.
- Telegram nao foi implementado.
- Redis, RabbitMQ, Node.js e Docker nao sao usados nesta etapa.
