# Proxima Etapa v0.5-kit-integracao-clientes

Esta pagina organiza a proxima evolucao do Tars Notificacoes apos a homologacao publica.

## Objetivo

Criar um kit oficial de integracao para projetos clientes consumirem o gateway em modo mock/log, com exemplos, guia e smoke test externo.

## Checklist da v0.5-kit-integracao-clientes

1. Publicar cliente PHP de referencia.
2. Publicar exemplo cURL.
3. Publicar exemplo JavaScript/fetch para backend.
4. Publicar smoke test externo.
5. Publicar guia de integracao.
6. Publicar checklist de integracao.
7. Manter o gateway em mock/log.
8. Confirmar que nenhum SMS real e enviado.

## Requisitos de seguranca

- manter `SMS_DRIVER=mock`
- manter `SMS_PROVIDER=mock`
- manter `SMS_ALLOW_REAL_SEND=false`
- manter `SMS_TEST_ONLY=true`

## Critérios de sucesso

- o kit de integracao documenta o fluxo correto para clientes
- os exemplos usam `type=test` por padrao
- o smoke externo valida `202`, `200`, `401`, `415`, `422` e `GET /api/sms/status/{id}`
- a documentacao reforca que a operacao continua em mock/log
- nenhum exemplo expõe API key
