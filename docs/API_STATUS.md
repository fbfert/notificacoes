# API de status e saude

O gateway Tars Notificacoes expõe dois pontos de consulta operacional:

- `GET /api/sms/status/{id}`
- `GET /health`

## GET /api/sms/status/{id}

Consulta o status de uma mensagem pertencente ao projeto autenticado.

### Regras

- exige `Authorization: Bearer <API_KEY>`
- o projeto só pode consultar mensagens dele mesmo
- se a mensagem nao pertencer ao projeto, retorna `404`
- nao retorna dados sensiveis

### Resposta

Campos retornados:

- `message_id`
- `status`
- `type`
- `provider`
- `created_at`
- `sent_at`
- `delivered_at`
- `failed_at`
- `error_message`

### Exemplo

```bash
curl -X GET https://gateway.tars.art.br/api/sms/status/123 \
  -H "Authorization: Bearer SUA_CHAVE_DE_TESTE" \
  -H "Content-Type: application/json"
```

### Respostas esperadas

- `200` quando a mensagem pertence ao projeto autenticado
- `404` quando a mensagem nao existe ou pertence a outro projeto
- `401` quando a API key estiver ausente ou invalida

## GET /health

Retorna um JSON simples para verificacao da saude do gateway.

Campos principais:

- `success`
- `app`
- `env`
- `timestamp`
- `queue_status`
- `sms_driver`
- `allow_real_send`

### Regras de seguranca

- nao retorna senha
- nao retorna API key
- nao retorna credenciais do banco
- nao retorna detalhes sensiveis do servidor

### Exemplo

```bash
curl -X GET https://gateway.tars.art.br/health
```

## Operacao

Os tipos aceitos continuam sendo:

- `transactional`
- `alert`
- `test`

O envio real permanece bloqueado com:

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`
