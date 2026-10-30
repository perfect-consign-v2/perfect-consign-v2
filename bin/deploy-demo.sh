#!/bin/bash
set -euo pipefail

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"

if [[ ! -d .git ]]; then
    echo "deploy-demo: ${root} is not a git checkout" >&2
    exit 1
fi

if [[ ! -f .env ]]; then
    echo "deploy-demo: .env is missing" >&2
    exit 1
fi

sha="${DEPLOY_SHA:-}"
if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "deploy-demo: DEPLOY_SHA must be the 40-character commit" >&2
    exit 1
fi

deploy_key="${HOME}/.ssh/github_deploy"
if [[ ! -f "$deploy_key" ]]; then
    echo "deploy-demo: ${deploy_key} is missing" >&2
    exit 1
fi

export GIT_TERMINAL_PROMPT=0
export GIT_SSH_COMMAND="ssh -i $(printf '%q' "$deploy_key") -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

git fetch origin master
tip=$(git rev-parse origin/master)
if [[ "$tip" != "$sha" ]]; then
    echo "deploy-demo: origin/master is ${tip}; skipping ${sha}"
    exit 0
fi

git reset --hard "$sha"

if [[ ! -f .env ]]; then
    echo "deploy-demo: .env is missing after reset" >&2
    exit 1
fi

php_bin=/opt/plesk/php/8.5/bin/php
composer="${root}/composer.phar"
if [[ ! -x "$php_bin" ]]; then
    echo "deploy-demo: ${php_bin} is missing" >&2
    exit 1
fi
if [[ ! -f "$composer" ]]; then
    echo "deploy-demo: ${composer} is missing" >&2
    exit 1
fi

# Plesk PHP is not on PATH as `php`. Composer scripts that shell out to bare
# `php` (and @php when the finder misses) need this directory first.
export PATH="$(dirname -- "$php_bin"):${PATH:-}"

"$php_bin" "$composer" --working-dir="$root" install --no-dev --no-interaction --prefer-dist
# Production APP_ENV reads var/cache/feature-controllers.php; rebuild after every
# reset so new controllers are registered and removed ones drop out.
# build:routes uses @php so it reuses this interpreter even without PATH.
"$php_bin" "$composer" --working-dir="$root" build:routes

"$php_bin" "${root}/bin/ensure-mail-key.php"
"$php_bin" "${root}/bin/migrate.php" platform
if ! tenant_err=$("$php_bin" "${root}/bin/migrate.php" tenant mall 2>&1); then
    printf '%s\n' "$tenant_err" >&2
    echo "deploy-demo: tenant migrate failed" >&2
    exit 1
fi
"$php_bin" "${root}/bin/migrate.php" tenant-all
"$php_bin" "${root}/bin/migrate.php" platform-after

# Demo convenience: plant or refresh mall owner from .env. set-owner.php is
# idempotent when the password already matches (no credentials_version bump).
if grep -Eq '^STALL_OWNER_PASSWORD=.+' "${root}/.env"; then
    "$php_bin" "${root}/bin/set-owner.php" mall owner
fi

# Sign-in is only at `/`. Old /{slug}/login bookmarks redirect (303) there.
live=https://v2.perfectconsign.com/
code=$(curl -sS -o /dev/null -w '%{http_code}' "$live")
if [[ "$code" != "200" ]]; then
    echo "deploy-demo: ${live} returned ${code}" >&2
    exit 1
fi

echo "deploy-demo: ${sha} is live at ${live}"
