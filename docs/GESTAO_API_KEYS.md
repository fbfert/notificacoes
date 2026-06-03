# Gestao de API keys

O painel administrativo do gateway permite gerenciar projetos e suas chaves de API sem expor segredos.

## Onde fica

- `GET /admin/projects`

## O que e possivel fazer

- cadastrar projetos
- regenerar API key
- desativar ou reativar projeto
- visualizar `last_used_at`
- visualizar limites por projeto, incluindo `minute_limit`

## Regeneracao de chave

Ao regenerar uma API key:

- a chave antiga deixa de ser valida
- apenas o hash novo fica salvo no banco
- a nova chave e exibida somente uma vez na tela
- nenhuma chave antiga e mostrada novamente

### Fluxo

1. Abra o painel em `https://gateway.tars.art.br/admin`
2. Acesse `Projetos`
3. Clique em `Regenerar chave`
4. Copie a nova API key imediatamente
5. Nao existe recuperacao posterior da chave

## Desativacao e ativacao

- `Desativar` impede novas autenticacoes daquele projeto
- `Ativar` reabilita o projeto

## last_used_at

O campo `last_used_at` e atualizado quando a API autentica com sucesso, inclusive em:

- `POST /api/sms/send`
- `GET /api/sms/status/{id}`

Autenticacoes invalidas nao atualizam esse campo.

## minute_limit

O campo `minute_limit` controla o numero maximo de mensagens aceitas por minuto:

- `NULL` = ilimitado
- `0` = bloqueia todas as tentativas
- `> 0` = limite maximo por minuto

Quando o limite e ultrapassado, a mensagem e registrada como `blocked` e logada.

## Log administrativo

As acoes do painel sao registradas em `tn_sms_logs` com entradas administrativas, sem expor API keys.

## Configuracao segura

O ambiente continua operando com:

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

Nenhum SMS real deve ser enviado nesta etapa.
