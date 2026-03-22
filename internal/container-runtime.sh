#!/bin/bash

podman_auto_install_disabled() {
    [[ "${WPRINT3D_AUTO_INSTALL_PODMAN:-1}" == '0' ]];
}

podman_rootful_enabled() {
    [[ "${WPRINT3D_PODMAN_ROOTFUL:-1}" != '0' ]];
}

DETECTED_HOST_CONTAINER_RUNTIME='';
DETECTED_HOST_COMPOSE_COMMAND='';
HOST_PODMAN_ROOTFUL="${HOST_PODMAN_ROOTFUL:-0}";

has_graphical_session() {
    [[ -n "${DISPLAY:-}" || -n "${WAYLAND_DISPLAY:-}" ]];
}

detect_sudo_askpass_program() {
    local askpass_candidates=(
        "${SUDO_ASKPASS:-}"
        /usr/libexec/openssh/ssh-askpass
        /usr/bin/ssh-askpass
        /usr/bin/ksshaskpass
        /usr/bin/lxqt-openssh-askpass
        /usr/lib/ssh/x11-ssh-askpass
    );
    local askpass_program;

    for askpass_program in "${askpass_candidates[@]}"; do
        if [[ -n "$askpass_program" ]] && [[ -x "$askpass_program" ]]; then
            printf '%s\n' "$askpass_program";

            return 0;
        fi;
    done;

    return 1;
}

sudo_requires_authentication() {
    local stderr_output="${1:-}";

    [[ "$stderr_output" == *'a password is required'* ]] \
        || [[ "$stderr_output" == *'a terminal is required'* ]] \
        || [[ "$stderr_output" == *'no tty present and no askpass program specified'* ]] \
        || [[ "$stderr_output" == *'sorry, you must have a tty to run sudo'* ]];
}

RUN_WITH_SUDO_NONINTERACTIVE_AUTH_REQUIRED=0;

run_with_sudo_noninteractive() {
    local stderr_file;
    local exit_code;
    local stderr_output='';

    RUN_WITH_SUDO_NONINTERACTIVE_AUTH_REQUIRED=0;

    stderr_file="$(mktemp)";

    sudo -n "$@" 2> "$stderr_file";
    exit_code=$?;

    if [[ -f "$stderr_file" ]]; then
        stderr_output="$(cat "$stderr_file")";
        rm -f "$stderr_file";
    fi;

    if [[ "$exit_code" -eq 0 ]]; then
        return 0;
    fi;

    if sudo_requires_authentication "$stderr_output"; then
        RUN_WITH_SUDO_NONINTERACTIVE_AUTH_REQUIRED=1;

        return "$exit_code";
    fi;

    if [[ -n "$stderr_output" ]]; then
        printf '%s\n' "$stderr_output" >&2;
    fi;

    return "$exit_code";
}

apt_lock_is_held() {
    local apt_lock_paths=(
        /var/lib/dpkg/lock-frontend
        /var/lib/dpkg/lock
        /var/lib/apt/lists/lock
        /var/cache/apt/archives/lock
    );

    if command -v fuser > /dev/null 2>&1; then
        fuser "${apt_lock_paths[@]}" > /dev/null 2>&1;

        return $?;
    fi;

    if command -v lsof > /dev/null 2>&1; then
        lsof "${apt_lock_paths[@]}" > /dev/null 2>&1;

        return $?;
    fi;

    return 1;
}

wait_for_apt_lock() {
    local attempt=1;

    while [[ "$attempt" -le 20 ]]; do
        if ! apt_lock_is_held; then
            return 0;
        fi;

        if [[ "$attempt" -eq 20 ]]; then
            echo 'apt/dpkg is locked by another process. Podman setup timed out waiting for the package manager to become available.' >&2;

            return 1;
        fi;

        echo 'Waiting for apt/dpkg locks to clear before continuing Podman setup...' >&2;

        sleep 3;
        attempt=$((attempt + 1));
    done;
}

