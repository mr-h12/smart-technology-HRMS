#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output="${1:?Pass a directory for the synthetic PDFs}"
image="${HRMS_PDF_IMAGE:-hrms-php-forms}"
form="${HRMS_SMOKE_FORM:-experience-certificate}"
# The case of backend/tests/Smoke/render-hr-form.php's CASES; its PDFs go to <output>/<form>/<case>/, as the goldens are laid out.
case="${HRMS_SMOKE_CASE:-female}"
goldens="${HRMS_SMOKE_GOLDENS:-}"
python=/usr/bin/python3
measure="$repo/scripts/pdf-measure.py"
absent=()
fonts=
case "$form" in
  overtime-request) phrases=('سارة مثال للاختبار' 'طلب عمل إضافي' 'Overtime Request' 'Checkout Time' '17:35' 'ساعة واحدة');;
  advance-request) phrases=('سارة مثال للاختبار' 'طلب سلفة' 'Employee Salary' '9000.00' '250.50' 'General Manager');;
  advance-request-no-pay) phrases=('سارة مثال للاختبار' 'طلب سلفة' '250.50' 'Monthly Salary Deduction' 'من الراتب' 'General Manager')
    absent=('راتب الموظف' 'راتب الموظفة' 'Employee Salary' 'غير متاح' 'Unavailable' '9000' 'Synthetic finance remarks');;
  # Written as Poppler extracts a correct page: it reverses some of Markazi's two-letter ligatures.
  leave-request) fonts='MarkaziText-Bold|Carlito-Bold'; phrases=('نموذج طلب إجازة' 'برجاء من سيادتكم' 'توقيع مدير الشركة' 'توقيع الموارد البشرية' 'Smart Technology');;
  experience-certificate) fonts='MarkaziText-Bold'; phrases=('شهادة خبرة' 'الشركة');;
  *) printf 'Unknown form: %s\n' "$form" >&2; exit 1;;
esac
pdftotext="${PDFTOTEXT:-pdftotext}"
command -v "$pdftotext" >/dev/null || { echo 'Install Poppler or set PDFTOTEXT to its pdftotext executable.' >&2; exit 1; }
pdfinfo="${PDFINFO:-pdfinfo}"
command -v "$pdfinfo" >/dev/null || { echo 'Install Poppler or set PDFINFO to its pdfinfo executable.' >&2; exit 1; }
pdffonts="${PDFFONTS:-pdffonts}"
command -v "$pdffonts" >/dev/null || { echo 'Install Poppler or set PDFFONTS to its pdffonts executable.' >&2; exit 1; }
[[ -z "$goldens" || -d "$goldens" ]] || { echo "No goldens folder: $goldens" >&2; exit 1; }
[[ -z "$goldens" ]] || "$python" -c 'import PIL' || { echo "$python needs Pillow for the pixel compare." >&2; exit 1; }
suffix=hrms-render-smoke-$$
net=$suffix-net
db=$suffix-db
redis=$suffix-redis
app=$suffix-app
docker network create --internal "$net" >/dev/null
trap 'docker rm -f "$app" "$db" "$redis" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true' EXIT
docker run -d --rm --pull=never --name "$db" --network "$net" --network-alias postgres --tmpfs /var/lib/postgresql/data -e POSTGRES_DB=hrms_test -e POSTGRES_USER=hrms -e POSTGRES_PASSWORD=local-test-only postgres:17.5-bookworm >/dev/null
docker run -d --rm --pull=never --name "$redis" --network "$net" --network-alias redis redis:7.4-alpine redis-server --requirepass local-test-only >/dev/null
docker run -d --rm --pull=never --name "$app" --network "$net" -v "$repo/backend:/var/www/html" --tmpfs /var/www/html/storage/app/private -w /var/www/html -e APP_KEY="base64:$(openssl rand -base64 32)" -e DB_HOST=postgres -e DB_DATABASE=hrms_test -e DB_USERNAME=hrms -e DB_PASSWORD=local-test-only -e CACHE_STORE=array -e HRMS_SMOKE_FORM="$form" -e HRMS_SMOKE_CASE="$case" -e HRMS_SMOKE_TODAY="${HRMS_SMOKE_TODAY:-}" -e REDIS_HOST=redis -e REDIS_PASSWORD=local-test-only "$image" sleep 600 >/dev/null
for n in {1..20}; do if docker exec "$db" pg_isready -h 127.0.0.1 -U hrms -d hrms_test >/dev/null 2>&1; then break; fi; sleep 1; done
docker exec "$app" php artisan migrate --force -q
# Whether the case has an approved twin comes from its CASES entry, so a wrong goldens folder fails instead of skipping the compare.
twin=no
[[ -z "$goldens" ]] || twin=$(docker exec "$app" php tests/Smoke/render-hr-form.php twin)

# Text as one string with no whitespace, no direction marks, and presentation forms folded by NFKC; no letter is mapped.
flat() { "$python" -c 'import re, sys, unicodedata; print(re.sub("[\\s\u200b-\u200f\u202a-\u202e\u2066-\u2069]", "", unicodedata.normalize("NFKC", sys.stdin.read())))'; }
fail() { printf '%s\n' "$1" >&2; exit 1; }

