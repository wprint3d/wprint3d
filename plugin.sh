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
  ./plugin.sh pack examples/plugins/hello-world --wizard
  ./plugin.sh pack examples/plugins/hello-world --signing-key ~/.config/wprint3d/plugin-signing/hello-world.pem
  ./plugin.sh verify examples/plugins/hello-world/builds/hello-world.w3dp --require-trusted
  ./plugin.sh restore examples/plugins/hello-world/builds/hello-world.w3dp --output plugins/hello-world-fork
  ./plugin.sh keygen
  ./plugin.sh install /tmp/hello-world.w3dp
  ./plugin.sh list
  ./plugin.sh --container wprint3d-core-backend-1 make

The command can be provided as:
  status      -> backend/plugin diagnostics
  make        -> php artisan plugin:make
  pack        -> php artisan plugin:pack
  verify      -> php artisan plugin:verify
  restore     -> php artisan plugin:restore
  install     -> php artisan plugin:install
  keygen      -> interactive signing-key generation wizard
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
        make|pack|publish|search|install|list|enable|disable|remove|update|doctor|verify|restore)
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

run_backend_shell() {
    local script="$1"

    case "$resolved_mode" in
        compose)
            run_compose_backend_shell "$script"
            ;;
        direct)
            run_direct_container_shell "$resolved_runtime" "$resolved_container" "$script"
            ;;
        *)
            echo "Unsupported resolution mode: ${resolved_mode}" >&2
            return 1
            ;;
    esac
}

run_backend_artisan_command() {
    local artisan_command="$1"
    shift

    if [[ "$resolved_mode" == 'compose' ]]; then
        local exec_args=(exec)

        if [[ ! -t 0 || ! -t 1 ]]; then
            exec_args+=(-T)
        fi

        exec_args+=(backend php artisan "$artisan_command")

        if [[ $# -gt 0 ]]; then
            exec_args+=("$@")
        fi

        run_host_compose "${compose_args[@]}" "${exec_args[@]}"

        return $?
    fi

    local previous_command_name="${command_name:-}"
    command_name="$artisan_command"
    run_direct_container_exec "$resolved_runtime" "$resolved_container" "$@"
    local exit_code=$?
    command_name="$previous_command_name"

    return $exit_code
}

backend_container_ref() {
    case "$resolved_mode" in
        compose)
            run_host_compose "${compose_args[@]}" ps -q backend | head -n 1
            ;;
        direct)
            printf '%s\n' "$resolved_container"
            ;;
        *)
            return 1
            ;;
    esac
}

backend_runtime_for_copy() {
    if [[ "$resolved_mode" == 'compose' ]]; then
        printf '%s\n' "$HOST_CONTAINER_RUNTIME"

        return 0
    fi

    printf '%s\n' "$resolved_runtime"
}

copy_file_to_backend() {
    local host_path="$1"
    local container_path="$2"
    local container_ref
    local runtime

    container_ref="$(backend_container_ref)"
    runtime="$(backend_runtime_for_copy)"

    if [[ -z "$container_ref" || -z "$runtime" ]]; then
        echo 'Unable to resolve a backend container for file copy.' >&2
        return 1
    fi

    runtime_container_cli "$runtime" cp "$host_path" "${container_ref}:${container_path}"
}

remove_file_from_backend() {
    local container_path="$1"

    run_backend_shell "rm -f '$container_path'"
}

interactive_shell_available() {
    [[ -t 0 && -t 1 ]]
}

sanitize_filename_component() {
    local value="$1"

    value="${value//[^A-Za-z0-9._-]/-}"
    value="${value##-}"
    value="${value%%-}"

    if [[ -z "$value" ]]; then
        value='signing-key'
    fi

    printf '%s\n' "$value"
}

prompt_with_default() {
    local message="$1"
    local default_value="${2:-}"
    local answer

    if [[ -n "$default_value" ]]; then
        read -r -p "${message} [${default_value}]: " answer
        printf '%s\n' "${answer:-$default_value}"

        return 0
    fi

    read -r -p "${message}: " answer
    printf '%s\n' "$answer"
}

prompt_secret() {
    local message="$1"
    local answer

    read -r -s -p "${message}: " answer
    echo
    printf '%s\n' "$answer"
}

prompt_yes_no() {
    local message="$1"
    local default_answer="${2:-y}"
    local suffix='[y/N]'
    local answer

    if [[ "$default_answer" == 'y' ]]; then
        suffix='[Y/n]'
    fi

    read -r -p "${message} ${suffix} " answer
    answer="${answer:-$default_answer}"
    answer="$(printf '%s' "$answer" | tr '[:upper:]' '[:lower:]')"

    [[ "$answer" == 'y' || "$answer" == 'yes' ]]
}

