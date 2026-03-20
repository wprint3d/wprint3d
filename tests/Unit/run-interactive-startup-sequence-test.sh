#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

assert_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected output to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Did not expect output to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

container_lookup_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_DISABLE_SCREEN_CLEAR=1 \
    bash -c '
        source "$1"

        HOST_CONTAINER_RUNTIME=podman
        HOST_COMPOSE_COMMAND=podman-compose

        run_host_compose() {
            printf "compose %s\n" "$*" >> "$COMMAND_LOG"
        }

        run_host_container_cli() {
            printf "container %s\n" "$*" >> "$COMMAND_LOG"

            if [[ "$1" == "ps" ]] && [[ "$2" == "-a" ]]; then
                printf "redis-cid\n"
                return 0
            fi

            return 0
        }

        printf "cid=%s\n" "$(wprint3d_container_id_for_service redis docker-compose-development.yml)"
        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$container_lookup_output" "cid=redis-cid"
assert_contains "$container_lookup_output" "container ps -a --filter label=com.docker.compose.project="
assert_contains "$container_lookup_output" "label=com.docker.compose.service=redis"

build_capture_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    touch "$TEMP_DIR/docker-compose-development.yml"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        noisy_command() {
            printf "STEP 1/24: FROM docker.io/library/php:8.4.12-bookworm\n"
            printf "STEP 2/24: RUN apt-get update\n"
        }

        command_output="$(wprint3d_run_captured_command noisy_command 2>&1)"

        printf "captured=%s\n" "$command_output"
        printf "events=%s\n" "${WPRINT3D_EVENT_LOG[*]}"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$build_capture_output" "captured="
assert_not_contains "$build_capture_output" "captured=STEP 1/24: FROM docker.io/library/php:8.4.12-bookworm"
assert_contains "$build_capture_output" "events="
assert_contains "$build_capture_output" "STEP 1/24: FROM docker.io/library/php:8.4.12-bookworm"

sanitized_capture_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        noisy_command() {
            printf "\rSTEP 11/24: RUN BUILD_TIME_DEPS=build-essential\r\n"
            printf "\033[2K--> Using cache abcdef123456\n"
        }

        wprint3d_run_captured_command noisy_command

        printf "events=%s\n" "${WPRINT3D_EVENT_LOG[*]}"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$sanitized_capture_output" "STEP 11/24: RUN BUILD_TIME_DEPS=build-essential"
assert_contains "$sanitized_capture_output" "--> Using cache abcdef123456"
assert_not_contains "$sanitized_capture_output" $'\r'
assert_not_contains "$sanitized_capture_output" $'\033[2K'

normalized_capture_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        noisy_command() {
            printf "%s\n" "podman-compose version: 1.0.6"
            printf "%s\n" "['podman', '--version', '']"
            printf "%s\n" "using podman version: 4.9.3"
            printf "%s\n" "8a2a78bcff2aaf4de0598b04deee534ff793472bd4a36a0408d612deca517825"
            printf "%s\n" "--> 0ae23b1fd5db"
            printf "%s\n" "--> Using cache 0ae23b1fd5db620ba00f165e44c7ab8388690d7476662506b102d4682bb73ed3"
            printf "%s\n" "exit code: 0"
            printf "%s\n" "STEP 1/24: FROM docker.io/library/php:8.4.12-bookworm"
        }

        wprint3d_run_captured_command noisy_command

        printf "events=%s\n" "${WPRINT3D_EVENT_LOG[*]}"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_not_contains "$normalized_capture_output" "8a2a78bcff2aaf4de0598b04deee534ff793472bd4a36a0408d612deca517825"