run_with_elevation() {
    local askpass_program;
    local sudo_exit_code;

    if [[ "${EUID:-1}" -eq 0 ]]; then
        "$@";

        return $?;
    fi;

    if command -v sudo > /dev/null 2>&1; then
        run_with_sudo_noninteractive "$@";
        sudo_exit_code=$?;

        if [[ "$sudo_exit_code" -eq 0 ]]; then
            return 0;
        fi;

        if [[ "${RUN_WITH_SUDO_NONINTERACTIVE_AUTH_REQUIRED:-0}" -ne 1 ]]; then
            return "$sudo_exit_code";
        fi;

        if { exec 3<> /dev/tty; } 2> /dev/null; then
            echo 'Administrator privileges are required to continue the automatic Podman setup. sudo will prompt for your password.' >&3;

            sudo -v <&3 >&3 || {
                exec 3<&-;
                exec 3>&-;

                return 1;
            };

            sudo "$@" <&3;

            local sudo_exit_code=$?;

            exec 3<&-;
            exec 3>&-;

            return $sudo_exit_code;
        fi;

        if has_graphical_session && askpass_program="$(detect_sudo_askpass_program)"; then
            echo "No interactive terminal is available. Requesting administrator privileges through ${askpass_program}..." >&2;

            SUDO_ASKPASS="$askpass_program" sudo -A "$@";

            return $?;
        fi;

        echo 'Automatic Podman setup needs sudo access, but no interactive terminal is available for a password prompt.' >&2;

        return 1;
    fi;

    if command -v doas > /dev/null 2>&1; then
        doas "$@";

        return $?;
    fi;

    echo 'Podman installation requires root privileges or a working sudo or doas command.' >&2;

    return 1;
}

prime_elevated_access() {
    local askpass_program;
    local sudo_exit_code;

    if [[ "${EUID:-1}" -eq 0 ]]; then
        return 0;
    fi;

    if command -v sudo > /dev/null 2>&1; then
        if [[ "$#" -gt 0 ]]; then
            run_with_sudo_noninteractive "$@" > /dev/null;
            sudo_exit_code=$?;

            if [[ "$sudo_exit_code" -eq 0 ]]; then
                return 0;
            fi;

            if [[ "${RUN_WITH_SUDO_NONINTERACTIVE_AUTH_REQUIRED:-0}" -ne 1 ]]; then
                return "$sudo_exit_code";
            fi;
        elif sudo -n true > /dev/null 2>&1; then
            return 0;
        fi;

        if { exec 3<> /dev/tty; } 2> /dev/null; then
            echo 'Administrator privileges will be required during startup. Authenticating now so the script can continue unattended.' >&3;

            sudo -v <&3 >&3;
            local sudo_exit_code=$?;

            exec 3<&-;
            exec 3>&-;

            return $sudo_exit_code;
        fi;

        if has_graphical_session && askpass_program="$(detect_sudo_askpass_program)"; then
            echo "Requesting administrator privileges through ${askpass_program} before startup continues..." >&2;

            SUDO_ASKPASS="$askpass_program" sudo -A -v;

            return $?;
        fi;
    fi;

    if command -v doas > /dev/null 2>&1; then
        echo 'Administrator privileges will be required during startup. Authenticating now so the script can continue unattended.' >&2;

        doas true;

        return $?;
    fi;

    echo 'Administrator privileges will be required later in startup, but no supported elevation method is available right now.' >&2;

    return 1;
}

enable_podman_user_socket() {
    local current_user="${SUDO_USER:-${USER:-}}";

    if [[ -z "$current_user" ]] && command -v id > /dev/null 2>&1; then
        current_user="$(id -un)";
    fi;

    if command -v loginctl > /dev/null 2>&1 && [[ -n "$current_user" ]]; then
        run_with_elevation loginctl enable-linger "$current_user" > /dev/null 2>&1 || true;
    fi;

    if command -v systemctl > /dev/null 2>&1; then
        systemctl --user enable --now podman.socket > /dev/null 2>&1 || true;
    fi;
}

