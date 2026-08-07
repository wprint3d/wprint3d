#!/usr/bin/env bash

set -euo pipefail

script='scripts/e2e_cura_wprint3d.py'

grep -q 'WPRINT3D_E2E_ALLOW_DESTRUCTIVE' "$script"
grep -q 'SECOND_EMAIL' "$script"
grep -q 'CURA_GATEWAY_IMAGE' "$script"
grep -q '@sha256:' "$script"
grep -q 'hostMode=embedded' "$script"
grep -q 'duplicate standalone Cura header' "$script"
grep -q 'web-calibration-cube.stl' "$script"
grep -q 'job.completed' "$script"
grep -q 'name="Cancel"' "$script"
grep -q 'Save to WPrint 3D' "$script"
grep -q 'assert_second_user_cannot_read_first_job' "$script"
grep -q 'plugin:disable' "$script"
grep -q 'plugin:enable' "$script"
if grep -q 'mock\|fake gateway\|route.fulfill' "$script"; then
    echo 'Cross-application harness must not use mocks.' >&2
    exit 1
fi

python3 -c 'compile(open("scripts/e2e_cura_wprint3d.py", encoding="utf-8").read(), "scripts/e2e_cura_wprint3d.py", "exec")'
echo 'Cura cross-application E2E harness checks passed'