assert_not_contains "$normalized_capture_output" "--> 0ae23b1fd5db"
assert_not_contains "$normalized_capture_output" "podman-compose version: 1.0.6"
assert_not_contains "$normalized_capture_output" "['podman', '--version', '']"
assert_not_contains "$normalized_capture_output" "using podman version: 4.9.3"
assert_not_contains "$normalized_capture_output" "exit code: 0"
assert_contains "$normalized_capture_output" "--> Using cache 0ae23b1fd5db620ba00f165e44c7ab8388690d7476662506b102d4682bb73ed3"
assert_contains "$normalized_capture_output" "STEP 1/24: FROM docker.io/library/php:8.4.12-bookworm"

captured_nonzero_exit_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        noisy_command() {
            printf "%s\n" "podman start wprint3d-core_redis_1"
            printf "%s\n" "Error: no container with name or ID \"wprint3d-core_redis_1\" found: no such container"
            printf "%s\n" "exit code: 125"
            return 0
        }

        wprint3d_run_captured_command noisy_command
        printf "status=%s\n" "$?"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$captured_nonzero_exit_output" "status=125"

pty_capture_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    cat > "$TEMP_DIR/fake-compose" <<'EOF'
#!/bin/bash
printf 'pull-start\n'
sleep 0.1
printf 'pull-finished\n'
EOF
    chmod +x "$TEMP_DIR/fake-compose"

    PATH="$TEMP_DIR:$PATH" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        script() {
            if [[ "$1" != "-qefc" ]]; then
                return 1
            fi

            bash -lc "$2"
        }

        wprint3d_run_captured_command fake-compose

        printf "events=%s\n" "${WPRINT3D_EVENT_LOG[*]}"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$pty_capture_output" "pull-start"
assert_contains "$pty_capture_output" "pull-finished"

pty_burst_capture_output="$(
    TEMP_DIR="$(mktemp -d)"
    RENDER_LOG="$TEMP_DIR/render.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    cat > "$TEMP_DIR/fake-burst" <<'EOF'
#!/bin/bash
for i in 1 2 3 4 5; do
    printf 'burst-%s\n' "$i"
done
sleep 0.2
EOF
    chmod +x "$TEMP_DIR/fake-burst"

    PATH="$TEMP_DIR:$PATH" \
    RENDER_LOG="$RENDER_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1

        script() {
            if [[ "$1" != "-qefc" ]]; then
                return 1
            fi

            bash -lc "$2"
        }

        first_render=1
        wprint3d_render_control_panel() {
            if [[ "$first_render" -eq 1 ]]; then
                printf "first_render_events=%s\n" "${#WPRINT3D_EVENT_LOG[@]}" >> "$RENDER_LOG"
                first_render=0
            fi
        }

        wprint3d_run_captured_command fake-burst

        cat "$RENDER_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$pty_burst_capture_output" "first_render_events=5"

quiet_pty_animation_output="$(
    TEMP_DIR="$(mktemp -d)"
    RENDER_LOG="$TEMP_DIR/render.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    cat > "$TEMP_DIR/fake-quiet" <<'EOF'
#!/bin/bash
sleep 0.35
printf 'done\n'
EOF
    chmod +x "$TEMP_DIR/fake-quiet"

    PATH="$TEMP_DIR:$PATH" \
    RENDER_LOG="$RENDER_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_PANEL_SHOW_MENU=0

        script() {
            if [[ "$1" != "-qefc" ]]; then
                return 1
            fi

            bash -lc "$2"
        }

        wprint3d_render_control_panel() {
            printf "render\n" >> "$RENDER_LOG"
        }

        wprint3d_run_captured_command fake-quiet

        printf "renders=%s\n" "$(wc -l < "$RENDER_LOG" | tr -d " ")"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$quiet_pty_animation_output" "renders="
render_count="${quiet_pty_animation_output#renders=}"
if [[ "$render_count" -lt 2 ]]; then
    echo "Expected at least 2 renders during quiet PTY capture, got $render_count" >&2
    exit 1
fi

interrupt_output="$(
    TEMP_DIR="$(mktemp -d)"
    INTERRUPT_LOG="$TEMP_DIR/interrupt.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    cat > "$TEMP_DIR/fake-interruptible" <<'EOF'
