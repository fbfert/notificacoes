# Release Notes

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
