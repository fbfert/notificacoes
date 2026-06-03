# Homologacao em gateway.tars.art.br

Este guia prepara o Tars Notificacoes para homologacao em uma VPS AlmaLinux 9.8 com Apache, PHP 8.2+ e MySQL/MariaDB.

## Status da release

A release `v0.4-operacionalizacao-do-gateway` esta em uso publicamente em `https://gateway.tars.art.br`.

Configuracao homologada:

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

Nesta fase nenhum SMS real deve ser enviado.

## Atualizacao incremental do schema

Se o banco ja existir, aplique as alteracoes abaixo antes de subir a nova versao:

```sql
ALTER TABLE tn_projects
    ADD COLUMN minute_limit INT UNSIGNED DEFAULT NULL AFTER monthly_limit,
    ADD COLUMN last_used_at DATETIME DEFAULT NULL AFTER minute_limit,
    ADD KEY idx_tn_projects_last_used_at (last_used_at);

ALTER TABLE tn_sms_messages
    ADD COLUMN delivered_at DATETIME DEFAULT NULL AFTER sent_at,
    ADD COLUMN failed_at DATETIME DEFAULT NULL AFTER delivered_at,
    MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'transactional',
    ADD KEY idx_tn_sms_messages_type (type);
```

Se alguma coluna ou indice ja existir no banco destino, ajuste o comando antes de executar.

## 1. Subdominio e VirtualHost

Crie o subdominio `gateway.tars.art.br` no DNS apontando para o IP da VPS.

Exemplo de VirtualHost Apache:

```apache
<VirtualHost *:80>
    ServerName gateway.tars.art.br
    ServerAlias www.gateway.tars.art.br
    DocumentRoot /var/www/tars-notificacoes/public_html

    <Directory /var/www/tars-notificacoes/public_html>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog logs/gateway.tars.art.br-error.log
    CustomLog logs/gateway.tars.art.br-access.log combined
</VirtualHost>
```

Pontos obrigatorios:

- o `DocumentRoot` deve apontar somente para `public_html/`
- nunca aponte a raiz do projeto
- mantenha `AllowOverride All` para o `.htaccess`

## 2. HTTPS e Let's Encrypt

Depois de validar o VirtualHost HTTP, emita o certificado SSL:

```bash
sudo dnf install -y certbot python3-certbot-apache
sudo certbot --apache -d gateway.tars.art.br -d www.gateway.tars.art.br
```

Confirme que o redirecionamento para HTTPS esta ativo e que o navegador mostra conexao segura.

## 3. Estrutura de diretorios

Instale o projeto, por exemplo:

```bash
sudo mkdir -p /var/www/tars-notificacoes
sudo chown -R apache:apache /var/www/tars-notificacoes
```

Copie os arquivos para `/var/www/tars-notificacoes`.

Os diretorios `app/`, `config/`, `database/`, `cron/` e `storage/` nao devem ser acessiveis pela web. O `.htaccess` interno reforca isso, mas o `DocumentRoot` correto e a principal protecao.

## 4. Banco de dados

Crie o banco e o usuario:

```sql
CREATE DATABASE tars_notificacoes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tars_user'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT ALL PRIVILEGES ON tars_notificacoes.* TO 'tars_user'@'localhost';
FLUSH PRIVILEGES;
```

Importe o schema:

```bash
mysql -u root -p tars_notificacoes < /var/www/tars-notificacoes/database/schema.sql
```

## 5. Arquivo `.env`

Na VPS, crie `/var/www/tars-notificacoes/.env` a partir do exemplo.

Use valores reais da homologacao, mantendo o provider em mock:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://gateway.tars.art.br`
- `SMS_PROVIDER=mock`
- `SMS_DRIVER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`
- `SMS_ALLOWED_TEST_PHONES=` 
- `QUEUE_BATCH_SIZE=20`
- `QUEUE_MAX_ATTEMPTS=3`

### Travas de seguranca de envio

Na primeira subida da homologacao, mantenha obrigatoriamente:

- `SMS_DRIVER=mock`
- `SMS_PROVIDER=mock`
- `SMS_ALLOW_REAL_SEND=false`
- `SMS_TEST_ONLY=true`

Se `SMS_TEST_ONLY=true`, preencha `SMS_ALLOWED_TEST_PHONES` com os numeros liberados para teste, separados por virgula ou ponto e virgula. Sem essa lista, o envio e bloqueado por seguranca.

Nao versionar o `.env` real.

## 6. Permissoes

Garanta que `storage/` seja gravavel pelo usuario do Apache:

```bash
sudo chown -R apache:apache /var/www/tars-notificacoes/storage
sudo chmod -R 750 /var/www/tars-notificacoes/storage
```

Se precisar de logs no `storage/logs`, mantenha a escrita liberada apenas para o Apache.

## 7. Cron da fila

Adicione no crontab do usuario apropriado:

```cron
* * * * * /usr/bin/php /var/www/tars-notificacoes/cron/processar_fila.php >> /var/www/tars-notificacoes/storage/logs/cron.log 2>&1
```

Recomendacao:

- rode a cada minuto
- mantenha o `php` apontando para a versao 8.2+ correta
- monitore o `cron.log` durante a homologacao

## 8. Smoke tests no dominio real

Antes de liberar a homologacao, execute:

```bash
export BASE_URL=https://gateway.tars.art.br
export API_KEY=SUA_CHAVE_DE_TESTE
export PHONE_VALIDO='(11) 99999-9999'
export PHONE_INVALIDO='abc'

bash scripts/health_check.sh
bash scripts/smoke_test.sh
bash scripts/v02_edge_cases.sh
bash scripts/panel_smoke.sh
php scripts/v04_operational_tests.php
php cron/processar_fila.php
php scripts/check_queue.php
php scripts/security_v021_tests.php
```

Veja tambem os detalhes de integracao e da proxima etapa em:

- [docs/INTEGRACAO_CLIENTE.md](docs/INTEGRACAO_CLIENTE.md)
- [docs/PROXIMA_ETAPA_V04.md](docs/PROXIMA_ETAPA_V04.md)

## 9. Checklist de seguranca

- `https://gateway.tars.art.br` responde normalmente
- `.env` nao esta acessivel via web
- `/app`, `/config`, `/database`, `/storage` e `/cron` nao estao acessiveis
- o painel abre apenas em `/admin`
- a fila processa em modo mock
- nenhuma chave de API e exibida no painel

## 10. Rollback basico

Se algo falhar:

1. verifique o `error_log` do Apache
2. verifique `storage/logs`
3. confirme as credenciais do banco em `.env`
4. confirme o `DocumentRoot`
5. confirme se o cron esta chamando o PHP correto
