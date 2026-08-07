#!/usr/bin/env bash

set -euo pipefail

workflow='.github/workflows/docker-image.yml'
plugin_config='config/plugins.php'

grep -q '^  workflow_dispatch:' "$workflow"
for input in cura_w3dp_url cura_w3dp_sha256 cura_plugin_version cura_compatibility_url cura_compatibility_sha256; do
    grep -q "^      ${input}:" "$workflow"
    awk -v input="$input" '
        $0 == "      " input ":" { found = 1; next }
        found && $0 ~ /^      [A-Za-z0-9_]+:/ { exit !(typed) }
        found && $0 == "        type: string" { typed = 1 }
        END { exit !(found && typed) }
    ' "$workflow"
done
if grep -q '^      type:' "$workflow"; then
    echo 'Found an accidentally top-level workflow input named type.' >&2
    exit 1
fi

grep -q '^  prepare-cura-builtin:' "$workflow"
grep -q 'secrets.CURA_W3DP_SIGNER_PUBLIC_KEY' "$workflow"
grep -q 'secrets.CURA_RELEASE_READ_TOKEN' "$workflow"
grep -q 'scripts/stage-builtin-plugin.sh' "$workflow"
grep -q 'php artisan plugin:verify-builtins' "$workflow"
grep -q 'actions/upload-artifact@v4' "$workflow"
grep -q 'actions/download-artifact@v4' "$workflow"
grep -q 'name: cura-builtin-staged' "$workflow"
grep -q 'CURA_W3DP_SIGNER_PUBLIC_KEY' "$workflow"
grep -q 'resources/plugins/builtin/trusted/cura-w3dp-release.pem' "$workflow"
grep -Fq "glob(base_path('resources/plugins/builtin/trusted/*.pem'))" "$plugin_config"
grep -q 'resources/plugins/builtin' "$workflow"
grep -q 'needs: \[validate, prepare-cura-builtin\]' "$workflow"
grep -q 'always()' "$workflow"
grep -q "needs.prepare-cura-builtin.result == 'skipped'" "$workflow"
grep -q "github.event_name == 'workflow_dispatch'" "$workflow"
grep -q 'sha256sum --check --status' "$workflow"
grep -q -- "--proto-redir '=https'" "$workflow"
[[ "$(grep -c 'Accept: application/octet-stream' "$workflow")" -eq 2 ]]
[[ "$(grep -c 'Authorization: Bearer \$CURA_RELEASE_READ_TOKEN' "$workflow")" -eq 2 ]]

# Both architecture pushes and the manifest aggregation job must authenticate
# before reading/copying Docker Hub layers; otherwise a valid release can fail
# on anonymous-pull limits while creating the multi-architecture index.
[[ "$(grep -c 'name: Login to Docker Hub' "$workflow")" -ge 2 ]]
[[ "$(grep -c 'secrets.DOCKERHUB_TOKEN' "$workflow")" -ge 2 ]]
[[ "$(grep -c 'for attempt in 1 2 3 4 5' "$workflow")" -eq 2 ]]
grep -Fq "github.event_name == 'push' && github.ref == 'refs/heads/master'" "$workflow"
grep -q 'cura-cross-app-e2e-harness-test.sh' "$workflow"

echo 'built-in release workflow checks passed'
