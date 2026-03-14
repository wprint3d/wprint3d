#!/bin/bash

set -euo pipefail

SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"
cd "$SCRIPT_PATH"

source "${SCRIPT_PATH}/internal/container-runtime.sh"

usage() {
    cat <<'EOF'
Usage:
  ./plugin.sh [--environment dev|production] [--container <name>] <command> [arguments...]

Examples:
  ./plugin.sh status
  ./plugin.sh make
  ./plugin.sh make acme.hello-world "Hello World"
  ./plugin.sh pack examples/plugins/hello-world
  ./plugin.sh install /tmp/hello-world.w3dp
  ./plugin.sh list
  ./plugin.sh --container wprint3d-core-backend-1 make

The command can be provided as:
  status      -> backend/plugin diagnostics
  make        -> php artisan plugin:make
  pack        -> php artisan plugin:pack
  install     -> php artisan plugin:install
  plugin:make -> php artisan plugin:make
EOF
}

command_exists() {
    command -v "$1" > /dev/null 2>&1
}

runtime_container_cli() {
    local runtime="$1"
    shift

    if [[ "$runtime" == 'podman' ]] && [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_podman_rootful_command "$@"

        return $?
    fi

    "$runtime" "$@"
}

detect_running_environment() {
    if [[ -f docker-compose-development.yml ]]; then
        if run_host_compose -f docker-compose-development.yml ps -q backend 2>/dev/null | grep -q .; then
            printf 'dev\n'

            return 0
        fi
    fi

    if [[ -f docker-compose.yml ]]; then
        if run_host_compose ps -q backend 2>/dev/null | grep -q .; then
            printf 'production\n'

            return 0
        fi
    fi

    return 1
}

compose_args_for_environment() {
    local environment="$1"

    case "$environment" in
        dev)
            printf '%s\n' "-f" "docker-compose-development.yml"
            ;;
        production)
            ;;
        *)
            echo "Unsupported environment: ${environment}" >&2
            return 1
            ;;
    esac
}

normalize_plugin_command() {
    local command="$1"

    case "$command" in
        plugin:*)
            printf '%s\n' "$command"
            ;;
        make|pack|publish|search|install|list|enable|disable|remove|update|doctor)
            printf 'plugin:%s\n' "$command"
            ;;
        *)
            printf '%s\n' "$command"
            ;;
    esac
}

list_backend_candidates_for_runtime() {
    local runtime="$1"
    local candidates=''

    if ! command_exists "$runtime"; then
        return 0
    fi

    candidates="$(runtime_container_cli "$runtime" ps \
        --filter label=com.docker.compose.service=backend \
        --format '{{.Names}}' 2>/dev/null || true)"

    candidates+=$'\n'"$(runtime_container_cli "$runtime" ps \
        --filter label=io.podman.compose.service=backend \
        --format '{{.Names}}' 2>/dev/null || true)"

    if ! grep -q '[^[:space:]]' <<< "$candidates"; then
        candidates="$(runtime_container_cli "$runtime" ps \
            --filter name=backend \
            --format '{{.Names}}' 2>/dev/null | grep -E '(^|[-_])backend([_-]|$)' || true)"
    fi

    awk 'NF && !seen[$0]++' <<< "$candidates"
}

list_container_runtimes() {
    local runtimes=()

    if [[ -n "${HOST_CONTAINER_RUNTIME:-}" ]] && command_exists "$HOST_CONTAINER_RUNTIME"; then
        runtimes+=("$HOST_CONTAINER_RUNTIME")
    fi

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'docker' ]] && command_exists docker; then
        runtimes+=('docker')
    fi

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]] && command_exists podman; then
        runtimes+=('podman')
    fi

    printf '%s\n' "${runtimes[@]}"
}

