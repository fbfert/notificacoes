# Guia de Integracao do Cliente

Este guia orienta projetos clientes a consumir o Tars Notificacoes em modo mock/log, sem envio real de SMS.

## Visao geral

O gateway recebe mensagens via API JSON, enfileira no banco e processa tudo em modo mock. Nesta fase:

- nao existe envio real de SMS
- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

Use a integracao apenas para testes administrativos de baixo risco.

## Criar o projeto no painel do Gateway

1. Abra `https://gateway.tars.art.br/admin`
2. Autentique-se com `ADMIN_PASSWORD`
3. Abra `Projetos`
4. Crie o projeto cliente
5. Gere a API key
6. Copie a chave exibida apenas uma vez

## Como guardar a API key

Guarde a chave somente no `.env` do projeto cliente:

```env
TARS_NOTIFICACOES_ENABLED=false
TARS_NOTIFICACOES_BASE_URL=https://gateway.tars.art.br
TARS_NOTIFICACOES_API_KEY=SUA_CHAVE_AQUI
TARS_NOTIFICACOES_TEST_PHONE=5549999999999
TARS_NOTIFICACOES_TIMEOUT=10
```

Boas praticas:

- nunca coloque a API key no frontend
- nunca registre a API key em logs
- nunca comite a API key
- mantenha `TARS_NOTIFICACOES_ENABLED=false` por padrao

## Endpoint de envio

- `POST /api/sms/send`

Headers:

- `Authorization: Bearer API_KEY`
- `Content-Type: application/json`

Body:

```json
{
  "phone": "5549999999999",
  "message": "Teste de integracao do projeto cliente com o Tars Notificacoes.",
  "type": "test",
  "idempotency_key": "cliente-evento-123"
}
```

Tipos aceitos no gateway:

- `transactional`
- `alert`
- `test`

Use `type=test` por padrao para o kit e para exemplos.

## Endpoint de status

- `GET /api/sms/status/{id}`

O endpoint retorna apenas mensagens do projeto autenticado.

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

Se a mensagem nao pertencer ao projeto, a resposta deve ser `404`.

## Respostas HTTP esperadas

- `202` envio novo aceito
- `200` reenvio idempotente
- `401` API key ausente ou invalida
- `415` Content-Type invalido
- `422` validacao de payload ou contrato
- `500` falha interna

## idempotency_key

Sempre use `idempotency_key` para evitar duplicacao acidental.

Recomendacoes:

- prefixo do projeto
- nome do evento
- timestamp ou hash controlado

## Como validar sent_mock no painel

1. Envie a requisicao com sucesso
2. Abra o painel do gateway
3. Verifique a fila
4. Confirme a mensagem como `sent_mock`
5. Confirme o `message_id` e os logs

## Como desativar rapidamente

Para desligar a integracao no projeto cliente:

```env
TARS_NOTIFICACOES_ENABLED=false
```

## Exemplo de uso

Consulte:

- `examples/clientes/php/TarsNotificationsClient.php`
- `examples/clientes/php/exemplo_envio_teste.php`
- `examples/clientes/curl/enviar_teste.sh`
- `examples/clientes/javascript/enviar_teste.js`

## Observacao

Todo o fluxo desta etapa continua em mock/log. Nenhum SMS real deve ser enviado.
