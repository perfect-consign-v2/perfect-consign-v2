#!/bin/bash
# Wipe and reload mall + shop demo books on the host checkout.
# Lands tip + deps via deploy (skip migrate), then reinstalls when allowed,
# provisions, and seeds. Does not replace deploy-demo.sh for ordinary pushes.
set -euo pipefail

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"

if [[ ! -d .git ]]; then
    echo "seed-demo: ${root} is not a git checkout" >&2
    exit 1
fi

if [[ ! -f .env ]]; then
    echo "seed-demo: .env is missing" >&2
    exit 1
fi

php_bin=/opt/plesk/php/8.5/bin/php
if [[ ! -x "$php_bin" ]]; then
    echo "seed-demo: ${php_bin} is missing" >&2
    exit 1
fi

export PATH="$(dirname -- "$php_bin"):${PATH:-}"

deploy_key="${HOME}/.ssh/github_deploy"
if [[ ! -f "$deploy_key" ]]; then
    echo "seed-demo: ${deploy_key} is missing" >&2
    exit 1
fi

export GIT_TERMINAL_PROMPT=0
export GIT_SSH_COMMAND="ssh -i $(printf '%q' "$deploy_key") -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

git fetch origin master
tip=$(git rev-parse origin/master)
export DEPLOY_SHA="$tip"
# Deploy first without migrate: old 000/001 stamps would fail before any wipe.
export DEPLOY_SKIP_MIGRATE=1
bash "${root}/bin/deploy-demo.sh"

# Two-installer baseline: old stamps cannot migrate. Recreate the DB when allowed.
if grep -Eq '^STALL_ALLOW_PLATFORM_REINSTALL=(1|true|yes)$' "${root}/.env"; then
    "$php_bin" "${root}/bin/reinstall-empty-database.php"
    "$php_bin" "${root}/bin/provision-platform.php"
    if grep -Eq '^STALL_PLATFORM_ADMIN_PASSWORD=.+' "${root}/.env"; then
        "$php_bin" "${root}/bin/set-platform-admin.php"
    fi
else
    "$php_bin" "${root}/bin/migrate.php" platform
    "$php_bin" "${root}/bin/migrate.php" platform-after
    if ! tenant_err=$("$php_bin" "${root}/bin/migrate.php" tenant mall 2>&1); then
        printf '%s\n' "$tenant_err" >&2
        echo "seed-demo: tenant migrate failed (set STALL_ALLOW_PLATFORM_REINSTALL=true to wipe)" >&2
        exit 1
    fi
    "$php_bin" "${root}/bin/migrate.php" tenant-all
    if grep -Eq '^STALL_PLATFORM_ADMIN_PASSWORD=.+' "${root}/.env"; then
        "$php_bin" "${root}/bin/set-platform-admin.php"
    fi
fi

"$php_bin" "${root}/bin/seed-mall.php"
if grep -Eq '^STALL_OWNER_PASSWORD=.+' "${root}/.env"; then
    "$php_bin" "${root}/bin/set-owner.php" mall owner
fi

live=https://v2.perfectconsign.com/
headers=$(curl -sS -D - -o /dev/null "$live")
code=$(printf '%s' "$headers" | awk 'BEGIN{c=""} /^HTTP\//{c=$2} END{print c}')
if [[ "$code" != "200" ]]; then
    echo "seed-demo: ${live} returned ${code}" >&2
    exit 1
fi

echo "seed-demo: mall and shop books loaded at ${tip}"
