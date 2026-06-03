# Integracao Tars Notificacoes v0.3.2-autoteste-administrativo-validado

Esta documentacao descreve o autoteste administrativo interno validado do gateway Tars Notificacoes, em modo mock/log.

## Variaveis de ambiente

Configure no `.env` do projeto cliente:

```env
TARS_NOTIFICACOES_ENABLED=false
TARS_NOTIFICACOES_BASE_URL=https://gateway.tars.art.br
TARS_NOTIFICACOES_API_KEY=
TARS_NOTIFICACOES_TEST_PHONE=5549999999999
TARS_NOTIFICACOES_TIMEOUT=10
```

### Como configurar a API key

1. Crie um projeto no painel do gateway `https://gateway.tars.art.br/admin`.
2. Gere ou cadastre a `API key` do projeto.
3. Copie a chave para `TARS_NOTIFICACOES_API_KEY` no `.env` do projeto cliente.
4. Nunca exponha essa chave no frontend, em logs ou em commits.

## Como testar

1. Ajuste o `.env` do projeto cliente com a `API key`.
2. Defina `TARS_NOTIFICACOES_ENABLED=true`.
3. Abra o painel administrativo do projeto cliente.
4. Execute a ação administrativa de teste do gateway Tars Notificacoes.
   Essa ação usa `type=test`.

O envio usa a classe `TarsNotificationsClient` e chama:

- `POST /api/sms/send`
- `Authorization: Bearer API_KEY`
- `Content-Type: application/json`

Tipos aceitos no gateway:

- `transactional`
- `alert`
- `test`

## Respostas esperadas

- `202` envio aceito
- `200` requisicao idempotente
- `401` API key ausente ou invalida
- `415` Content-Type invalido
- `422` validacao
- `500` falha interna no gateway

### Observacao pratica

O contrato aceito pelo gateway para esta etapa contempla `transactional`, `alert` e `test`. O cliente administrativo usa `type=test` para o teste de integracao.

### Nomenclatura da etapa

Esta etapa corresponde a `v0.3.2-autoteste-administrativo-validado`.
`v0.4-integracao-projeto-externo-mock` fica reservada para a integracao de um projeto externo real.

## Como validar no painel do gateway

No painel do gateway:

1. Abra `https://gateway.tars.art.br/admin`
2. Verifique a fila e as mensagens recentes
3. Confirme que a mensagem de teste aparece como `sent_mock` quando o envio for aceito
4. Valide o `message_id`, o status e os logs do envio

## Como desativar rapidamente

Para desligar a integracao no projeto cliente, volte:

```env
TARS_NOTIFICACOES_ENABLED=false
```

Assim nenhuma tentativa de envio sera feita pela integracao administrativa.

## Sequencia manual de teste

1. Crie um projeto e uma API key no painel do gateway.
2. Configure `TARS_NOTIFICACOES_API_KEY` no `.env` do projeto cliente.
3. Altere `TARS_NOTIFICACOES_ENABLED=true`.
4. Execute a acao administrativa de teste.
5. Valide no retorno HTTP se veio `202` ou `200`.
6. Se necessario, rode o cron do gateway para processar a fila.
7. Abra o painel do gateway e confirme a mensagem como `sent_mock`.
8. Confirme que nenhum SMS real foi enviado.

## Observacao

Nesta fase o gateway continua em mock/log. Nenhum SMS real deve ser enviado.