# Checks one PDF: one A4 page, the form's fonts embedded, the title, the phrases, then the golden twin or the layout rules.
verify() {
  local pdf="$1" rel="$2" serial="$3" text haystack phrase title list report
  [[ "$("$pdfinfo" "$pdf" | sed -n 's/^Pages: *//p')" == 1 ]] || fail "Not one page: $pdf"
  "$pdfinfo" "$pdf" | grep -Eq '^Page size: +595\.92 x 841\.92 pts' || fail "Not A4: $pdf"
  if [[ -n "$fonts" ]]; then
    list=$("$pdffonts" "$pdf" | tail -n +3)
    if [[ -z "$list" ]] || awk -v ok="^([A-Z]{6}\\\\+)?($fonts)\$" '{ if ($1 !~ ok || $(NF-4) != "yes") bad = 1 } END { exit !bad }' <<< "$list"; then
      fail "$(printf 'Unexpected fonts in %s:\n%s' "$pdf" "$list")"
    fi
  fi
  title=$("$pdfinfo" -enc UTF-8 "$pdf" | sed -n 's/^Title: *//p')
  [[ "$title" != index.html && "$title" == *"الرقم المسلسل $serial" ]] || fail "Unexpected PDF title in $pdf: $title"
  text=$("$pdftotext" -enc UTF-8 "$pdf" -)
  haystack=$(flat <<< "$text")
  # The date in either reading direction (the forms on pdf.page type it with spaces around the slashes), never ISO.
  if ! grep -Eq '[0-9]{2}/[0-9]{2}/[0-9]{4}|[0-9]{4}/[0-9]{2}/[0-9]{2}' <<< "$haystack" || grep -Eq '[0-9]{4}-[0-9]{2}-[0-9]{2}' <<< "$text"; then
    fail "Unexpected date format in $pdf"
  fi
  for phrase in "${phrases[@]}"; do
    grep -Fq -- "$(flat <<< "$phrase")" <<< "$haystack" || fail "Expected text missing from $pdf: $phrase"
  done
  for phrase in ${absent[@]+"${absent[@]}"}; do
    ! grep -Fq -- "$phrase" <<< "$text" || fail "Unexpected text in $pdf: $phrase"
  done
  if [[ "$twin" == yes ]]; then
    [[ -f "$goldens/$rel" ]] || fail "Approved twin missing: $goldens/$rel"
    report="${pdf%.pdf}-compare"
    mkdir -p "$report"
    if ! "$python" "$measure" compare "$goldens/$rel" "$pdf" --out "$report" > "$report/report.txt" || grep -q 'RASTER  not run' "$report/report.txt"; then
      fail "$(printf 'Differs from its approved twin %s:\n%s' "$goldens/$rel" "$(cat "$report/report.txt")")"
    fi
    echo "$rel: $(tail -1 "$report/report.txt")"
  elif [[ -n "$fonts" ]]; then
    "$python" "$measure" check "$form" "$pdf" > "${pdf%.pdf}-check.txt" || fail "$(cat "${pdf%.pdf}-check.txt")"
    echo "$rel: ${goldens:+no approved twin; }$(tail -1 "${pdf%.pdf}-check.txt")"
  fi
}

# A run renders unsigned, then signed for the forms that are signed, into <form>/<case>[/<run>]/.
for run in $(docker exec "$app" php tests/Smoke/render-hr-form.php runs); do
  rel="$form/$case"; [[ "$run" == . ]] || rel="$rel/$run"
  dir="$output/$rel"; mkdir -p "$dir"
  unsigned=$(docker exec -e HRMS_SMOKE_RUN="$run" "$app" php tests/Smoke/render-hr-form.php setup)
  docker exec "$app" php artisan queue:work redis --queue=pdf --once --tries=3 -v
  docker exec "$app" php tests/Smoke/render-hr-form.php export "$unsigned" > "$dir/unsigned.pdf"
  serial=$(docker exec "$db" psql -U hrms -d hrms_test -tAc "select serial_number from hr_form_exports where id = '$unsigned'")
  stages=(unsigned)
  if [[ "$form" != advance-request* && "$form" != overtime-request ]]; then
    signed=$(docker exec -e HRMS_SMOKE_RUN="$run" "$app" php tests/Smoke/render-hr-form.php approve "$unsigned")
    docker exec "$app" php artisan queue:work redis --queue=pdf --once --tries=3 -v
    docker exec "$app" php tests/Smoke/render-hr-form.php export "$signed" > "$dir/signed.pdf"
    stages+=(signed)
  fi
  for stage in "${stages[@]}"; do verify "$dir/$stage.pdf" "$rel/$stage.pdf" "$serial"; done
done
[[ -n "$goldens" ]] || echo 'No golden compare: HRMS_SMOKE_GOLDENS is not set.'
echo 'Page count, page size, fonts, title, phrases and layout verified.'
docker exec "$db" psql -U hrms -d hrms_test -c 'select stage, serial_number, status, size_bytes from hr_form_exports order by serial_number, stage;'
