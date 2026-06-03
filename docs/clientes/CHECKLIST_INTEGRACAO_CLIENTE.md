# Checklist de Integracao do Cliente

Use esta lista antes de ligar um projeto cliente ao gateway.

- [ ] Projeto criado no Gateway
- [ ] API key gerada no painel
- [ ] API key armazenada apenas no `.env`
- [ ] `TARS_NOTIFICACOES_ENABLED=false` por padrao
- [ ] Telefone de teste incluido na allowlist do Gateway
- [ ] Rota administrativa protegida criada no projeto cliente
- [ ] Envio de teste retorna `202`
- [ ] Reenvio idempotente retorna `200`
- [ ] Status endpoint retorna `200`
- [ ] Mensagem vira `sent_mock` no painel
- [ ] API key nao aparece em log
- [ ] Nenhum SMS real foi enviado

## Observacoes

- Use sempre `type=test` nos exemplos e testes iniciais
- Nao exponha a API key no frontend
- Nao use a integracao em login, recuperacao de senha, OTP ou pagamento
- Mantenha o gateway em mock/log enquanto a etapa externa nao for iniciada