detect_backend_container() {
    local requested_container="${1:-}"
    local runtime
    local name
    local -a matches=()

    while IFS= read -r runtime; do
        [[ -n "$runtime" ]] || continue

        while IFS= read -r name; do
            [[ -n "$name" ]] || continue

            if [[ -n "$requested_container" ]] && [[ "$name" != "$requested_container" ]]; then
                continue
            fi

            matches+=("${runtime}:${name}")
        done < <(list_backend_candidates_for_runtime "$runtime")
    done < <(list_container_runtimes)

    if [[ ${#matches[@]} -eq 0 ]]; then
        return 1
    fi

    if [[ -n "$requested_container" ]]; then
        if [[ ${#matches[@]} -gt 1 ]]; then
            echo "Multiple containers matched '${requested_container}'. Pass a more specific --container value." >&2
            printf 'Matches:\n' >&2
            printf '  %s\n' "${matches[@]}" >&2

            return 1
        fi
    elif [[ ${#matches[@]} -gt 1 ]]; then
        echo 'Multiple running backend containers were detected.' >&2
        echo 'Pass --environment or --container to disambiguate which stack to use.' >&2
        printf 'Matches:\n' >&2
        printf '  %s\n' "${matches[@]}" >&2

        return 1
    fi

    printf '%s\n' "${matches[0]}"
}

run_direct_container_exec() {
    local runtime="$1"
    local container="$2"
    shift 2

    local exec_args=(exec)

    if [[ -t 0 && -t 1 ]]; then
        exec_args+=(-i -t)
    else
        exec_args+=(-i)
    fi

    exec_args+=("$container" php artisan "$command_name")

    if [[ $# -gt 0 ]]; then
        exec_args+=("$@")
    fi

    runtime_container_cli "$runtime" "${exec_args[@]}"
}

run_direct_container_shell() {
    local runtime="$1"
    local container="$2"
    local script="$3"

    runtime_container_cli "$runtime" exec -i "$container" sh -lc "$script"
}

run_compose_backend_shell() {
    local script="$1"

    run_host_compose "${compose_args[@]}" exec -T backend sh -lc "$script"
}

print_status_report() {
    local script

    script="$(cat <<'EOF'
echo "Resolved backend diagnostics"
echo "Developer mode env: ${DEVELOPER_MODE:-<unset>}"
echo "Configured mount path env: ${PLUGIN_DEVELOPMENT_MOUNT_PATH:-<unset>}"

mount_path="${PLUGIN_DEVELOPMENT_MOUNT_PATH:-/var/www/plugins-dev}"

if [ -d "$mount_path" ]; then
  echo "Live development mount: available"
  echo "Live development mount path: $mount_path"
  plugin_count="$(find "$mount_path" -mindepth 2 -maxdepth 2 -name plugin.json 2>/dev/null | wc -l | tr -d ' ')"
  echo "Discovered unpacked plugins: ${plugin_count:-0}"
else
  echo "Live development mount: unavailable"
  echo "Live development mount path: $mount_path"
fi

echo
echo "Backend API view:"
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();
$controller = $app->make('App\Http\Controllers\PluginController');
echo json_encode($controller->development(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
PHP
EOF
)"

    echo "Plugin CLI runtime: ${HOST_CONTAINER_RUNTIME}"

    if [[ "${HOST_CONTAINER_RUNTIME}" == 'podman' ]]; then
        echo "Plugin CLI podman mode: $([[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]] && printf 'rootful' || printf 'rootless')"
    fi

    case "$resolved_mode" in
        compose)
            echo "Backend resolution: compose (${resolved_environment})"
            echo "Backend service: backend"
            echo
            run_compose_backend_shell "$script"
            ;;
        direct)
            echo "Backend resolution: direct container"
            echo "Backend container: ${resolved_container}"
            echo "Backend runtime: ${resolved_runtime}"
            echo
            run_direct_container_shell "$resolved_runtime" "$resolved_container" "$script"
            ;;
        *)
            echo "Unsupported resolution mode: ${resolved_mode}" >&2
            return 1
            ;;
    esac
}

resolve_backend_target() {
    resolved_mode=''
    resolved_runtime=''
    resolved_container=''
    resolved_environment=''
    compose_available=1

    if [[ -n "$container_name" ]]; then
        detected_backend="$(detect_backend_container "$container_name" || true)"

        if [[ -z "$detected_backend" ]]; then
            echo "No running backend container matching '${container_name}' was detected." >&2
            return 1
        fi

        resolved_mode='direct'
        resolved_runtime="${detected_backend%%:*}"
        resolved_container="${detected_backend#*:}"

        return 0
    fi

    if [[ -z "$environment" ]]; then
        environment="$(detect_running_environment || true)"

        if [[ -z "$environment" ]]; then
            compose_available=0
        fi
    fi

    if [[ "$compose_available" == '1' ]]; then
        mapfile -t compose_args < <(compose_args_for_environment "$environment")

        if run_host_compose "${compose_args[@]}" ps -q backend 2>/dev/null | grep -q .; then
            resolved_mode='compose'
            resolved_environment="$environment"

            return 0
        fi
    fi

    detected_backend="$(detect_backend_container || true)"

    if [[ -z "$detected_backend" ]]; then
        echo 'No running WPrint 3D backend container was detected.' >&2
        echo 'Start the stack with ./run.sh, pass --environment when compose can see it, or pass --container for a root-owned stack.' >&2
        return 1
    fi

    resolved_mode='direct'
    resolved_runtime="${detected_backend%%:*}"
    resolved_container="${detected_backend#*:}"

    if [[ "$compose_available" == '0' ]] || [[ -z "$resolved_environment" ]]; then
        echo "Compose could not detect the running stack. Falling back to direct ${resolved_runtime} exec into ${resolved_container}." >&2
    fi

    return 0
}

environment=''
container_name=''
resolved_mode=''
resolved_runtime=''
resolved_container=''
resolved_environment=''
compose_available=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage
            exit 0
            ;;
        -e|--environment)
            if [[ $# -lt 2 ]]; then
                echo 'The environment option requires a value.' >&2
                exit 1
            fi

            environment="$2"
            shift 2
            ;;
        -c|--container)
            if [[ $# -lt 2 ]]; then
                echo 'The container option requires a value.' >&2
                exit 1
            fi

            container_name="$2"
            shift 2
            ;;
        *)
            break
            ;;
    esac
done

if [[ $# -lt 1 ]]; then
    usage
    exit 1
fi

command_name="$(normalize_plugin_command "$1")"
shift

init_container_runtime || exit 1
resolve_backend_target || exit 1

if [[ "$command_name" == 'status' ]]; then
    print_status_report
    exit $?
fi

if [[ "$resolved_mode" == 'compose' ]]; then

    exec_args=(exec)

    if [[ ! -t 0 || ! -t 1 ]]; then
        exec_args+=(-T)
    fi

    exec_args+=(backend php artisan "$command_name")

    if [[ $# -gt 0 ]]; then
        exec_args+=("$@")
    fi

    run_host_compose "${compose_args[@]}" "${exec_args[@]}"
    exit $?
fi

run_direct_container_exec "$resolved_runtime" "$resolved_container" "$@"
