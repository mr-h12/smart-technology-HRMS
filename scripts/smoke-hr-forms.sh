#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output="${1:?Pass a directory for the synthetic PDFs}"
mkdir -p "$output"
image="${HRMS_PDF_IMAGE:-hrms-php-forms}"
form="${HRMS_SMOKE_FORM:-experience-certificate}"
case "$form" in
  early-leave-request) phrases=('Smart Technology' 'سارة مثال للاختبار' 'طلب انصراف' 'لمدة ساعة' 'واحدة و' '30' 'دقيقة' '08:30' '17:30' '16:00' '15/11/2026');;
  late-permit) phrases=('سارة مثال للاختبار' 'إذن تأخير' 'ساعتان' '16/11/2026' '15/11/2026' '11:00' 'موعد طبي');;
  overtime-request) phrases=('سارة مثال للاختبار' 'طلب عمل إضافي' 'Overtime Request' 'Checkout Time' '17:35' 'ساعة واحدة');;
  advance-request) phrases=('سارة مثال للاختبار' 'طلب سلفة' 'Employee Salary' '9000.00' '250.50' 'General Manager');;
  leave-request) phrases=('سارة مثال للاختبار' 'نموذج طلب إجازة' 'توقيع مدير الشركة');;
  experience-certificate) phrases=('سارة مثال للاختبار' 'شهادة خبرة' 'الشركة');;
  *) printf 'Unknown form: %s\n' "$form" >&2; exit 1;;
esac
pdftotext="${PDFTOTEXT:-pdftotext}"
command -v "$pdftotext" >/dev/null || { echo 'Install Poppler or set PDFTOTEXT to its pdftotext executable.' >&2; exit 1; }
suffix=hrms-render-smoke-$$
net=$suffix-net
db=$suffix-db
redis=$suffix-redis
app=$suffix-app
docker network create --internal "$net" >/dev/null
trap 'docker rm -f "$app" "$db" "$redis" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true' EXIT
docker run -d --rm --pull=never --name "$db" --network "$net" --network-alias postgres --tmpfs /var/lib/postgresql/data -e POSTGRES_DB=hrms_test -e POSTGRES_USER=hrms -e POSTGRES_PASSWORD=local-test-only postgres:17.5-bookworm >/dev/null
docker run -d --rm --pull=never --name "$redis" --network "$net" --network-alias redis redis:7.4-alpine redis-server --requirepass local-test-only >/dev/null
docker run -d --rm --pull=never --name "$app" --network "$net" -v "$repo/backend:/var/www/html" --tmpfs /var/www/html/storage/app/private -w /var/www/html -e APP_KEY="base64:$(openssl rand -base64 32)" -e DB_HOST=postgres -e DB_DATABASE=hrms_test -e DB_USERNAME=hrms -e DB_PASSWORD=local-test-only -e CACHE_STORE=array -e HRMS_SMOKE_FORM="$form" -e REDIS_HOST=redis -e REDIS_PASSWORD=local-test-only "$image" sleep 300 >/dev/null
for n in {1..20}; do if docker exec "$db" pg_isready -h 127.0.0.1 -U hrms -d hrms_test >/dev/null 2>&1; then break; fi; sleep 1; done
docker exec "$app" php artisan migrate --force -q
unsigned=$(docker exec "$app" php tests/Smoke/render-hr-form.php setup)
docker exec "$app" php artisan queue:work redis --queue=pdf --once --tries=3 -v
docker exec "$app" php tests/Smoke/render-hr-form.php export "$unsigned" > "$output/unsigned.pdf"
pdfs=("$output/unsigned.pdf")
if [[ "$form" != advance-request && "$form" != overtime-request && "$form" != late-permit && "$form" != early-leave-request ]]; then
  signed=$(docker exec "$app" php tests/Smoke/render-hr-form.php approve "$unsigned")
  docker exec "$app" php artisan queue:work redis --queue=pdf --once --tries=3 -v
  docker exec "$app" php tests/Smoke/render-hr-form.php export "$signed" > "$output/signed.pdf"
  pdfs+=("$output/signed.pdf")
fi
for pdf in "${pdfs[@]}"; do
  text=$("$pdftotext" -enc UTF-8 "$pdf" -)
  if ! grep -Eq '[0-9]{2}/[0-9]{2}/[0-9]{4}' <<< "$text" || grep -Eq '[0-9]{4}-[0-9]{2}-[0-9]{2}' <<< "$text"; then
    printf 'Unexpected date format in %s\n' "$pdf" >&2
    exit 1
  fi
  for phrase in "${phrases[@]}"; do
    if ! grep -Fq -- "$phrase" <<< "$text"; then
      printf 'Expected text missing from %s: %s\n' "$pdf" "$phrase" >&2
      exit 1
    fi
  done
done
echo 'PDF text extraction verified.'
docker exec "$db" psql -U hrms -d hrms_test -c 'select stage, serial_number, status, size_bytes from hr_form_exports order by stage;'
