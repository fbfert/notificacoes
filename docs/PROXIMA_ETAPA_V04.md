# Proxima Etapa v0.5-integracao-projeto-externo-mock

Esta pagina organiza a proxima evolucao do Tars Notificacoes apos a homologacao publica.

## Objetivo

Integrar um projeto real em modo mock, validar o fluxo completo de envio via API e manter o sistema sem envio real.

## Checklist da v0.5-integracao-projeto-externo-mock

1. Escolher um projeto real para integrar em modo mock.
2. Criar o projeto e a API key no painel do Tars Notificacoes.
3. Configurar o projeto cliente com a API key.
4. Criar uma funcao cliente para envio de SMS.
5. Testar envio valido.
6. Testar idempotencia.
7. Testar bloqueio por telefone fora da allowlist.
8. Validar logs no painel.
9. Validar o cron e a fila em mock.
10. Confirmar que nenhum SMS real e enviado.

## Requisitos de seguranca

- manter `SMS_DRIVER=mock`
- manter `SMS_PROVIDER=mock`
- manter `SMS_ALLOW_REAL_SEND=false`
- manter `SMS_TEST_ONLY=true`

## Critérios de sucesso

- o cliente consegue enviar mensagens para o gateway
- a API responde com `202` para envio aceito
- a mesma `idempotency_key` retorna `200`
- numeros fora da allowlist sao bloqueados
- o painel mostra registros e logs corretamente
- a fila continua operando em mock/log