print_key_security_guidance() {
    local private_key_path="$1"
    local public_key_path="$2"

    cat <<EOF

Signing key generated:
  Private key: ${private_key_path}
  Public key:  ${public_key_path}

Keep the private key outside the plugin directory and out of git.
Back it up in at least one secure location such as an encrypted password manager, hardware token export, or offline encrypted backup.
If you lose the private key, you cannot prove signer continuity for future releases of that plugin.

To restore from a backup later:
  1. Copy the private key PEM back to a secure path on your workstation.
  2. Restrict it with: chmod 600 /path/to/key.pem
  3. Regenerate the public key if needed:
     openssl pkey -in /path/to/key.pem -pubout -out /path/to/key.pub.pem
EOF
}

generate_signing_key() {
    local private_key_path="$1"
    local protect_with_passphrase="${2:-1}"
    local public_key_path="${private_key_path%.pem}.pub.pem"
    local -a genpkey_args=(genpkey -algorithm RSA -out "$private_key_path" -pkeyopt rsa_keygen_bits:4096)
    local -a pkey_args=(pkey -in "$private_key_path" -pubout -out "$public_key_path")

    mkdir -p "$(dirname "$private_key_path")"

    if [[ -e "$private_key_path" || -e "$public_key_path" ]]; then
        echo "Key output already exists at ${private_key_path} or ${public_key_path}." >&2
        return 1
    fi

    if [[ "$protect_with_passphrase" == '1' ]]; then
        genpkey_args=(genpkey -algorithm RSA -aes-256-cbc -out "$private_key_path" -pkeyopt rsa_keygen_bits:4096)
    fi

    chmod 700 "$(dirname "$private_key_path")" 2>/dev/null || true
    openssl "${genpkey_args[@]}"
    chmod 600 "$private_key_path" 2>/dev/null || true
    openssl "${pkey_args[@]}"
    chmod 644 "$public_key_path" 2>/dev/null || true

    print_key_security_guidance "$private_key_path" "$public_key_path" >&2
    printf '%s\n' "$private_key_path"
}

run_keygen_wizard() {
    local requested_output="${1:-}"
    local output_path="$requested_output"
    local key_dir key_name protect_with_passphrase

    if ! command_exists openssl; then
        echo 'openssl is required to generate plugin signing keys.' >&2
        return 1
    fi

    if [[ -z "$output_path" ]]; then
        if ! interactive_shell_available; then
            echo 'Non-interactive key generation requires --output /path/to/key.pem.' >&2
            return 1
        fi

        key_dir="$(prompt_with_default 'Directory to store the signing key' "${HOME}/.config/wprint3d/plugin-signing")"
        key_name="$(prompt_with_default 'Signing key filename' 'plugin-signing.pem')"
        output_path="${key_dir}/$(sanitize_filename_component "$key_name")"

        if [[ "$output_path" != *.pem ]]; then
            output_path="${output_path}.pem"
        fi
    fi

    protect_with_passphrase=0
    if interactive_shell_available; then
        protect_with_passphrase=1
        if ! prompt_yes_no 'Protect the private key with a passphrase?' 'y'; then
            protect_with_passphrase=0
        fi
    fi

    generate_signing_key "$output_path" "$protect_with_passphrase" >/dev/null
    echo "Generated signing key at ${output_path}" >&2
    printf '%s\n' "$output_path"
}

collect_pack_wizard_inputs() {
    local plugin_source="$1"
    local default_key_path base_name

    if ! interactive_shell_available; then
        echo 'The pack wizard requires an interactive terminal. Use --signing-key directly in non-interactive environments.' >&2
        return 1
    fi

    if [[ -n "$pack_signing_key" ]]; then
        return 0
    fi

    if ! prompt_yes_no "Sign the package for ${plugin_source}?" 'y'; then
        return 0
    fi

    base_name="$(sanitize_filename_component "$(basename "$plugin_source")")"
    default_key_path="${HOME}/.config/wprint3d/plugin-signing/${base_name}.pem"

    if prompt_yes_no 'Generate a new signing key now?' 'n'; then
        pack_signing_key="$(run_keygen_wizard "$default_key_path")"
    else
        pack_signing_key="$(prompt_with_default 'Existing private key path' "$default_key_path")"
    fi

    if [[ -z "$pack_passphrase" && -z "$pack_passphrase_file" ]] && prompt_yes_no 'Does this private key use a passphrase?' 'n'; then
        pack_passphrase="$(prompt_secret 'Private key passphrase')"
    fi
}

parse_pack_arguments() {
    pack_wizard=0
    pack_source=''
    pack_signing_key=''
    pack_passphrase=''
    pack_passphrase_file=''
    pack_forward_args=()

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --wizard)
                pack_wizard=1
                shift
                ;;
            --signing-key=*)
                pack_signing_key="${1#*=}"
                shift
                ;;
            --signing-key)
                if [[ $# -lt 2 ]]; then
                    echo 'The --signing-key option requires a value.' >&2
                    return 1
                fi
                pack_signing_key="$2"
                shift 2
                ;;
            --passphrase=*)
                pack_passphrase="${1#*=}"
                shift
                ;;
            --passphrase)
                if [[ $# -lt 2 ]]; then
                    echo 'The --passphrase option requires a value.' >&2
                    return 1
                fi
                pack_passphrase="$2"
                shift 2
                ;;
            --passphrase-file=*)
                pack_passphrase_file="${1#*=}"
                shift
                ;;
            --passphrase-file)
                if [[ $# -lt 2 ]]; then
                    echo 'The --passphrase-file option requires a value.' >&2
                    return 1
                fi
                pack_passphrase_file="$2"
                shift 2
                ;;
            *)
                pack_forward_args+=("$1")
                if [[ -z "$pack_source" && "$1" != --* ]]; then
                    pack_source="$1"
                fi
                shift
                ;;
        esac
    done
}

