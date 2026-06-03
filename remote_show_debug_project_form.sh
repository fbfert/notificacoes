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
printf '%s\n' "$projects_page" | sed -n '/debug-20260602210344-fb4867/,+20p'
