# Release Notes

## v0.4-operacionalizacao-do-gateway

Etapa de operacao interna do gateway Tars Notificacoes, ainda em modo mock/log.

### O que foi adicionado

- gestao de API keys no painel
- regeneracao de API key com exibicao unica da nova chave
- desativacao/ativacao de projetos
- `last_used_at` atualizado quando a API autentica com sucesso
- `minute_limit` por projeto
- endpoint `GET /api/sms/status/{id}`
- endpoint `GET /health`
- filtros e detalhe de mensagens no painel
- log administrativo das acoes de chave/projeto

### Observacao

Nenhum SMS real deve ser enviado nesta fase. O envio real continua bloqueado por configuracao.

## v0.3.1-correcao-contrato-type

Correção de contrato da API para o gateway Tars Notificacoes.

### O que mudou

- `type=transactional` passou a ser aceito
- `type=alert` passou a ser aceito
- `type=test` passou a ser aceito
- `type` inválido continua retornando `422`
- a mensagem de erro agora informa os tipos aceitos
- os testes e scripts de validação foram alinhados ao novo contrato

### Observacao

Esta é uma correção da v0.3 homologada publicamente, não a v0.4.

## v0.3.2-autoteste-administrativo-validado

Validação do autoteste administrativo interno do próprio gateway, em modo mock/log, usando `POST /admin/tars-notificacoes/test`.

### O que foi validado

- cliente interno `TarsNotificationsClient`
- retorno `202` em envio novo
- retorno `200` em reenvio idempotente
- captura de `message_id`
- processamento da fila até `sent_mock`
- log local estruturado sem exposição de API key

### Observacao de nomenclatura

Esta etapa não é `v0.4`. A próxima etapa externa fica reservada como `v0.4-integracao-projeto-externo-mock`.

## v0.3-homologada-publica

Release homologada publicamente em `https://gateway.tars.art.br`.

### Validacoes concluidas

- DNS publico validado
- HTTPS Let's Encrypt validado
- API validada com 25 testes
- edge cases aprovados
- painel e CSRF aprovados
- fila mock aprovada
- diretorios sensiveis nao expostos
- travas de envio real ativas

### Configuracao homologada

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

### Observacao

O ambiente segue em modo mock/log e nenhum SMS real deve ser enviado nesta fase.

### Pendencia externa

O certificado legado de `rodrigo.tars.art.br` afeta `certbot renew --dry-run` global no mesmo servidor, mas nao afeta o certificado de `gateway.tars.art.br`.
