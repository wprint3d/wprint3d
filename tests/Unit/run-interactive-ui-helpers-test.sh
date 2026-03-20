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

assert_at_least() {
    local actual="$1"
    local minimum="$2"

    if [[ "$actual" -lt "$minimum" ]]; then
        echo "Expected at least: $minimum" >&2
        echo "Actual:            $actual" >&2

        exit 1
    fi
}

assert_at_most() {
    local actual="$1"
    local maximum="$2"

    if [[ "$actual" -gt "$maximum" ]]; then
        echo "Expected at most: $maximum" >&2
        echo "Actual:           $actual" >&2

        exit 1
    fi
}

assert_matches() {
    local haystack="$1"
    local pattern="$2"

    if ! printf '%s\n' "$haystack" | grep -E "$pattern" > /dev/null; then
        echo "Expected output to match: $pattern" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

helper_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    bash -c '
        source "$1"

        printf "version=%s\n" "$(wprint3d_get_project_version)"
        printf "interactive=%s\n" "$(wprint3d_should_use_interactive_ui && echo yes || echo no)"
        printf "dev_url=%s\n" "$(wprint3d_web_interface_url dev)"
        printf "prod_url=%s\n" "$(wprint3d_web_interface_url production)"
        printf "dev_services=%s\n" "$(wprint3d_service_sequence dev | paste -sd "," -)"
        printf "prod_services=%s\n" "$(wprint3d_service_sequence production | paste -sd "," -)"
        printf "menu=%s\n" "$(wprint3d_menu_summary)"
        printf "spinner0=%s\n" "$(wprint3d_startup_spinner_frame 0)"
        printf "spinner1=%s\n" "$(wprint3d_startup_spinner_frame 1)"
        printf "phrase0=%s\n" "$(wprint3d_startup_phrase 0)"
        printf "phrase15=%s\n" "$(wprint3d_startup_phrase 15)"
        printf "phrase45=%s\n" "$(wprint3d_startup_phrase 45)"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$helper_output" "version=abc123"
assert_contains "$helper_output" "interactive=yes"
assert_contains "$helper_output" "dev_url=https://localhost"
assert_contains "$helper_output" "prod_url=https://localhost"
assert_contains "$helper_output" "dev_services=redis,mongo,backend,web,proxy,mapper,yv-streamer-software,streamer,memcached"
assert_contains "$helper_output" "prod_services=redis,mongo,backend,web,proxy,mapper,yv-streamer-software,streamer,memcached"
assert_contains "$helper_output" "View Logs"
assert_contains "$helper_output" "Restart Services"
assert_contains "$helper_output" "Access Web Interface"
assert_contains "$helper_output" "Send Feedback"
assert_contains "$helper_output" "Background Mode"
assert_contains "$helper_output" "Shutdown"
assert_contains "$helper_output" "spinner0=⠋"
assert_contains "$helper_output" "spinner1=⠙"
assert_contains "$helper_output" "phrase0=whispering to containers"
assert_contains "$helper_output" "phrase15=aligning tiny gears"
assert_contains "$helper_output" "phrase45=preparing the w's in print3d"

render_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    WPRINT3D_DISABLE_SCREEN_CLEAR=1 \
    COLUMNS=120 \
    LINES=80 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        wprint3d_init_ui
        wprint3d_register_startup_progress

        for i in $(seq 1 30); do
            wprint3d_append_event_log "event-${i}"
        done

        wprint3d_render_control_panel
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$render_output" " __        ______       _       _     _____ ____  "
assert_contains "$render_output" " \\ \\      / /  _ \\ _ __(_)_ __ | |_  |___ /|  _ \\ "
assert_contains "$render_output" "  \\ \\ /\\ / /| |_) | '__| | '_ \\| __|   |_ \\| | | |"
assert_contains "$render_output" "   \\ V  V / |  __/| |  | | | | | |_   ___) | |_| |"
assert_contains "$render_output" "    \\_/\\_/  |_|   |_|  |_|_| |_|\\__| |____/|____/ "
assert_matches "$render_output" 'Starting\.\.\..*⠋ whispering to containers'
assert_not_contains "$render_output" "|| ⠋"

event_line_count="$(printf '%s\n' "$render_output" | grep -c 'event-')"
assert_at_least "$event_line_count" "25"

compact_render_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    WPRINT3D_DISABLE_SCREEN_CLEAR=1 \
    COLUMNS=80 \
    LINES=24 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        wprint3d_init_ui
        wprint3d_register_startup_progress

        for i in $(seq 1 10); do
            wprint3d_append_event_log "event-${i}"
        done

        wprint3d_render_control_panel
    ' _ "$TEMP_DIR/run.sh"
)"

