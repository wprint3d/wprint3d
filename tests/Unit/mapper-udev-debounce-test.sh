#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
RUN_SCRIPT="$ROOT_DIR/internal/run.sh"

run_source="$(cat "$RUN_SCRIPT")"

if [[ "$run_source" != *'php artisan map:serial-printers --debounce=3 &'* ]]; then
    echo 'Expected tty udev events to launch the serial mapper asynchronously with a 3-second debounce.' >&2
    exit 1
fi

if ! rg -U -n 'if \[\[ "\$nodePath" == \*'\''tty'\''\* \]\]; then\s+#.*\s+#.*\s+php artisan map:serial-printers --debounce=3 &\s+elif \[\[ "\$nodePath" == \*'\''video'\''\* \]\]; then\s+php artisan map:hardware-cameras;\s+mapCameraLabels;' "$RUN_SCRIPT" > /dev/null; then
    echo 'Expected tty and video udev events to use separate mapper flows.' >&2
    exit 1
fi

echo 'mapper udev debounce checks passed'
