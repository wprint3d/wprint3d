#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root_dir/../cura-web-ui/.github/workflows/multiarch-image.yml"

test -f "$workflow"
grep -q '^  native-source-build:' "$workflow"
grep -q 'runner: ubuntu-24.04' "$workflow"
grep -q 'runner: ubuntu-24.04-arm' "$workflow"
grep -q 'node scripts/verify-engine-lock.mjs' "$workflow"
grep -q 'docker buildx build' "$workflow"
grep -q -- '--load' "$workflow"
grep -q 'CURAENGINE_URL' "$workflow"
grep -q 'CURA_RESOURCES_URL' "$workflow"
grep -q 'CONAN_CONFIG_URL' "$workflow"
grep -q 'scripts/container-real-slice-test.py --base-url http://127.0.0.1:19312' "$workflow"
grep -q 'needs: \[build-push, smoke, native-smoke, native-source-build\]' "$workflow"
attestation_script="$root_dir/../cura-web-ui/scripts/fetch-oci-attestations.sh"
grep -q 'repository="\${image%@\*}"' "$attestation_script"
! grep -q 'oras manifest fetch "\$image@' "$attestation_script"

echo 'Cura native source-build workflow checks passed'