handle_pack_command() {
    local original_args=("$@")
    local -a final_args=()
    local -a backend_cleanup_files=()
    local host_temp_passphrase=''
    local staged_signing_key=''
    local staged_passphrase_file=''
    local signing_key_name=''
    local passphrase_name=''
    local exit_code=0

    parse_pack_arguments "${original_args[@]}" || return 1

    if [[ "$pack_wizard" == '1' ]]; then
        if [[ -z "$pack_source" ]]; then
            echo 'The pack wizard requires a plugin source path, for example: ./plugin.sh pack examples/plugins/hello-world --wizard' >&2
            return 1
        fi

        collect_pack_wizard_inputs "$pack_source" || return 1
    fi

    final_args=("${pack_forward_args[@]}")

    if [[ -n "$pack_passphrase" && -n "$pack_passphrase_file" ]]; then
        echo 'Use either --passphrase or --passphrase-file, not both.' >&2
        return 1
    fi

    if [[ -n "$pack_passphrase" ]]; then
        host_temp_passphrase="$(mktemp)"
        chmod 600 "$host_temp_passphrase" 2>/dev/null || true
        printf '%s' "$pack_passphrase" > "$host_temp_passphrase"
        pack_passphrase_file="$host_temp_passphrase"
    fi

    if [[ -n "$pack_passphrase_file" ]]; then
        if [[ ! -f "$pack_passphrase_file" ]]; then
            echo "Signing passphrase file not found: ${pack_passphrase_file}" >&2
            [[ -n "$host_temp_passphrase" ]] && rm -f "$host_temp_passphrase"
            return 1
        fi
    fi

    if [[ -n "$pack_signing_key" ]]; then
        if [[ ! -f "$pack_signing_key" ]]; then
            echo "Signing key not found: ${pack_signing_key}" >&2
            [[ -n "$host_temp_passphrase" ]] && rm -f "$host_temp_passphrase"
            return 1
        fi

        signing_key_name="$(sanitize_filename_component "$(basename "$pack_signing_key")")"
        staged_signing_key="/tmp/wprint3d-plugin-signing-$$-${signing_key_name}"
        if ! copy_file_to_backend "$pack_signing_key" "$staged_signing_key"; then
            [[ -n "$host_temp_passphrase" ]] && rm -f "$host_temp_passphrase"
            return 1
        fi
        backend_cleanup_files+=("$staged_signing_key")
        final_args+=(--signing-key="$staged_signing_key")
    fi

    if [[ -n "$pack_passphrase_file" ]]; then

        passphrase_name="$(sanitize_filename_component "$(basename "$pack_passphrase_file")")"
        staged_passphrase_file="/tmp/wprint3d-plugin-passphrase-$$-${passphrase_name}"
        if ! copy_file_to_backend "$pack_passphrase_file" "$staged_passphrase_file"; then
            for staged_file in "${backend_cleanup_files[@]}"; do
                remove_file_from_backend "$staged_file" || true
            done
            [[ -n "$host_temp_passphrase" ]] && rm -f "$host_temp_passphrase"
            return 1
        fi
        backend_cleanup_files+=("$staged_passphrase_file")
        final_args+=(--passphrase-file="$staged_passphrase_file")
    fi

    run_backend_artisan_command 'plugin:pack' "${final_args[@]}" || exit_code=$?

    for staged_file in "${backend_cleanup_files[@]}"; do
        remove_file_from_backend "$staged_file" || true
    done

    if [[ -n "$host_temp_passphrase" ]]; then
        rm -f "$host_temp_passphrase"
    fi

    return $exit_code
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

case "$command_name" in
    keygen)
        if [[ $# -eq 0 ]]; then
            run_keygen_wizard >/dev/null
            exit $?
        fi

        if [[ $# -eq 1 && "$1" == --output=* ]]; then
            run_keygen_wizard "${1#*=}" >/dev/null
            exit $?
        fi

        if [[ $# -eq 2 && "$1" == '--output' ]]; then
            run_keygen_wizard "$2" >/dev/null
            exit $?
        fi

        echo 'Usage: ./plugin.sh keygen [--output /path/to/key.pem]' >&2
        exit 1
        ;;
esac

init_container_runtime || exit 1
resolve_backend_target || exit 1

if [[ "$command_name" == 'status' ]]; then
    print_status_report
    exit $?
fi
case "$command_name" in
    plugin:pack)
        handle_pack_command "$@"
        exit $?
        ;;
    *)
        run_backend_artisan_command "$command_name" "$@"
        exit $?
        ;;
esac
