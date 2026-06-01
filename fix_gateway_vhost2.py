from pathlib import Path
path = Path('/etc/httpd/conf/httpd.conf')
lines = path.read_text().splitlines()
out = []
in_gateway = False
for line in lines:
    stripped = line.strip()
    if stripped.startswith('ServerName gateway.tars.art.br'):
        in_gateway = True
    elif stripped == '</VirtualHost>' and in_gateway:
        in_gateway = False
    if in_gateway:
        if 'DocumentRoot /home/gateway/public_html' in line:
            line = line.replace('/home/gateway/public_html', '/var/www/tars-notificacoes/public_html')
        if '<Directory /home/gateway/public_html>' in line:
            line = line.replace('/home/gateway/public_html', '/var/www/tars-notificacoes/public_html')
        if 'AllowOverride All Options=ExecCGI,Includes,IncludesNOEXEC,Indexes,MultiViews,SymLinksIfOwnerMatch' in line:
            line = '        AllowOverride All'
        if stripped.startswith('AliasMatch ^/api/sms/send$') or stripped.startswith('AliasMatch ^/admin(?:/.*)?$'):
            continue
        if stripped.startswith('FallbackResource /index.php'):
            continue
    out.append(line)
path.write_text('\n'.join(out) + '\n')