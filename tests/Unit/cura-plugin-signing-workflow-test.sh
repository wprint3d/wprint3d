#!/usr/bin/env bash

set -euo pipefail

workflow='.github/workflows/cura-plugin-signing.yml'

grep -q '^  workflow_dispatch:' "$workflow"
grep -q 'environment:' "$workflow"
grep -q 'name: wprint-release-signing' "$workflow"
grep -q 'CURA_W3DP_SIGNING_PRIVATE_KEY' "$workflow"
grep -q 'CURA_W3DP_SIGNING_PASSPHRASE' "$workflow"
grep -q '^      minimum_wprint_core_version:' "$workflow"
grep -q 'scripts/validate-plugin-source-archive.py' "$workflow"
grep -q -- "--proto-redir '=https'" "$workflow"
grep -q 'plugin:pack' "$workflow"
grep -q -- '--require-trusted' "$workflow"
grep -q 'Staged manifest minimum WPrint core version' "$workflow"
grep -q 'publicKeySha256' "$workflow"
! grep -q 'signature\]\["fingerprint"\]' "$workflow"
grep -q 'cannot be the development placeholder' "$workflow"
grep -q 'upload-artifact@v4' "$workflow"
! grep -q '^  pull_request:' "$workflow"
! grep -q 'SIGNING_PRIVATE_KEY.*echo' "$workflow"

echo 'Cura plugin signing workflow checks passed'
