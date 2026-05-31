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

## Testes manuais com curl

Assuma que exista um projeto ativo com uma `api_key` valida.

### 1. API key ausente

```bash
curl -i -X POST http://localhost/api/sms/send -H "Content-Type: application/json" -d '{"phone":"11999999999","message":"Teste","type":"sms"}'
```

### 2. API key invalida

```bash
curl -i -X POST http://localhost/api/sms/send -H "Authorization: Bearer invalida" -H "Content-Type: application/json" -d '{"phone":"11999999999","message":"Teste","type":"sms"}'
```

### 2b. Projeto inativo

Marque `active = 0` no projeto e repita um envio valido. A resposta deve vir com `project_inactive`.

### 3. Telefone invalido

```bash
curl -i -X POST http://localhost/api/sms/send -H "Authorization: Bearer SUA_CHAVE" -H "Content-Type: application/json" -d '{"phone":"abc","message":"Teste","type":"sms"}'
```

### 4. Mensagem acima de 160 caracteres

```bash
curl -i -X POST http://localhost/api/sms/send -H "Authorization: Bearer SUA_CHAVE" -H "Content-Type: application/json" -d '{"phone":"11999999999","message":"ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ","type":"sms"}'
```

### 5. Envio valido

```bash
curl -i -X POST http://localhost/api/sms/send -H "Authorization: Bearer SUA_CHAVE" -H "Content-Type: application/json" -d '{"phone":"(11) 99999-9999","message":"Mensagem valida","type":"sms"}'
```

### 6. Reenvio com idempotency_key

```bash
curl -i -X POST http://localhost/api/sms/send -H "Authorization: Bearer SUA_CHAVE" -H "Content-Type: application/json" -d '{"phone":"(11) 99999-9999","message":"Mensagem valida","type":"sms","idempotency_key":"pedido-123"}'
```

Rode a mesma requisicao novamente com o mesmo `idempotency_key` e a API deve retornar a mensagem ja criada sem duplicar registro.

### 7. Processamento da fila

```bash
php cron/processar_fila.php
```

### 8. Verificacao no painel

- Abra `http://localhost/admin`
- Entre com `ADMIN_PASSWORD`
- Verifique projetos e mensagens
- Confirme que a `api_key` nao aparece em nenhum lugar do painel

## Observacoes

- O provider real de SMS nao foi implementado.
- WhatsApp nao foi implementado.
- Telegram nao foi implementado.
- Redis, RabbitMQ, Node.js e Docker nao sao usados nesta etapa.