#!/bin/bash
trap 'printf "child-int\n" >> "$INTERRUPT_LOG"; exit 130' INT TERM

while true; do
    sleep 0.2
done
EOF
    chmod +x "$TEMP_DIR/fake-interruptible"

    PATH="$TEMP_DIR:$PATH" \
    INTERRUPT_LOG="$INTERRUPT_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_PANEL_SHOW_MENU=0

        wprint3d_restore_terminal() {
            printf "restored\n" >> "$INTERRUPT_LOG"
        }

        fake-interruptible &
        runner_pid=$!
        WPRINT3D_ACTIVE_CHILD_PID="$runner_pid"
        sleep 0.2

        wprint3d_interrupt_session INT || true

        wait "$runner_pid"
        runner_status=$?

        printf "runner_status=%s\n" "$runner_status"
        cat "$INTERRUPT_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$interrupt_output" "runner_status=130"
assert_contains "$interrupt_output" "restored"
assert_contains "$interrupt_output" "child-int"

sequence_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    touch "$TEMP_DIR/docker-compose-development.yml"

    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        HOST_CONTAINER_RUNTIME=podman
        HOST_COMPOSE_COMMAND=podman-compose

        run_host_compose() {
            printf "compose %s\n" "$*" >> "$COMMAND_LOG"
        }

        wprint3d_log_progress() {
            printf "progress %s|%s|%s\n" "$1" "$2" "$3" >> "$COMMAND_LOG"
        }

        wprint3d_wait_for_service_ready() {
            printf "wait %s\n" "$1" >> "$COMMAND_LOG"
            return 0
        }

        wprint3d_start_services_with_progress dev docker-compose-development.yml

        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps redis"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps mongo"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps backend"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps web"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps proxy"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps mapper"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps yv-streamer-software"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps streamer"
assert_contains "$sequence_output" "compose -f docker-compose-development.yml up -d --no-deps memcached"
assert_contains "$sequence_output" "progress redis|starting|Starting Redis..."
assert_contains "$sequence_output" "progress redis|ready|Redis is up!"
assert_contains "$sequence_output" "wait memcached"

interactive_sequence_capture_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    touch "$TEMP_DIR/docker-compose-development.yml"

    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        HOST_CONTAINER_RUNTIME=podman
        HOST_COMPOSE_COMMAND=podman-compose
        WPRINT3D_PANEL_ACTIVE=1

        run_host_compose() {
            printf "compose %s\n" "$*" >> "$COMMAND_LOG"
        }

        wprint3d_run_captured_command() {
            printf "captured %s\n" "$*" >> "$COMMAND_LOG"
            "$@"
        }

        wprint3d_log_progress() {
            :
        }

        wprint3d_append_event_log() {
            :
        }

        wprint3d_wait_for_service_ready() {
            return 0
        }

        wprint3d_start_services_with_progress dev docker-compose-development.yml

        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$interactive_sequence_capture_output" "captured run_host_compose -f docker-compose-development.yml up -d --no-deps redis"
assert_contains "$interactive_sequence_capture_output" "captured run_host_compose -f docker-compose-development.yml up -d --no-deps mapper"

mapper_readiness_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"

        wprint3d_container_process_matches() {
            local container_id="$1"
            shift
            printf "patterns=%s\n" "$*"

            for pattern in "$@"; do
                if [[ "$pattern" == "udevadm" ]]; then
                    return 0
                fi
            done

            return 1
        }

        if wprint3d_service_is_ready mapper mapper-cid; then
            printf "ready=yes\n"
        else
            printf "ready=no\n"
        fi
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$mapper_readiness_output" "patterns=udevadm"
assert_contains "$mapper_readiness_output" "ready=yes"

