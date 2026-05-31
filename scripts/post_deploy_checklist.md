# Checklist pos-deploy

## Conectividade

- [ ] `https://gateway.tars.art.br` carrega
- [ ] `https://gateway.tars.art.br/admin` abre
- [ ] HTTPS esta ativo

## Exposicao de arquivos

- [ ] `.env` nao e acessivel via web
- [ ] `/app` nao e acessivel
- [ ] `/config` nao e acessivel
- [ ] `/database` nao e acessivel
- [ ] `/storage` nao e acessivel
- [ ] `/cron` nao e acessivel

## Banco e fila

- [ ] criar projeto de teste na VPS com `php scripts/create_test_project.php`
- [ ] executar `bash scripts/smoke_test.sh` apontando para `gateway.tars.art.br`
- [ ] executar `php cron/processar_fila.php`
- [ ] executar `php scripts/check_queue.php`

## Painel

- [ ] validar login admin
- [ ] validar logout admin
- [ ] validar bloqueio CSRF em POST administrativo

## Validações extras

- [ ] verificar projeto inativo
- [ ] verificar telefone em `tn_optouts`
- [ ] verificar limites por projeto
- [ ] confirmar que o provider segue em mock