install_podman_automatically() {
    if podman_auto_install_disabled; then
        return 1;
    fi;

    if command -v podman > /dev/null 2>&1; then
        return 0;
    fi;

    echo 'Podman is not installed. Attempting automatic installation...' >&2;

    if command -v apt-get > /dev/null 2>&1; then
        wait_for_apt_lock || return 1;
        run_with_elevation apt-get update || return 1;
        wait_for_apt_lock || return 1;
        run_with_elevation apt-get install -y podman podman-compose || return 1;
    elif command -v dnf > /dev/null 2>&1; then
        run_with_elevation dnf install -y podman podman-compose || return 1;
    elif command -v yum > /dev/null 2>&1; then
        run_with_elevation yum install -y podman podman-compose || return 1;
    elif command -v pacman > /dev/null 2>&1; then
        run_with_elevation pacman -Sy --noconfirm podman podman-compose || return 1;
    elif command -v zypper > /dev/null 2>&1; then
        run_with_elevation zypper --non-interactive install podman podman-compose || return 1;
    elif command -v apk > /dev/null 2>&1; then
        run_with_elevation apk add podman podman-compose || return 1;
    else
        echo 'Automatic Podman installation is not supported on this system: no supported package manager was found.' >&2;

        return 1;
    fi;

    if ! command -v podman > /dev/null 2>&1; then
        echo 'Podman installation completed but the podman binary is still not available in PATH.' >&2;

        return 1;
    fi;

    enable_podman_user_socket;

    echo 'Podman was installed successfully.' >&2;

    return 0;
}

install_podman_compose_automatically() {
    if podman_auto_install_disabled; then
        return 1;
    fi;

    if command -v podman-compose > /dev/null 2>&1; then
        return 0;
    fi;

    echo 'A native Podman compose provider is not available. Attempting to install podman-compose...' >&2;

    if command -v apt-get > /dev/null 2>&1; then
        wait_for_apt_lock || return 1;
        run_with_elevation apt-get install -y podman-compose || return 1;
    elif command -v dnf > /dev/null 2>&1; then
        run_with_elevation dnf install -y podman-compose || return 1;
    elif command -v yum > /dev/null 2>&1; then
        run_with_elevation yum install -y podman-compose || return 1;
    elif command -v pacman > /dev/null 2>&1; then
        run_with_elevation pacman -Sy --noconfirm podman-compose || return 1;
    elif command -v zypper > /dev/null 2>&1; then
        run_with_elevation zypper --non-interactive install podman-compose || return 1;
    elif command -v apk > /dev/null 2>&1; then
        run_with_elevation apk add podman-compose || return 1;
    else
        echo 'Automatic podman-compose installation is not supported on this system: no supported package manager was found.' >&2;

        return 1;
    fi;

    command -v podman-compose > /dev/null 2>&1;
}

podman_compose_uses_external_provider() {
    local compose_output;

    compose_output="$(podman compose version 2>&1)" || return 1;

    [[ "$compose_output" == *'Executing external compose provider'* ]];
}

detect_host_container_runtime() {
    if [[ -n "${HOST_CONTAINER_RUNTIME:-}" ]]; then
        if command -v "${HOST_CONTAINER_RUNTIME}" > /dev/null 2>&1; then
            DETECTED_HOST_CONTAINER_RUNTIME="${HOST_CONTAINER_RUNTIME}";

            return 0;
        fi;

        if [[ "${HOST_CONTAINER_RUNTIME}" == 'podman' ]] && install_podman_automatically; then
            DETECTED_HOST_CONTAINER_RUNTIME='podman';

            return 0;
        fi;

        echo "Requested container runtime '${HOST_CONTAINER_RUNTIME}' is not installed." >&2;

        return 1;
    fi;

    if command -v podman > /dev/null 2>&1; then
        DETECTED_HOST_CONTAINER_RUNTIME='podman';

        return 0;
    fi;

    if install_podman_automatically; then
        DETECTED_HOST_CONTAINER_RUNTIME='podman';

        return 0;
    fi;

    echo 'Podman is unavailable and the automatic installation attempt did not succeed.' >&2;

    return 1;
}

