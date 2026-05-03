#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PRINT_JOB="$ROOT_DIR/app/Jobs/PrintGcode.php"

if grep -n '\$absolutePosition = \$this->printer->getAbsolutePosition' "$PRINT_JOB"; then
    echo "PrintGcode must keep absolute position in memory during the hot loop; per-line cache reads are too expensive on low-end hosts." >&2
    exit 1
fi

if ! awk '
    /time\(\) - \$lastPositionUpdate >= 1/ {
        in_position_flush = 1
    }
    in_position_flush && /\$this->printer->setAbsolutePosition\(/ {
        saw_position_flush = 1
    }
    saw_position_flush && /\$lastPositionUpdate = time\(\);/ {
        found = 1
        exit
    }
    END {
        exit found ? 0 : 1
    }
' "$PRINT_JOB"; then
    echo "PrintGcode must refresh lastPositionUpdate after periodic absolute-position cache writes." >&2
    exit 1
fi