menu_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"
    CHOICE_STATE="$TEMP_DIR/choice-state"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    COMMAND_LOG="$COMMAND_LOG" \
    CHOICE_STATE="$CHOICE_STATE" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"
        touch "$COMMAND_LOG"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_CURRENT_COMPOSE_FILE=docker-compose-development.yml
        WPRINT3D_FINAL_MESSAGE=""

        wprint3d_render_control_panel() {
            :
        }

        wprint3d_read_menu_choice() {
            if [[ ! -f "$CHOICE_STATE" ]]; then
                touch "$CHOICE_STATE"
                printf "choice-empty\n" >> "$COMMAND_LOG"
                printf "\n"
                return 0
            fi

            printf "choice-b\n" >> "$COMMAND_LOG"
            printf "B\n"
        }

        wprint3d_run_menu

        printf "final=%s\n" "$WPRINT3D_FINAL_MESSAGE"
        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$menu_output" "choice-empty"
assert_contains "$menu_output" "choice-b"
assert_contains "$menu_output" "final=Interactive mode closed. Services will keep running in the background at https://localhost"

logs_menu_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"
    CHOICE_STATE="$TEMP_DIR/choice-state"
    LOGS_STATE="$TEMP_DIR/logs-state"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    COMMAND_LOG="$COMMAND_LOG" \
    CHOICE_STATE="$CHOICE_STATE" \
    LOGS_STATE="$LOGS_STATE" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"
        touch "$COMMAND_LOG"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_CURRENT_COMPOSE_FILE=docker-compose-development.yml
        WPRINT3D_FINAL_MESSAGE=""

        wprint3d_render_control_panel() {
            printf "render\n" >> "$COMMAND_LOG"
        }

        wprint3d_restore_terminal() {
            printf "restore\n" >> "$COMMAND_LOG"
        }

        wprint3d_prepare_terminal() {
            printf "prepare\n" >> "$COMMAND_LOG"
        }

        wprint3d_read_menu_choice() {
            if [[ ! -f "$CHOICE_STATE" ]]; then
                touch "$CHOICE_STATE"
                printf "L\n"
                return 0
            fi

            printf "B\n"
        }

        run_host_compose() {
            printf "compose %s\n" "$*" >> "$COMMAND_LOG"

            if [[ "$1" == "-f" ]] && [[ "$3" == "logs" ]]; then
                if [[ ! -f "$LOGS_STATE" ]]; then
                    touch "$LOGS_STATE"
                    return 0
                fi

                return 130
            fi

            return 0
        }

        wprint3d_run_menu

        printf "final=%s\n" "$WPRINT3D_FINAL_MESSAGE"
        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$logs_menu_output" "compose -f docker-compose-development.yml logs -f --tail 80"
logs_compose_count="$(printf '%s\n' "$logs_menu_output" | grep -c '^compose -f docker-compose-development.yml logs -f --tail 80$')"
if [[ "$logs_compose_count" -lt 2 ]]; then
    echo "Expected logs command to be retried before returning to the menu." >&2
    echo "$logs_menu_output" >&2
    exit 1
fi
assert_contains "$logs_menu_output" "restore"
assert_contains "$logs_menu_output" "prepare"
assert_contains "$logs_menu_output" "final=Interactive mode closed. Services will keep running in the background at https://localhost"

shutdown_menu_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"
        touch "$COMMAND_LOG"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_CURRENT_COMPOSE_FILE=docker-compose-development.yml
        WPRINT3D_FINAL_MESSAGE=""

        wprint3d_render_control_panel() {
            :
        }

        wprint3d_read_menu_choice() {
            printf "S\n"
        }

        wprint3d_read_confirmation() {
            printf "y\n"
        }

        run_host_compose() {
            printf "compose %s\n" "$*" >> "$COMMAND_LOG"
            return 0
        }

        wprint3d_run_menu

        printf "final=%s\n" "$WPRINT3D_FINAL_MESSAGE"
        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$shutdown_menu_output" "compose -f docker-compose-development.yml down --timeout 5"
assert_contains "$shutdown_menu_output" "final=All services have been stopped. Goodbye."

echo "run.sh interactive startup sequence checks passed"