detect_host_compose_command() {
    local runtime="$1";
    local compose_output;

    case "$runtime" in
        podman)
            if command -v podman-compose > /dev/null 2>&1; then
                DETECTED_HOST_COMPOSE_COMMAND='podman-compose';

                return 0;
            fi;

            compose_output="$(run_podman_host_command compose version 2>&1)" || compose_output='';

            if [[ "$compose_output" != '' ]] && [[ "$compose_output" != *'Executing external compose provider'* ]]; then
                DETECTED_HOST_COMPOSE_COMMAND='podman compose';

                return 0;
            fi;

            if [[ "$compose_output" == *'Executing external compose provider'* ]]; then
                echo 'Detected a Docker-backed external compose provider behind `podman compose`.' >&2;
            fi;

            if install_podman_compose_automatically; then
                DETECTED_HOST_COMPOSE_COMMAND='podman-compose';

                return 0;
            fi;

            echo 'Podman is installed but no usable native compose provider was found.' >&2;

            return 1
            ;;
        docker)
            if docker compose version > /dev/null 2>&1; then
                DETECTED_HOST_COMPOSE_COMMAND='docker compose';

                return 0;
            fi;

            if command -v docker-compose > /dev/null 2>&1; then
                DETECTED_HOST_COMPOSE_COMMAND='docker-compose';

                return 0;
            fi;

            echo 'Docker is installed but no compose provider was found.' >&2;

            return 1
            ;;
    esac

    echo "Unsupported container runtime '${runtime}'." >&2;

    return 1;
}

configure_podman_host_access() {
    local rootless_output='';

    HOST_PODMAN_ROOTFUL=0;

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        export HOST_PODMAN_ROOTFUL;

        return 0;
    fi;

    if ! podman_rootful_enabled; then
        export HOST_PODMAN_ROOTFUL;

        return 0;
    fi;

    rootless_output="$(run_with_elevation podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null)" || {
        echo 'Rootful Podman is enabled for this project, but elevated Podman access could not be established.' >&2;
        echo 'Set WPRINT3D_PODMAN_ROOTFUL=0 if you need to opt back into rootless Podman.' >&2;

        return 1;
    };

    if [[ "$rootless_output" != 'false' ]]; then
        echo 'Elevated Podman access did not resolve to a rootful Podman service.' >&2;
        echo 'Set WPRINT3D_PODMAN_ROOTFUL=0 if you need to opt back into rootless Podman.' >&2;

        return 1;
    fi;

    HOST_PODMAN_ROOTFUL=1;
    export HOST_PODMAN_ROOTFUL;

    return 0;
}

