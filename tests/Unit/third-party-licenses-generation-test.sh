#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

if ! rg -n 'refresh-third-party-licenses\.sh' "$ROOT_DIR/Dockerfile" > /dev/null; then
    echo 'Dockerfile does not generate third-party licenses during the production image build.' >&2

    exit 1
fi

if [[ ! -f "$ROOT_DIR/internal/refresh-third-party-licenses.sh" ]]; then
    echo 'The shared third-party license generator script is missing.' >&2

    exit 1
fi

if ! rg -n '^!frontend/package\.json$|^!frontend/pnpm-lock\.yaml$|^!frontend/pnpm-workspace\.yaml$|^!frontend/\.npmrc$' "$ROOT_DIR/.dockerignore" > /dev/null; then
    echo '.dockerignore does not re-include the frontend manifests needed for production license generation.' >&2

    exit 1
fi

if ! rg -U -n 'if \[\[ "\$\{DEVELOPER_MODE\}" == '\''true'\'' \]\]; then\s+refreshThirdPartyLicenses &' "$ROOT_DIR/internal/run.sh" > /dev/null; then
    echo 'internal/run.sh does not limit runtime third-party license refreshes to developer mode.' >&2

    exit 1
fi

echo "third-party license generation checks passed"
