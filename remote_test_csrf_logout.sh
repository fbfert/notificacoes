set -euo pipefail
BASE=/var/www/tars-notificacoes
cd "$BASE"
set -a
. ./.env
set +a
cookie=$(mktemp)
trap 'rm -f "$cookie"' EXIT
admin_page=$(curl -sS -c "$cookie" -b "$cookie" https://gateway.tars.art.br/admin)
csrf=$(printf '%s' "$admin_page" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1)
curl -sS -c "$cookie" -b "$cookie" -X POST https://gateway.tars.art.br/admin/login -H 'Content-Type: application/x-www-form-urlencoded' --data-urlencode "csrf_token=$csrf" --data-urlencode "password=$ADMIN_PASSWORD" >/dev/null
projects_page=$(curl -sS -c "$cookie" -b "$cookie" https://gateway.tars.art.br/admin/projects)
csrf_projects=$(printf '%s' "$projects_page" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1)
logout_code=$(curl -sS -o /tmp/logout_body.$$ -w '%{http_code}' -c "$cookie" -b "$cookie" -X POST https://gateway.tars.art.br/admin/logout -H 'Content-Type: application/x-www-form-urlencoded' --data-urlencode "csrf_token=$csrf_projects")
echo "LOGOUT_CODE=$logout_code"
head -c 200 /tmp/logout_body.$$; echo
rm -f /tmp/logout_body.$$