ensure_podman_socket() {
    local socket_path="${CONTAINER_SOCKET_PATH:-}";

    if [[ -z "$socket_path" ]]; then
        socket_path="$(run_podman_host_command info --format '{{.Host.RemoteSocket.Path}}' 2> /dev/null)";
    fi;

    if [[ -z "$socket_path" ]]; then
        if [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
            socket_path='/run/podman/podman.sock';
        else
            socket_path="/run/user/$(id -u)/podman/podman.sock";
        fi;
    fi;

    export CONTAINER_SOCKET_PATH="$socket_path";

    if [[ -S "$CONTAINER_SOCKET_PATH" ]]; then
        return 0;
    fi;

    if [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_with_elevation mkdir -p "$(dirname "$CONTAINER_SOCKET_PATH")";

        if command -v systemctl > /dev/null 2>&1; then
            run_with_elevation systemctl daemon-reload > /dev/null 2>&1 || true;
            run_with_elevation systemctl enable --now podman.socket > /dev/null 2>&1 || true;
        fi;

        if [[ ! -S "$CONTAINER_SOCKET_PATH" ]]; then
            local stale_pid;
            stale_pid="$(pgrep -f "podman system service .*${CONTAINER_SOCKET_PATH}" 2>/dev/null)" && \
                run_with_elevation kill "$stale_pid" > /dev/null 2>&1 && sleep 0.5;

            run_with_elevation sh -c 'nohup podman system service --time=0 "unix://'"${CONTAINER_SOCKET_PATH}"'" > /tmp/wprint3d-podman-service.log 2>&1 &';
        fi;
    else
        mkdir -p "$(dirname "$CONTAINER_SOCKET_PATH")";

        if command -v systemctl > /dev/null 2>&1 && systemctl --user show-environment > /dev/null 2>&1; then
            systemctl --user start podman.socket > /dev/null 2>&1 || true;
        fi;

        if [[ ! -S "$CONTAINER_SOCKET_PATH" ]]; then
            local stale_pid;
            stale_pid="$(pgrep -f "podman system service .*${CONTAINER_SOCKET_PATH}" 2>/dev/null)" && \
                kill "$stale_pid" > /dev/null 2>&1 && sleep 0.5;

            nohup podman system service --time=0 "unix://${CONTAINER_SOCKET_PATH}" > /tmp/wprint3d-podman-service.log 2>&1 &
        fi;
    fi;

    # Let Podman handle its own socket availability and timeouts.
    # The service started above; Podman commands will fail appropriately
    # if the socket isn't ready.
    return 0;
}

init_container_runtime() {
    DETECTED_HOST_CONTAINER_RUNTIME='';
    detect_host_container_runtime || return 1;
    HOST_CONTAINER_RUNTIME="${DETECTED_HOST_CONTAINER_RUNTIME}";
    export HOST_CONTAINER_RUNTIME;
    configure_podman_host_access || return 1;

    if [[ -z "${HOST_COMPOSE_COMMAND:-}" ]]; then
        DETECTED_HOST_COMPOSE_COMMAND='';
        detect_host_compose_command "$HOST_CONTAINER_RUNTIME" || return 1;
        HOST_COMPOSE_COMMAND="${DETECTED_HOST_COMPOSE_COMMAND}";
    fi;
    export HOST_COMPOSE_COMMAND;

    read -r -a HOST_COMPOSE_COMMAND_ARGS <<< "$HOST_COMPOSE_COMMAND"

    case "$HOST_CONTAINER_RUNTIME" in
        podman)
            export CONTAINER_LOG_DRIVER="${CONTAINER_LOG_DRIVER:-k8s-file}"
            ensure_podman_socket || return 1
            ;;
        docker)
            export CONTAINER_LOG_DRIVER="${CONTAINER_LOG_DRIVER:-local}"
            export CONTAINER_SOCKET_PATH="${CONTAINER_SOCKET_PATH:-/var/run/docker.sock}"
            ;;
    esac

    export IN_CONTAINER_CLI="${IN_CONTAINER_CLI:-docker}"
    export IN_CONTAINER_COMPOSE_COMMAND="${IN_CONTAINER_COMPOSE_COMMAND:-docker-compose}"
}

frontend_node_modules_needs_permission_repair() {
    local node_modules_path="${1:-frontend/node_modules}";
    local current_user current_group;

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 1;
    fi;

    if [[ ! -d "$node_modules_path" ]]; then
        return 1;
    fi;

    current_user="$(id -un)";
    current_group="$(id -gn)";

    find "$node_modules_path" \( ! -user "$current_user" -o ! -group "$current_group" \) -print -quit 2> /dev/null | grep -q .;
}

repair_frontend_node_modules_permissions() {
    local node_modules_path="${1:-frontend/node_modules}";
    local current_user current_group;

    if ! frontend_node_modules_needs_permission_repair "$node_modules_path"; then
        return 0;
    fi;

    current_user="$(id -un)";
    current_group="$(id -gn)";

    echo "Repairing ${node_modules_path} ownership for Podman..." >&2;

    run_with_elevation chown -R "${current_user}:${current_group}" "$node_modules_path";
}