compact_line_count="$(printf '%s\n' "$compact_render_output" | wc -l | tr -d ' ')"
compact_max_width="$(
    while IFS= read -r line; do
        printf '%s\n' "${#line}"
    done < <(printf '%s\n' "$compact_render_output" | sed -E $'s/\x1B\\[[0-9;]*[[:alpha:]]//g') | sort -nr | head -n 1
)"
assert_at_most "$compact_line_count" "23"
assert_at_most "$compact_max_width" "79"
assert_contains "$compact_render_output" "more startup steps"

status_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    WPRINT3D_DISABLE_SCREEN_CLEAR=1 \
    COLUMNS=80 \
    LINES=35 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        wprint3d_init_ui
        wprint3d_register_startup_progress
        wprint3d_log_progress runtime ready "Using podman with podman-compose."
        wprint3d_log_progress images failed "Failed to pull container images."
        wprint3d_render_control_panel
    ' _ "$TEMP_DIR/run.sh"
)"

assert_matches "$status_output" '^\| ✔ Runtime: Using podman with podman-compose\.[[:space:]]+\|$'
assert_matches "$status_output" '^\| ✖ Images: Failed to pull container images\.[[:space:]]+\|$'

color_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_COLOR=1 \
    WPRINT3D_DISABLE_SCREEN_CLEAR=1 \
    COLUMNS=100 \
    LINES=35 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        wprint3d_init_ui
        wprint3d_register_startup_progress
        wprint3d_log_progress runtime ready "Using podman with podman-compose."
        wprint3d_render_control_panel
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$color_output" $'\033[1;36m'
assert_contains "$color_output" $'\033[1;32m'

redraw_output="$(
    TEMP_DIR="$(mktemp -d)"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    COLUMNS=100 \
    LINES=35 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        wprint3d_init_ui
        wprint3d_register_startup_progress
        wprint3d_render_control_panel
        wprint3d_append_event_log "next-event"
        wprint3d_render_control_panel
    ' _ "$TEMP_DIR/run.sh"
)"

clear_count="$(printf '%s' "$redraw_output" | grep -o $'\033\\[H\033\\[2J' | wc -l | tr -d ' ')"
assert_at_most "$clear_count" "1"

idle_footer_only_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/render.log"

    mkdir -p "$TEMP_DIR/internal"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    printf 'abc123\n' > "$TEMP_DIR/internal/app_ver"

    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    WPRINT3D_FORCE_INTERACTIVE=1 \
    bash -c '
        source "$1"

        WPRINT3D_CURRENT_ENV=dev
        WPRINT3D_PANEL_ACTIVE=1
        WPRINT3D_PANEL_SHOW_MENU=0
        WPRINT3D_TERM_WIDTH=100
        WPRINT3D_TERM_HEIGHT=35
        WPRINT3D_EVENT_LOG=("steady-line")

        wprint3d_refresh_ui_dimensions() {
            :
        }

        wprint3d_clear_screen() {
            :
        }

        wprint3d_output_panel_lines() {
            printf "full\n" >> "$COMMAND_LOG"
            WPRINT3D_PANEL_HAS_DRAWN=1
        }

        wprint3d_output_panel_footer_row() {
            printf "footer:%s\n" "$1" >> "$COMMAND_LOG"
        }

        wprint3d_render_control_panel
        wprint3d_render_control_panel

        cat "$COMMAND_LOG"
    ' _ "$TEMP_DIR/run.sh"
)"

assert_contains "$idle_footer_only_output" "full"
assert_contains "$idle_footer_only_output" "footer:"

echo "run.sh interactive UI helper checks passed"
