from pathlib import Path
path = Path('/etc/httpd/conf/httpd.conf')
text = path.read_text()
lines = text.splitlines()
out = []
in_gateway = False
inserted_in_block = False
for line in lines:
    stripped = line.strip()
    if stripped.startswith('ServerName gateway.tars.art.br'):
        in_gateway = True
        inserted_in_block = False
    elif stripped == '</VirtualHost>' and in_gateway:
        in_gateway = False
    if in_gateway:
        if 'DocumentRoot /home/gateway/public_html' in line:
            line = line.replace('/home/gateway/public_html', '/var/www/tars-notificacoes/public_html')
        if '<Directory /home/gateway/public_html>' in line:
            line = line.replace('/home/gateway/public_html', '/var/www/tars-notificacoes/public_html')
        if stripped == 'DirectoryIndex index.php index.htm index.html' and not inserted_in_block:
            out.append(line)
            out.append('    SetEnvIfNoCase Authorization "(.+)" HTTP_AUTHORIZATION=$1')
            out.append('    AliasMatch ^/api/sms/send$ /var/www/tars-notificacoes/public_html/index.php')
            out.append('    AliasMatch ^/admin(?:/.*)?$ /var/www/tars-notificacoes/public_html/index.php')
            inserted_in_block = True
            continue
    out.append(line)
path.write_text('\n'.join(out) + '\n')