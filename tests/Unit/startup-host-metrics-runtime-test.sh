#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
run_source="$(<"$ROOT_DIR/internal/run.sh")"
sampler_source="$(<"$ROOT_DIR/internal/startup-metrics.php")"

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected ${description} to contain: ${needle}" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Expected ${description} not to contain: ${needle}" >&2
        exit 1
    fi
}

assert_contains "$run_source" "rm -fv /tmp/*.txt /var/www/internal/startup/*.txt" 'startup artifact cleanup'
assert_contains "$run_source" 'php /var/www/internal/startup-metrics.php' 'startup metrics sampler launch'
assert_contains "$run_source" '--output=/var/www/internal/startup/host-metrics.txt' 'public metrics output path'
assert_contains "$run_source" '--interval-ms=2000' 'two-second sample interval'
assert_contains "$run_source" 'trap '\''stopHostMetricsSampler "$host_metrics_pid"'\'' EXIT' 'sampler exit cleanup'
assert_contains "$run_source" 'kill "$sampler_pid"' 'sampler termination'

assert_contains "$sampler_source" "'version' => 1" 'versioned metrics contract'
assert_contains "$sampler_source" "'leaders' =>" 'process leader contract'
assert_contains "$sampler_source" 'rename($temporaryPath, $outputPath)' 'atomic metrics publication'
assert_not_contains "$sampler_source" "'pid' =>" 'public process identifiers'
assert_not_contains "$sampler_source" "'cmdline' =>" 'public process command lines'

echo 'startup host metrics runtime checks passed'
