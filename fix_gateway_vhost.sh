#!/bin/bash
set -euo pipefail
cp /etc/httpd/conf/httpd.conf.bak-tars-20260531-215517 /etc/httpd/conf/httpd.conf
perl -0pi -e 's#(DocumentRoot /var/www/tars-notificacoes/public_html\n)#$1    SetEnvIfNoCase Authorization "(.+)" HTTP_AUTHORIZATION=\$1\n    AliasMatch ^/api/sms/send\$ /var/www/tars-notificacoes/public_html/index.php\n    AliasMatch ^/admin(?:/.*)?\$ /var/www/tars-notificacoes/public_html/index.php\n#g' /etc/httpd/conf/httpd.conf
apachectl configtest
systemctl reload httpd