run_host_compose() {
    if [[ "${HOST_CONTAINER_RUNTIME:-}" == 'podman' ]] && [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_podman_rootful_command compose "$@";

        return $?;
    fi;

    "${HOST_COMPOSE_COMMAND_ARGS[@]}" "$@";
}

run_host_container_cli() {
    if [[ "${HOST_CONTAINER_RUNTIME:-}" == 'podman' ]] && [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_podman_rootful_command "$@";

        return $?;
    fi;

    "${HOST_CONTAINER_RUNTIME}" "$@";
}

run_podman_host_command() {
    if [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_podman_rootful_command "$@";

        return $?;
    fi;

    podman "$@";
}

run_podman_rootful_command() {
    local command="$1";
    shift;
    local var_name;
    local -a env_prefix=();
    local passthrough_vars=(
        PWD
        CONTAINER_SOCKET_PATH
        CONTAINER_LOG_DRIVER
        IN_CONTAINER_CLI
        IN_CONTAINER_COMPOSE_COMMAND
        HOST_CONTAINER_RUNTIME
        HOST_COMPOSE_COMMAND
        HOST_PODMAN_ROOTFUL
    );

    if [[ "$command" == 'compose' ]]; then
        if [[ "${HOST_COMPOSE_COMMAND:-}" == 'podman-compose' ]]; then
            env_prefix=();

            for var_name in "${passthrough_vars[@]}"; do
                if [[ -n "${!var_name+x}" ]]; then
                    env_prefix+=("${var_name}=${!var_name}");
                fi;
            done;

            run_with_elevation env "${env_prefix[@]}" podman-compose "$@";

            return $?;
        fi;

        run_with_elevation podman compose "$@";

        return $?;
    fi;

    run_with_elevation podman "$command" "$@";
}

migrate_docker_volumes_to_podman() {
    local env="${1:-production}";

    # Guard 1: only run when Podman is the active runtime.
    echo 'Migrating Docker volumes to Podman if necessary...';
    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        echo "Warning: Container runtime is '${HOST_CONTAINER_RUNTIME:-unknown}', not Podman; skipping volume migration." >&2;

        return 0;
    fi;

    # Guard 2: Docker CLI must be available.
    echo 'Checking for Docker CLI availability for volume migration...';
    if ! command -v docker > /dev/null 2>&1; then
        echo 'Warning: Docker CLI not found; skipping volume migration.' >&2;

        return 0;
    fi;

    # Guard 3: Docker daemon must be reachable (no timeout).
    echo 'Checking Docker daemon connectivity for volume migration...';
    if ! docker info > /dev/null 2>&1; then
        echo 'Warning: Docker daemon is not reachable; skipping volume migration.' >&2;

        return 0;
    fi;

    # Bring down any running Docker services so their ports are free for Podman.
    local compose_file='docker-compose.yml';

    if [[ "$env" == 'dev' ]]; then
        compose_file='docker-compose-development.yml';
    fi;

    if [[ -f "${SCRIPT_PATH}/${compose_file}" ]]; then
        if docker compose -f "${SCRIPT_PATH}/${compose_file}" ps -q 2>/dev/null | grep -q .; then
            echo 'Stopping running Docker services before migration...';

            docker compose -f "${SCRIPT_PATH}/${compose_file}" down 2>&1 || true;
        fi;
    fi;

    # Resolve volume name prefixes.
    local src_prefix="${WPRINT3D_DOCKER_PROJECT_NAME:-wprint3d}";
    local dst_prefix="${COMPOSE_PROJECT_NAME:-$(basename "$SCRIPT_PATH")}";

    # Normalize dst_prefix to match Compose's project name derivation:
    # lowercase and strip any leading non-alphanumeric characters.
    dst_prefix="${dst_prefix,,}";
    while [[ -n "$dst_prefix" ]] && [[ "${dst_prefix:0:1}" =~ [^a-zA-Z0-9] ]]; do
        dst_prefix="${dst_prefix:1}";
    done;

    # Validate / normalise $env argument.
    case "$env" in
        dev|production)
            ;;
        *)
            echo "Warning: Unknown environment '${env}' passed to migrate_docker_volumes_to_podman. Treating as 'production'." >&2;
            env='production';
            ;;
    esac;

    # Build the list of volume base names to migrate.
    local volumes=('mongo');

    if [[ "$env" == 'production' ]]; then
        volumes+=('storage' 'proxy' 'startup');
    fi;

    # Pre-flight: collect only the volumes that actually need migration.
    local vol src dst;
    local pending_src=() pending_dst=();

    for vol in "${volumes[@]}"; do
        src="${src_prefix}_${vol}";
        dst="${dst_prefix}_${vol}";

        run_host_container_cli volume inspect "$dst" > /dev/null 2>&1 && continue;
        docker volume inspect "$src" > /dev/null 2>&1 || continue;

        pending_src+=("$src");
        pending_dst+=("$dst");
    done;

    local total="${#pending_src[@]}";

    if [[ "$total" -eq 0 ]]; then
        return 0;
    fi;

    local i;

    for (( i = 0; i < total; i++ )); do
        src="${pending_src[$i]}";
        dst="${pending_dst[$i]}";

        printf '[ %d / %d ] Migrating volume %s...\n' "$((i + 1))" "$total" "$src";

        # Create the target Podman volume (stdout suppressed; stderr intentionally left open).
        if ! run_host_container_cli volume create "$dst" > /dev/null; then
            echo "ERROR: Failed to create Podman volume '${dst}'." >&2;

            return 1;
        fi;

        # Pipe data via throwaway busybox containers.
        # PIPESTATUS[1] is reliable because run_host_container_cli ends with the
        # underlying CLI call as its last executed statement.
        docker run --rm \
            -v "${src}:/src" \
            docker.io/library/busybox:1.36 \
            tar -cC /src . \
          | run_host_container_cli run --rm -i \
            -v "${dst}:/dst" \
            docker.io/library/busybox:1.36 \
            tar -xC /dst;

        local pipe_status=("${PIPESTATUS[@]}");

        if [[ "${pipe_status[0]}" -ne 0 ]] || [[ "${pipe_status[1]}" -ne 0 ]]; then
            # Attempt cleanup of the partially-written target volume.
            if run_host_container_cli volume rm "$dst" > /dev/null 2>&1; then
                echo "ERROR: Migration of Docker volume '${src}' to Podman volume '${dst}' failed." >&2;
                echo 'The incomplete target volume has been removed. Re-run the script to retry.' >&2;
            else
                echo "ERROR: Migration of Docker volume '${src}' to Podman volume '${dst}' failed," >&2;
                echo 'and the incomplete target volume could not be removed automatically.' >&2;
                echo 'You must remove it manually before re-running:' >&2;
                echo "  podman volume rm ${dst}" >&2;
            fi;

            return 1;
        fi;

        echo "Migrated Docker volume '${src}' to Podman volume '${dst}'.";
    done;
}

force_cleanup_stuck_containers() {
    # Force-kill and remove ALL project containers to ensure clean slate
    # This handles Podman's corrupted container state after server restart
    # by using container IDs directly instead of names, bypassing name-based lookups
    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 0;
    fi

    # Derive the Compose project name
    local compose_project="${COMPOSE_PROJECT_NAME:-$(basename "$SCRIPT_PATH")}";
    compose_project="${compose_project,,}";
    while [[ -n "$compose_project" ]] && [[ "${compose_project:0:1}" =~ [^a-zA-Z0-9] ]]; do
        compose_project="${compose_project:1}";
    done

    # Get ALL containers for this project (using both ID and name for robustness)
    local container_ids=();
    local container_names=();
    local cid cname;

    while IFS='|' read -r cid cname; do
        [[ -z "$cid" ]] && continue;
        container_ids+=("$cid");
        [[ -n "$cname" ]] && container_names+=("$cname");
    done < <(run_host_container_cli ps -a --filter "label=com.docker.compose.project=${compose_project}" \
              --format '{{ .ID }}|{{ .Names }}' 2>/dev/null)

    if [[ ${#container_ids[@]} -eq 0 ]]; then
        return 0;
    fi

    echo "Force cleaning up ${#container_ids[@]} project container(s)..."

    # Step 1: Try container prune first - handles abnormal states better
    run_host_container_cli container prune -f > /dev/null 2>&1 || true

    # Re-check after prune - some containers might have been removed
    local pruned_ids=()
    while IFS='|' read -r cid cname; do
        [[ -z "$cid" ]] && continue;
        pruned_ids+=("$cid");
    done < <(run_host_container_cli ps -a --filter "label=com.docker.compose.project=${compose_project}" \
              --format '{{ .ID }}|{{ .Names }}' 2>/dev/null)

    if [[ ${#pruned_ids[@]} -eq 0 ]]; then
        echo "All containers removed via prune."
        return 0
    fi

    echo "Found ${#pruned_ids[@]} remaining container(s) after prune..."

    # Step 2: Force kill using container IDs (bypasses name lookup issues)
    for cid in "${pruned_ids[@]}"; do
        run_host_container_cli kill "$cid" > /dev/null 2>&1 || true
    done

    # Step 3: Wait for termination, then force remove using IDs
    local wait_count=0
    local max_wait=15
    while [[ $wait_count -lt $max_wait ]]; do
        local remaining=0
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue;
            remaining=$((remaining + 1))
        done < <(run_host_container_cli ps -a --filter "label=com.docker.compose.project=${compose_project}" \
                  --format '{{ .ID }}' 2>/dev/null)

        if [[ $remaining -eq 0 ]]; then
            break
        fi
        sleep 1
        wait_count=$((wait_count + 1))
    done

    # Step 4: Force remove using container IDs directly (bypasses state checks)
    for cid in "${pruned_ids[@]}"; do
        run_host_container_cli rm -f "$cid" > /dev/null 2>&1 || true
    done

    # Step 5: Final cleanup - check what remains
    local remaining=0
    local remaining_ids=()
    local remaining_names=()
    while IFS='|' read -r cid cname; do
        [[ -z "$cid" ]] && continue;
        remaining=$((remaining + 1))
        remaining_ids+=("$cid")
        remaining_names+=("$cname")
    done < <(run_host_container_cli ps -a --filter "label=com.docker.compose.project=${compose_project}" \
              --format '{{ .ID }}|{{ .Names }}' 2>/dev/null)

    if [[ $remaining -gt 0 ]]; then
        echo "Warning: $remaining container(s) still stuck in libpod database after all cleanup attempts."
        echo "Stuck containers:"
        local i
        for ((i=0; i<remaining; i++)); do
            echo "  - ${remaining_ids[$i]} (${remaining_names[$i]:-unknown})"
        done

        # Nuclear option: force-remove ALL containers on the system
        # This is necessary for headless devices where manual intervention is not possible
        echo "Attempting nuclear cleanup (removing all containers)..."
        run_host_container_cli rm -f --all > /dev/null 2>&1 || true
    fi

    echo "Cleanup complete."
}

ensure_podman_forward_rules() {
    # Only needed for rootful Podman — rootless uses a user-space proxy that
    # binds host-side sockets and never touches the FORWARD chain.
    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 0;
    fi;

    if ! podman_rootful_enabled; then
        return 0;
    fi;

    # The NETAVARK_FORWARD chain is created by netavark when containers start.
    # If it doesn't exist yet there is nothing to fix.
    if ! run_with_elevation iptables -t filter -L NETAVARK_FORWARD > /dev/null 2>&1; then
        return 0;
    fi;

    # Derive the Compose project name the same way migrate_docker_volumes_to_podman does.
    local compose_project="${COMPOSE_PROJECT_NAME:-$(basename "$SCRIPT_PATH")}";
    compose_project="${compose_project,,}";
    while [[ -n "$compose_project" ]] && [[ "${compose_project:0:1}" =~ [^a-zA-Z0-9] ]]; do
        compose_project="${compose_project:1}";
    done;

    # Resolve the podman network name (Compose uses <project>_default).
    local network_name="${compose_project}_default";

    # Get the subnet for this network so the ACCEPT rule is scoped correctly.
    local subnet;
    subnet=$(run_host_container_cli network inspect "$network_name" 2>/dev/null \
        | python3 -c "import json,sys; d=json.load(sys.stdin)[0]; print(d['subnets'][0]['subnet'])" 2>/dev/null);

    if [[ -z "$subnet" ]]; then
        return 0;
    fi;

    # netavark 1.4.x creates DNAT rules in PREROUTING for externally-arriving
    # packets but omits FORWARD ACCEPT rules for state NEW.  External traffic
    # (e.g. from a device on the LAN) therefore reaches the FORWARD chain with
    # no matching rule and is silently dropped.  Insert the missing rule once.
    if ! run_with_elevation iptables -t filter -C NETAVARK_FORWARD \
            -d "$subnet" -j ACCEPT > /dev/null 2>&1; then
        run_with_elevation iptables -t filter -I NETAVARK_FORWARD 1 \
            -d "$subnet" -j ACCEPT;
    fi;
}
