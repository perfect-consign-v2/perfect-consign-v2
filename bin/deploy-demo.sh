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
# Only scrub untracked SQL. A whole-tree git clean -fd deletes host-only files
# the checkout is not meant to own (composer.phar, Plesk dirs, …). Tenant
# migrate also uses SchemaCatalog::TENANT (allowlist), so a leftover 0xx under
# sql/ is belt-and-suspenders here, not the sole guard.
git clean -fd -- sql/

# Bash keeps the pre-reset inode open. Without re-exec, later steps (including
# the health check) still run from the script that started this process — so a
# tip that fixed /mall/login → / still failed with the old probe after reset.
if [[ "${DEPLOY_REEXEC:-}" != "1" ]]; then
    export DEPLOY_REEXEC=1
    export DEPLOY_SHA="$sha"
    exec bash "${root}/bin/deploy-demo.sh"
fi

if [[ "$(git rev-parse HEAD)" != "$sha" ]]; then
    echo "deploy-demo: HEAD is $(git rev-parse HEAD); expected ${sha}" >&2
    exit 1
fi

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
# PLATFORM_AFTER before tenants: cluster roles such as stall_vendor_entry must
# exist before tenant files grant to them (074_vendor_entry_grants.sql).
"$php_bin" "${root}/bin/migrate.php" platform-after
if ! tenant_err=$("$php_bin" "${root}/bin/migrate.php" tenant mall 2>&1); then
    printf '%s\n' "$tenant_err" >&2
    echo "deploy-demo: tenant migrate failed" >&2
    exit 1
fi
"$php_bin" "${root}/bin/migrate.php" tenant-all

# Demo convenience: plant or refresh mall owner from .env. set-owner.php is
# idempotent when the password already matches (no credentials_version bump).
if grep -Eq '^STALL_OWNER_PASSWORD=.+' "${root}/.env"; then
    "$php_bin" "${root}/bin/set-owner.php" mall owner
fi

# Platform admin forgot-password and operator notices need a planted admin and
# STALL_GMAIL_* (or STALL_SMTP_*) in .env. set-platform-admin.php is idempotent
# when the password already matches (no credentials_version bump).
if grep -Eq '^STALL_PLATFORM_ADMIN_PASSWORD=.+' "${root}/.env"; then
    "$php_bin" "${root}/bin/set-platform-admin.php"
fi


# Sign-in is only at `/`. Old /{slug}/login bookmarks redirect (303) there.
live=https://v2.perfectconsign.com/
headers=$(curl -sS -D - -o /dev/null "$live")
code=$(printf '%s' "$headers" | awk 'BEGIN{c=""} /^HTTP\//{c=$2} END{print c}')
if [[ "$code" != "200" ]]; then
    echo "deploy-demo: ${live} returned ${code}" >&2
    exit 1
fi

# HTTPS public origin requires Secure cookies: native HTTPS (Plesk sets HTTPS) or a
# trusted proxy peer. Empty STALL_TRUSTED_PROXIES is valid when the live origin
# already marks the session cookie Secure.
origin=$(grep -E '^STALL_PUBLIC_ORIGIN=' "${root}/.env" | head -n1 | cut -d= -f2- | tr -d '\r' | sed 's/^"//;s/"$//')
if [[ "${origin}" == https://* ]]; then
    proxies=$(grep -E '^STALL_TRUSTED_PROXIES=' "${root}/.env" | head -n1 | cut -d= -f2- | tr -d '\r' | sed 's/^"//;s/"$//' || true)
    if [[ -z "${proxies}" ]] && ! printf '%s' "$headers" | grep -qiE '^[Ss]et-[Cc]ookie:.*[;[:space:]][Ss]ecure([;[:space:]]|$)'; then
        echo "deploy-demo: STALL_PUBLIC_ORIGIN is https but STALL_TRUSTED_PROXIES is empty and ${live} did not set a Secure cookie; Secure cookies need a trusted proxy or process HTTPS" >&2
        exit 1
    fi
fi

echo "deploy-demo: ${sha} is live at ${live}"
