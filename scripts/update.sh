#!/usr/bin/env bash
# Update the Docker stack on the test machine to the latest code of a branch.
#
# What it does: checks preconditions, dumps the database to backups/, fast-forwards
# the git branch, rebuilds and restarts the containers, installs PHP packages,
# migrates, clears caches, restarts the queue workers, builds the frontend and
# checks /api/v1/health. It stops on the first failure.
#
# Run (first time):  cd <repo> && git fetch origin && git checkout feature/perf-architecture && bash scripts/update.sh
# Run (afterwards):  bash scripts/update.sh [branch]
#
# Roll back by hand:
#   1. git switch <old branch> && git reset --hard <old SHA>   (both are printed by this script)
#      On the first run from Release 1a the printed old SHA is already the perf tip: use git switch main (515b042).
#   2. docker compose up -d --build
#   3. gunzip -c backups/pre-update-<file>.sql.gz | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U <POSTGRES_USER> -d <POSTGRES_DB>
#      (restore into an empty database; drop and recreate it first if it already has tables)
set -euo pipefail
umask 077

STEP=start
step() { STEP="$*"; echo; echo "==> $STEP"; }
die() { echo "ERROR: $*" >&2; exit 1; }
trap 'echo "FAILED during: $STEP. Nothing further was run. See the rollback notes at the top of scripts/update.sh." >&2' ERR

cd "$(dirname "$0")/.."

# Read one key from the root .env without sourcing it (so no password is ever handled).
envval() { { grep -E "^$1=" .env || true; } | tail -1 | cut -d= -f2- | tr -d "\r\"'"; }

step "Preflight"
for tool in git docker npm curl; do
  command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed"
done
docker compose version >/dev/null 2>&1 || die "docker compose is not available"
[ -f .env ] || die "root .env is missing"
[ -f backend/.env ] || die "backend/.env is missing"
dirty=$(git status --porcelain --untracked-files=no)
if [ -n "$dirty" ]; then
  echo "$dirty" >&2
  die "tracked files are modified or staged (listed above). Commit or stash them, then re-run."
fi

old_branch=$(git rev-parse --abbrev-ref HEAD)
old_sha=$(git rev-parse HEAD)
branch=${1:-$old_branch}
db_user=$(envval POSTGRES_USER)
db_name=$(envval POSTGRES_DB)
app_port=$(envval APP_PORT)
app_port=${app_port:-8000}
echo "Branch: $branch (currently $old_branch at $old_sha)"

step "Database dump"
mkdir -p backups
dump="backups/pre-update-$(date +%Y%m%d-%H%M%S)-$(echo "$old_sha" | cut -c1-7).sql.gz"
docker compose up -d --wait postgres
docker compose exec -T postgres pg_dump -U "$db_user" "$db_name" | gzip > "$dump"
gunzip -c "$dump" | grep -q 'CREATE TABLE public.migrations' || die "dump has no migrations table: wrong or empty database (check the compose project name)"
echo "Dump written to $dump"

step "Update the code"
git fetch origin
if git show-ref --verify --quiet "refs/heads/$branch"; then
  git switch "$branch"
  git merge --ff-only "origin/$branch" || die "cannot fast-forward $branch to origin/$branch (local commits?). Nothing was merged."
else
  git switch -c "$branch" --track "origin/$branch"
fi
new_sha=$(git rev-parse HEAD)
echo "Old SHA: $old_sha"
echo "New SHA: $new_sha"
echo "Commits pulled: $(git rev-list --count "$old_sha..$new_sha")"

step "Build and start the containers"
docker compose up -d --build --remove-orphans

step "Composer install"
# vendor/ lives on the bind mount and may be stale or missing.
docker compose exec -T app composer install --no-interaction --prefer-dist
docker compose exec -T app chown -R www-data:www-data storage bootstrap/cache

step "Migrate and clear caches"
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:clear
# Role grants and settings are cached with no expiry, so this is required after migrating.
docker compose exec -T app php artisan cache:clear

step "Restart workers and scheduler"
docker compose restart worker-critical worker-pdf worker-reports worker-maintenance scheduler

step "Health check (up to 30 s)"
i=0
until curl -fsS "http://127.0.0.1:$app_port/api/v1/health"; do
  i=$((i + 1))
  [ "$i" -lt 15 ] || die "health check failed on port $app_port"
  sleep 2
done
echo

step "First account"
users=$(docker compose exec -T postgres psql -U "$db_user" -d "$db_name" -tAc 'select count(*) from users' | tr -d '[:space:]')
if [ "$users" = 0 ]; then
  echo "There are no users yet. Create the first Developer account:"
  echo '  docker compose exec app php artisan identity:create-user you@example.com --name="Your Name" --role=developer'
fi

step "Build the frontend"
(cd frontend && npm ci && npm run build)

echo
echo "Done. Old SHA: $old_sha ($old_branch). New SHA: $new_sha ($branch). Dump: $dump. Health: OK."
echo "Reboot this machine (sudo reboot), or restart your auto-start, so the frontend restarts with the new packages."
