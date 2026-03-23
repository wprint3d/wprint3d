#!/bin/bash

set -euo pipefail

ROOT_DIR="${1:-$(pwd)}"
OUTPUT_PATH="${2:-$ROOT_DIR/THIRD_PARTY_LICENSES.txt}"
STATIC_PATH="$ROOT_DIR/_STATIC_THIRD_PARTY_LICENSES.txt"

declare -a LICENSE_ROOTS=()

for candidate in "$ROOT_DIR/vendor" "$ROOT_DIR/frontend/node_modules"; do
    if [[ -d "$candidate" ]]; then
        LICENSE_ROOTS+=("$candidate")
    fi
done

cat "$STATIC_PATH" > "$OUTPUT_PATH"
printf '\n\n' >> "$OUTPUT_PATH"

if [[ ${#LICENSE_ROOTS[@]} -eq 0 ]]; then
    exit 0
fi

while IFS= read -r -d '' license; do
    relative_license="${license#"$ROOT_DIR"/}"
    project_name="$(printf '%s' "$relative_license" | sed -E 's#((vendor|frontend/node_modules)/)|(\/LICENSE.*)|(\/ORIGINAL.*)|(src/)##g')"

    echo '================================================================================' >> "$OUTPUT_PATH"
    echo "$project_name"                                                              >> "$OUTPUT_PATH"
    echo '================================================================================' >> "$OUTPUT_PATH"
    echo ''                                                                           >> "$OUTPUT_PATH"
    cat "$license"                                                                    >> "$OUTPUT_PATH"
    echo ''                                                                           >> "$OUTPUT_PATH"
done < <(find "${LICENSE_ROOTS[@]}" -type f -name '*LICENSE*' -print0 | sort -z)
