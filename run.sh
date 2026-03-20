#!/bin/bash

SCRIPT_SOURCE="${BASH_SOURCE[0]:-$0}"
SCRIPT_PATH="$(cd -- "$(dirname "$SCRIPT_SOURCE")" >/dev/null 2>&1 && pwd -P)"

cd "$SCRIPT_PATH"

if [[ -f "${SCRIPT_PATH}/internal/container-runtime.sh" ]]; then
    source "${SCRIPT_PATH}/internal/container-runtime.sh"
elif [[ "${WPRINT3D_RUN_SH_SOURCE_ONLY:-0}" != '1' ]]; then
    echo 'Missing internal/container-runtime.sh. Restore the repository files and try again.' >&2
    exit 1
fi

declare -ga WPRINT3D_PROGRESS_ORDER=()
declare -ga WPRINT3D_EVENT_LOG=()
declare -gA WPRINT3D_PROGRESS_LABELS=()
declare -gA WPRINT3D_PROGRESS_STATES=()
declare -gA WPRINT3D_PROGRESS_MESSAGES=()
declare -gA WPRINT3D_PROGRESS_DETAILS=()

WPRINT3D_PANEL_ACTIVE=0
WPRINT3D_PANEL_SHOW_MENU=0
WPRINT3D_UI_INITIALIZED=0
WPRINT3D_ALT_SCREEN_ACTIVE=0
WPRINT3D_PANEL_HAS_DRAWN=0
WPRINT3D_SPINNER_TICK=0
WPRINT3D_LAST_PANEL_STATIC_SIGNATURE=''
WPRINT3D_LAST_PANEL_FOOTER_ROW=0
WPRINT3D_ACTIVE_CHILD_PID=''
WPRINT3D_INTERRUPT_REQUESTED=0
WPRINT3D_CURRENT_ENV='production'
WPRINT3D_CURRENT_COMPOSE_FILE=''
WPRINT3D_FINAL_MESSAGE=''
WPRINT3D_TERM_WIDTH=100
WPRINT3D_TERM_HEIGHT=40

normalize_compose_logging_driver() {
    local compose_file="${1:-docker-compose.yml}"

    if [[ ! -f "$compose_file" ]]; then
        return 0
    fi

    sed -i 's/driver: local/driver: ${CONTAINER_LOG_DRIVER:-local}/g' "$compose_file"
}

offer_frontend_node_modules_reownership() {
    local node_modules_path='frontend/node_modules'
    local reown_choice="${WPRINT3D_REOWN_FRONTEND_NODE_MODULES:-ask}"

    if ! frontend_node_modules_needs_permission_repair "$node_modules_path"; then
        return 0
    fi

    echo 'Detected frontend/node_modules files that are not owned by the current user.'
    echo 'This commonly happens after switching from Docker to Podman.'

    case "$reown_choice" in
        1|true|yes)
            repair_frontend_node_modules_permissions "$node_modules_path" || return 1

            return 0
            ;;
        0|false|no)
            echo 'Skipping frontend/node_modules ownership repair.'

            return 0
            ;;
    esac

    if [[ -t 0 ]]; then
        read -r -p "Re-own frontend/node_modules to $(id -un):$(id -gn) before continuing? [y/N] " reown_choice

        case "$reown_choice" in
            y|Y|yes|YES)
                repair_frontend_node_modules_permissions "$node_modules_path" || return 1
                ;;
            *)
                echo 'Skipping frontend/node_modules ownership repair.'
                ;;
        esac

        return 0
    fi

    echo 'Non-interactive session detected. Re-run with WPRINT3D_REOWN_FRONTEND_NODE_MODULES=1 to repair ownership automatically.' >&2
}

ensure_podman_development_ports_supported() {
    local rootless='false'
    local unprivileged_port_start='1024'

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 0
    fi

    if [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        return 0
    fi

    if run_host_container_cli info --format '{{.Host.Security.Rootless}}' 2> /dev/null | grep -qx 'true'; then
        rootless='true'
    fi

    if [[ "$rootless" != 'true' ]]; then
        return 0
    fi

    if [[ -r /proc/sys/net/ipv4/ip_unprivileged_port_start ]]; then
        unprivileged_port_start="$(cat /proc/sys/net/ipv4/ip_unprivileged_port_start)"
    fi

    if [[ "$unprivileged_port_start" =~ ^[0-9]+$ ]] && [[ "$unprivileged_port_start" -gt 80 ]]; then
        echo 'Rootless Podman cannot bind the development stack to ports 80/443 on this host.' >&2
        echo "The current net.ipv4.ip_unprivileged_port_start value is ${unprivileged_port_start}." >&2
        echo 'Use rootful Podman, lower net.ipv4.ip_unprivileged_port_start to 80 or less, or restore high host ports for the proxy service.' >&2

        return 1
    fi
}

prepare_startup_elevation() {
    local needs_generic_elevation='false'
    local needs_podman_probe='false'

    if podman_rootful_enabled; then
        if command -v podman > /dev/null 2>&1; then
            needs_podman_probe='true'
        else
            needs_generic_elevation='true'
        fi
    fi

    if [[ -r /proc/sys/fs/inotify/max_user_watches ]] && [[ $(cat /proc/sys/fs/inotify/max_user_watches) -lt 65536 ]]; then
        needs_generic_elevation='true'
    fi

    if [[ "$needs_generic_elevation" == 'true' ]]; then
        prime_elevated_access

        return $?
    fi

    if [[ "$needs_podman_probe" == 'true' ]]; then
        prime_elevated_access podman info --format '{{.Host.Security.Rootless}}'

        return $?
    fi

    return 0
}

wprint3d_bool_is_true() {
    case "${1:-}" in
        1|true|TRUE|yes|YES|on|ON)
            return 0
            ;;
    esac

    return 1
}

wprint3d_should_use_interactive_ui() {
    if wprint3d_bool_is_true "${WPRINT3D_FORCE_INTERACTIVE:-0}"; then
        return 0
    fi

    if wprint3d_bool_is_true "${WPRINT3D_DISABLE_INTERACTIVE_UI:-0}"; then
        return 1
    fi

    [[ -t 0 && -t 1 ]]
}

wprint3d_init_ui() {
    local color_enabled=0

    if [[ "$WPRINT3D_UI_INITIALIZED" -eq 1 ]]; then
        return 0
    fi

    if wprint3d_bool_is_true "${WPRINT3D_FORCE_COLOR:-0}"; then
        color_enabled=1
    elif [[ -t 1 ]] && [[ ! "${TERM:-}" =~ ^(dumb|unknown)$ ]] && [[ -z "${NO_COLOR:-}" ]]; then
        color_enabled=1
    fi

    if [[ "$color_enabled" -eq 1 ]]; then
        WPRINT3D_COLOR_RESET=$'\033[0m'
        WPRINT3D_COLOR_TITLE=$'\033[1;36m'
        WPRINT3D_COLOR_ACCENT=$'\033[1;96m'
        WPRINT3D_COLOR_MUTED=$'\033[2;37m'
        WPRINT3D_COLOR_OK=$'\033[1;32m'
        WPRINT3D_COLOR_WARN=$'\033[1;33m'
        WPRINT3D_COLOR_ERROR=$'\033[1;31m'
        WPRINT3D_COLOR_INFO=$'\033[1;34m'
    else
        WPRINT3D_COLOR_RESET=''
        WPRINT3D_COLOR_TITLE=''
        WPRINT3D_COLOR_ACCENT=''
        WPRINT3D_COLOR_MUTED=''
        WPRINT3D_COLOR_OK=''
        WPRINT3D_COLOR_WARN=''
        WPRINT3D_COLOR_ERROR=''
        WPRINT3D_COLOR_INFO=''
    fi

    if [[ "${LC_ALL:-${LANG:-}}" == *'UTF-8'* ]] || [[ "${LC_CTYPE:-}" == *'UTF-8'* ]]; then
        WPRINT3D_SYMBOL_PENDING='...'
        WPRINT3D_SYMBOL_RUNNING='›'
        WPRINT3D_SYMBOL_READY='✔'
        WPRINT3D_SYMBOL_FAILED='✖'
        WPRINT3D_SYMBOL_MENU='›'
    else
        WPRINT3D_SYMBOL_PENDING='...'
        WPRINT3D_SYMBOL_RUNNING='>'
        WPRINT3D_SYMBOL_READY='[OK]'
        WPRINT3D_SYMBOL_FAILED='[X]'
        WPRINT3D_SYMBOL_MENU='>'
    fi

    wprint3d_refresh_ui_dimensions

    WPRINT3D_UI_INITIALIZED=1
}

wprint3d_refresh_ui_dimensions() {
    local tty_size=''
    local detected_height=''
    local detected_width=''
    local panel_height=0
    local panel_width=0

    if [[ -t 0 ]] || [[ -t 1 ]]; then
        tty_size="$(stty size 2>/dev/null || true)"
    fi

    if [[ "$tty_size" =~ ^([0-9]+)[[:space:]]+([0-9]+)$ ]]; then
        detected_height="${BASH_REMATCH[1]}"
        detected_width="${BASH_REMATCH[2]}"
    fi

    if [[ -z "$detected_width" ]] && [[ -n "${COLUMNS:-}" ]] && [[ "${COLUMNS}" =~ ^[0-9]+$ ]]; then
        detected_width="$COLUMNS"
    fi

    if [[ -z "$detected_height" ]] && [[ -n "${LINES:-}" ]] && [[ "${LINES}" =~ ^[0-9]+$ ]]; then
        detected_height="$LINES"
    fi

    if [[ -z "$detected_width" ]] && command -v tput > /dev/null 2>&1; then
        detected_width="$(tput cols 2>/dev/null || true)"
    fi

    if [[ -z "$detected_height" ]] && command -v tput > /dev/null 2>&1; then
        detected_height="$(tput lines 2>/dev/null || true)"
    fi

    if [[ ! "$detected_width" =~ ^[0-9]+$ ]] || [[ "$detected_width" -lt 1 ]]; then
        detected_width=100
    fi

    if [[ ! "$detected_height" =~ ^[0-9]+$ ]] || [[ "$detected_height" -lt 1 ]]; then
        detected_height=40
    fi

    if [[ "$detected_width" -gt 120 ]]; then
        detected_width=120
    fi

    panel_width=$((detected_width - 2))
    panel_height=$((detected_height - 1))

    if [[ "$panel_width" -lt 60 ]]; then
        panel_width=60
    fi

    if [[ "$panel_height" -lt 20 ]]; then
        panel_height=20
    fi

    WPRINT3D_TERM_WIDTH="$panel_width"
    WPRINT3D_TERM_HEIGHT="$panel_height"
}

wprint3d_clear_screen() {
    if [[ "$WPRINT3D_PANEL_ACTIVE" -ne 1 ]] || wprint3d_bool_is_true "${WPRINT3D_DISABLE_SCREEN_CLEAR:-0}"; then
        return 0
    fi

    printf '\033[H\033[2J'
}

wprint3d_restore_terminal() {
    if [[ -t 1 ]]; then
        if [[ "$WPRINT3D_ALT_SCREEN_ACTIVE" -eq 1 ]]; then
            printf '\033[?1049l'
            WPRINT3D_ALT_SCREEN_ACTIVE=0
        fi

        printf '\033[?25h'
    fi

    WPRINT3D_PANEL_HAS_DRAWN=0
    WPRINT3D_LAST_PANEL_STATIC_SIGNATURE=''
    WPRINT3D_LAST_PANEL_FOOTER_ROW=0
}

wprint3d_child_pids() {
    local parent_pid="$1"

    if [[ ! "$parent_pid" =~ ^[0-9]+$ ]] || [[ "$parent_pid" -le 0 ]]; then
        return 0
    fi

    if command -v pgrep > /dev/null 2>&1; then
        pgrep -P "$parent_pid" 2> /dev/null || true
        return 0
    fi

    ps -o pid= --ppid "$parent_pid" 2> /dev/null | awk '{print $1}'
}

wprint3d_signal_process_tree() {
    local pid="$1"
    local signal="${2:-TERM}"
    local child_pid=''

    if [[ ! "$pid" =~ ^[0-9]+$ ]] || [[ "$pid" -le 0 ]]; then
        return 0
    fi

    while IFS= read -r child_pid; do
        [[ -n "$child_pid" ]] || continue
        wprint3d_signal_process_tree "$child_pid" "$signal"
    done < <(wprint3d_child_pids "$pid")

    kill -s "$signal" "$pid" 2> /dev/null || true
}

wprint3d_stop_active_child() {
    local signal="${1:-INT}"
    local pid="${WPRINT3D_ACTIVE_CHILD_PID:-}"
    local attempt=0

    if [[ ! "$pid" =~ ^[0-9]+$ ]] || [[ "$pid" -le 0 ]]; then
        return 0
    fi

    wprint3d_signal_process_tree "$pid" "$signal"

    for attempt in $(seq 1 10); do
        if ! kill -0 "$pid" 2> /dev/null; then
            WPRINT3D_ACTIVE_CHILD_PID=''
            return 0
        fi

        sleep 0.05
    done

    wprint3d_signal_process_tree "$pid" TERM

    for attempt in $(seq 1 10); do
        if ! kill -0 "$pid" 2> /dev/null; then
            WPRINT3D_ACTIVE_CHILD_PID=''
            return 0
        fi

        sleep 0.05
    done

    wprint3d_signal_process_tree "$pid" KILL
    WPRINT3D_ACTIVE_CHILD_PID=''
}

wprint3d_interrupt_session() {
    local signal="${1:-INT}"

    if [[ "$WPRINT3D_INTERRUPT_REQUESTED" -eq 1 ]]; then
        return 130
    fi

    WPRINT3D_INTERRUPT_REQUESTED=1
    wprint3d_stop_active_child "$signal"
    wprint3d_restore_terminal

    return 130
}

wprint3d_install_panel_traps() {
    trap 'wprint3d_restore_terminal' EXIT
    trap 'wprint3d_interrupt_session INT; exit 130' INT
    trap 'wprint3d_interrupt_session TERM; exit 143' TERM
}

wprint3d_prepare_terminal() {
    if [[ -t 1 ]]; then
        if [[ "$WPRINT3D_ALT_SCREEN_ACTIVE" -eq 0 ]]; then
            printf '\033[?1049h'
            WPRINT3D_ALT_SCREEN_ACTIVE=1
        fi

        printf '\033[?25l'
    fi
}

wprint3d_feedback_url() {
    printf '%s\n' 'https://github.com/wprint3d/wprint3d/issues/new?template=Blank+issue'
}

wprint3d_docs_reference() {
    printf '%s\n' 'docs/index.md'
}

wprint3d_public_docs_url() {
    printf '%s\n' 'https://docs.wprint3d.com'
}

wprint3d_get_project_version() {
    if [[ -n "${WPRINT3D_PROJECT_VERSION_OVERRIDE:-}" ]]; then
        printf '%s\n' "$WPRINT3D_PROJECT_VERSION_OVERRIDE"

        return 0
    fi

    if [[ -s "${SCRIPT_PATH}/internal/app_ver" ]]; then
        sed -n '1p' "${SCRIPT_PATH}/internal/app_ver"

        return 0
    fi

    if [[ -f "${SCRIPT_PATH}/.git/HEAD" ]] && command -v git > /dev/null 2>&1; then
        git -C "${SCRIPT_PATH}" rev-parse --short HEAD 2> /dev/null && return 0
    fi

    printf '%s\n' 'development'
}

wprint3d_web_interface_url() {
    local env_name="${1:-production}"
    local https_port='443'
    local http_port='80'

    if [[ "$env_name" == 'production' ]]; then
        https_port="${EXTERNAL_WEB_HTTPS_PORT:-443}"
        http_port="${EXTERNAL_WEB_HTTP_PORT:-80}"
    fi

    if [[ "$https_port" =~ ^[0-9]+$ ]] && [[ "$https_port" -gt 0 ]]; then
        if [[ "$https_port" == '443' ]]; then
            printf '%s\n' 'https://localhost'
        else
            printf 'https://localhost:%s\n' "$https_port"
        fi

        return 0
    fi

    if [[ "$http_port" == '80' ]]; then
        printf '%s\n' 'http://localhost'
    else
        printf 'http://localhost:%s\n' "$http_port"
    fi
}

wprint3d_service_sequence() {
    local env_name="${1:-production}"

    case "$env_name" in
        dev|production)
            printf '%s\n' \
                redis \
                mongo \
                backend \
                web \
                proxy \
                mapper \
                yv-streamer-software \
                streamer \
                memcached
            ;;
    esac
}

wprint3d_service_label() {
    local service_name="$1"

    case "$service_name" in
        redis)
            printf '%s\n' 'Redis'
            ;;
        mongo)
            printf '%s\n' 'MongoDB'
            ;;
        backend)
            printf '%s\n' 'Backend'
            ;;
        web)
            if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
                printf '%s\n' 'Expo Web'
            else
                printf '%s\n' 'Web UI'
            fi
            ;;
        proxy)
            printf '%s\n' 'Proxy'
            ;;
        mapper)
            printf '%s\n' 'Device Mapper'
            ;;
        streamer)
            printf '%s\n' 'Streamer'
            ;;
        yv-streamer-software)
            printf '%s\n' 'YV Streamer'
            ;;
        memcached)
            printf '%s\n' 'Memcached'
            ;;
        *)
            printf '%s\n' "$service_name"
            ;;
    esac
}

wprint3d_menu_summary() {
    printf '%s\n' 'L View Logs | R Restart Services | W Access Web Interface | F Send Feedback | B Background Mode | S Shutdown'
}

wprint3d_startup_spinner_frame() {
    local tick="${1:-0}"
    local frames=('⠋' '⠙' '⠚' '⠞' '⠖' '⠦' '⠴' '⠲' '⠳' '⠓')
    local index=0

    if [[ ! "$tick" =~ ^[0-9]+$ ]]; then
        tick=0
    fi

    index=$((tick % ${#frames[@]}))
    printf '%s\n' "${frames[$index]}"
}

wprint3d_startup_phrase() {
    local tick="${1:-0}"
    local phrases=(
        'whispering to containers'
        'untangling usb noodles'
        'warming the nozzles'
        'convincing podman gently'
        'bribing the build goblins'
        'aligning tiny gears'
        'counting plastic sheep'
        'teaching redis patience'
        'asking mongo politely'
        'stretching proxy cables'
        'polishing the dashboard'
        'making pixels behave'
        'coaxing the stepper motors into formation'
        'waiting for Marlin to finish its monologue'
        'sweet-talking Klipper into sync'
        "preparing the w's in print3d"
        'dusting off vintage PHP spells'
        'listening for serial port gossip'
    )
    local phrase_tick=0
    local index=0

    if [[ ! "$tick" =~ ^[0-9]+$ ]]; then
        tick=0
    fi

    phrase_tick=$((tick / 15))
    index=$(( (phrase_tick * 5) % ${#phrases[@]} ))
    printf '%s\n' "${phrases[$index]}"
}

wprint3d_register_progress_item() {
    local key="$1"
    local label="$2"

    if [[ -z "${WPRINT3D_PROGRESS_LABELS[$key]+x}" ]]; then
        WPRINT3D_PROGRESS_ORDER+=("$key")
    fi

    WPRINT3D_PROGRESS_LABELS["$key"]="$label"
}

wprint3d_progress_icon() {
    local state="$1"

    case "$state" in
        ready)
            printf '%s\n' "$WPRINT3D_SYMBOL_READY"
            ;;
        failed)
            printf '%s\n' "$WPRINT3D_SYMBOL_FAILED"
            ;;
        starting)
            printf '%s\n' "$WPRINT3D_SYMBOL_RUNNING"
            ;;
        *)
            printf '%s\n' "$WPRINT3D_SYMBOL_PENDING"
            ;;
    esac
}

wprint3d_progress_color() {
    local state="$1"

    case "$state" in
        ready)
            printf '%s\n' "$WPRINT3D_COLOR_OK"
            ;;
        failed)
            printf '%s\n' "$WPRINT3D_COLOR_ERROR"
            ;;
        starting)
            printf '%s\n' "$WPRINT3D_COLOR_INFO"
            ;;
        *)
            printf '%s\n' "$WPRINT3D_COLOR_MUTED"
            ;;
    esac
}

wprint3d_truncate_plain() {
    local value="$1"
    local max_width="$2"

    if [[ "${#value}" -le "$max_width" ]]; then
        printf '%s\n' "$value"

        return 0
    fi

    if [[ "$max_width" -le 3 ]]; then
        printf '%.*s\n' "$max_width" "$value"

        return 0
    fi

    printf '%s...\n' "${value:0:$((max_width - 3))}"
}

wprint3d_truncate_visible() {
    local raw="$1"
    local max_width="$2"
    local plain=''
    local i=0
    local char=''
    local in_esc=0
    local esc_buf=''
    local result=''
    local visible=0
    local cut_at=0

    plain="$(wprint3d_strip_ansi "$raw")"

    if [[ "${#plain}" -le "$max_width" ]]; then
        printf '%s' "$raw"
        return 0
    fi

    if [[ "$max_width" -le 3 ]]; then
        printf '%.*s' "$max_width" "$plain"
        return 0
    fi

    cut_at=$((max_width - 3))

    while [[ $i -lt ${#raw} ]]; do
        char="${raw:$i:1}"
        if [[ "$in_esc" -eq 0 ]] && [[ "$char" == $'\e' ]]; then
            in_esc=1
            esc_buf="$char"
        elif [[ "$in_esc" -eq 1 ]]; then
            esc_buf+="$char"
            if [[ "$char" =~ [[:alpha:]] ]]; then
                in_esc=0
                result+="$esc_buf"
                esc_buf=''
            fi
        elif [[ "$visible" -lt "$cut_at" ]]; then
            result+="$char"
            visible=$((visible + 1))
        fi
        i=$((i + 1))
    done

    printf '%s...' "$result"
}

wprint3d_strip_ansi() {
    printf '%s' "$1" | sed -E $'s/\x1B\\[[0-9;]*[[:alpha:]]//g'
}

wprint3d_box_border_text() {
    local fill_char="${1:--}"
    local color="${2:-$WPRINT3D_COLOR_ACCENT}"
    local repeat_count="$((WPRINT3D_TERM_WIDTH - 2))"

    printf '%s+' "$color"
    printf '%*s' "$repeat_count" '' | tr ' ' "$fill_char"
    printf '+%s' "$WPRINT3D_COLOR_RESET"
}

wprint3d_print_box_border() {
    wprint3d_box_border_text "$1" "$2"
    printf '\n'
}

wprint3d_box_line_text() {
    local raw_text="$1"
    local max_width="$((WPRINT3D_TERM_WIDTH - 4))"
    local plain_text=''
    local display_text=''
    local visible_len=0
    local padding_width=0

    plain_text="$(wprint3d_strip_ansi "$raw_text")"

    if [[ "${#plain_text}" -gt "$max_width" ]]; then
        display_text="$(wprint3d_truncate_visible "$raw_text" "$max_width")"
        visible_len="$max_width"
    else
        display_text="$raw_text"
        visible_len="${#plain_text}"
    fi

    padding_width=$((max_width - visible_len))

    printf '| %s%*s |' "$display_text" "$padding_width" ''
}

wprint3d_box_split_line_text() {
    local left_raw="$1"
    local right_raw="$2"
    local separator="${3:-  }"
    local inner_width="$((WPRINT3D_TERM_WIDTH - 4))"
    local separator_width="${#separator}"
    local min_left_width=28
    local min_right_width=18
    local max_right_width=$((inner_width - separator_width - min_left_width))
    local right_width=0
    local left_width=0
    local left_plain=''
    local right_plain=''
    local left_display=''
    local right_display=''
    local left_visible_len=0
    local right_visible_len=0

    if [[ "$inner_width" -lt $((min_left_width + min_right_width + separator_width)) ]]; then
        wprint3d_box_line_text "${left_raw}${separator}${right_raw}"
        return 0
    fi

    left_plain="$(wprint3d_strip_ansi "$left_raw")"
    right_plain="$(wprint3d_strip_ansi "$right_raw")"
    right_width="${#right_plain}"

    if [[ "$right_width" -lt "$min_right_width" ]]; then
        right_width="$min_right_width"
    elif [[ "$right_width" -gt "$max_right_width" ]]; then
        right_width="$max_right_width"
    fi

    left_width=$((inner_width - separator_width - right_width))

    if [[ "$left_width" -lt "$min_left_width" ]]; then
        left_width="$min_left_width"
        right_width=$((inner_width - separator_width - left_width))
    fi

    if [[ "${#left_plain}" -gt "$left_width" ]]; then
        left_display="$(wprint3d_truncate_visible "$left_raw" "$left_width")"
        left_visible_len="$left_width"
    else
        left_display="$left_raw"
        left_visible_len="${#left_plain}"
    fi

    if [[ "${#right_plain}" -gt "$right_width" ]]; then
        right_display="$(wprint3d_truncate_visible "$right_raw" "$right_width")"
        right_visible_len="$right_width"
    else
        right_display="$right_raw"
        right_visible_len="${#right_plain}"
    fi

    printf '| %s%*s%s%s%*s |' \
        "$left_display" "$((left_width - left_visible_len))" '' \
        "$separator" \
        "$right_display" "$((right_width - right_visible_len))" ''
}

wprint3d_print_box_line() {
    wprint3d_box_line_text "$1"
    printf '\n'
}

wprint3d_output_panel_footer_row() {
    local row="$1"
    local line="$2"

    if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]] && [[ -t 1 ]] && ! wprint3d_bool_is_true "${WPRINT3D_DISABLE_SCREEN_CLEAR:-0}"; then
        printf '\033[%d;1H%s\033[K' "$row" "$line"
        printf '\033[%d;1H' "$((row + 1))"
    else
        printf '%s\n' "$line"
    fi

    WPRINT3D_PANEL_HAS_DRAWN=1
}

wprint3d_output_panel_lines() {
    local -n lines_ref="$1"
    local row=1
    local current_line_count="${#lines_ref[@]}"

    if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]] && [[ -t 1 ]] && ! wprint3d_bool_is_true "${WPRINT3D_DISABLE_SCREEN_CLEAR:-0}"; then
        printf '\033[H'

        for ((row = 1; row <= current_line_count; row++)); do
            printf '\033[%d;1H%s\033[K' "$row" "${lines_ref[$((row - 1))]}"
        done

        while [[ "$row" -le "$WPRINT3D_TERM_HEIGHT" ]]; do
            printf '\033[%d;1H\033[2K' "$row"
            row=$((row + 1))
        done

        printf '\033[%d;1H' "$((current_line_count + 1))"
        WPRINT3D_PANEL_HAS_DRAWN=1

        return 0
    fi

    printf '%s\n' "${lines_ref[@]}"
    WPRINT3D_PANEL_HAS_DRAWN=1
}

wprint3d_append_event_log() {
    local message="$1"

    WPRINT3D_EVENT_LOG+=("$message")

    if [[ "${#WPRINT3D_EVENT_LOG[@]}" -gt 250 ]]; then
        WPRINT3D_EVENT_LOG=("${WPRINT3D_EVENT_LOG[@]: -250}")
    fi
}

wprint3d_render_control_panel() {
    local version access_url docs_ref public_docs
    local banner_lines=(
        ' __        ______       _       _     _____ ____  '
        ' \ \      / /  _ \ _ __(_)_ __ | |_  |___ /|  _ \ '
        '  \ \ /\ / /| |_) | '\''__| | '\''_ \| __|   |_ \| | | |'
        '   \ V  V / |  __/| |  | | | | | |_   ___) | |_| |'
        '    \_/\_/  |_|   |_|  |_|_| |_|\__| |____/|____/ '
    )
    local banner_line
    local key state message detail icon color label
    local fixed_lines=19
    local content_capacity=0
    local min_log_lines=1
    local max_progress_lines=0
    local hidden_progress_count=0
    local head_count=0
    local tail_count=0
    local total_progress_lines=0
    local log_capacity=0
    local log_start_index=0
    local log_index=0
    local displayed_count=0
    local missing_count=0
    local progress_index=0
    local startup_spinner=''
    local startup_phrase=''
    local startup_tick=0
    local startup_footer_row=0
    local panel_static_signature=''
    local signature_index=0
    local line=''
    local -a panel_lines=()
    local -a progress_lines=()
    local -a visible_progress_lines=()

    wprint3d_refresh_ui_dimensions

    if [[ "$WPRINT3D_PANEL_HAS_DRAWN" -ne 1 ]]; then
        wprint3d_clear_screen
    fi

    version="$(wprint3d_get_project_version)"
    access_url="$(wprint3d_web_interface_url "$WPRINT3D_CURRENT_ENV")"
    docs_ref="$(wprint3d_docs_reference)"
    public_docs="$(wprint3d_public_docs_url)"

    panel_lines+=("$(wprint3d_box_border_text '=' "$WPRINT3D_COLOR_TITLE")")
    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_TITLE}Control panel${WPRINT3D_COLOR_RESET}")")
    panel_lines+=("$(wprint3d_box_border_text '-' "$WPRINT3D_COLOR_ACCENT")")

    for banner_line in "${banner_lines[@]}"; do
        panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_ACCENT}${banner_line}${WPRINT3D_COLOR_RESET}")")
    done
    panel_lines+=("$(wprint3d_box_line_text '')")

    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_TITLE}WPrint 3D${WPRINT3D_COLOR_RESET}    ${WPRINT3D_COLOR_MUTED}Version:${WPRINT3D_COLOR_RESET} ${version}    ${WPRINT3D_COLOR_MUTED}Mode:${WPRINT3D_COLOR_RESET} ${WPRINT3D_CURRENT_ENV}")")
    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_MUTED}Open the web interface at${WPRINT3D_COLOR_RESET} ${WPRINT3D_COLOR_INFO}${access_url}${WPRINT3D_COLOR_RESET}")")
    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_MUTED}Documentation:${WPRINT3D_COLOR_RESET} ${docs_ref}    ${WPRINT3D_COLOR_MUTED}Public docs:${WPRINT3D_COLOR_RESET} ${public_docs}")")
    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_WARN}Friendly tip:${WPRINT3D_COLOR_RESET} startup may take a few minutes on first run while images are pulled and built.")")
    panel_lines+=("$(wprint3d_box_border_text '-' "$WPRINT3D_COLOR_ACCENT")")

    for key in "${WPRINT3D_PROGRESS_ORDER[@]}"; do
        state="${WPRINT3D_PROGRESS_STATES[$key]:-pending}"
        message="${WPRINT3D_PROGRESS_MESSAGES[$key]:-Pending...}"
        detail="${WPRINT3D_PROGRESS_DETAILS[$key]:-}"
        icon="$(wprint3d_progress_icon "$state")"
        color="$(wprint3d_progress_color "$state")"
        label="${WPRINT3D_PROGRESS_LABELS[$key]}"

        progress_lines+=("${color}${icon}${WPRINT3D_COLOR_RESET} ${WPRINT3D_COLOR_TITLE}${label}:${WPRINT3D_COLOR_RESET} ${message}")
    done

    if [[ "$WPRINT3D_PANEL_SHOW_MENU" -eq 1 ]]; then
        fixed_lines=$((fixed_lines + 3))
    else
        fixed_lines=$((fixed_lines + 1))
    fi

    if [[ -n "$WPRINT3D_FINAL_MESSAGE" ]]; then
        fixed_lines=$((fixed_lines + 2))
    fi

    content_capacity=$((WPRINT3D_TERM_HEIGHT - fixed_lines))

    if [[ "$content_capacity" -lt 2 ]]; then
        content_capacity=2
    fi

    if [[ "$content_capacity" -ge 6 ]]; then
        min_log_lines=3
    elif [[ "$content_capacity" -ge 4 ]]; then
        min_log_lines=2
    fi

    max_progress_lines=$((content_capacity - min_log_lines))

    if [[ "$max_progress_lines" -lt 1 ]]; then
        max_progress_lines=1
    fi

    total_progress_lines="${#progress_lines[@]}"
    visible_progress_lines=("${progress_lines[@]}")

    if [[ "$total_progress_lines" -gt "$max_progress_lines" ]]; then
        visible_progress_lines=()

        if [[ "$max_progress_lines" -eq 1 ]]; then
            visible_progress_lines+=("${WPRINT3D_COLOR_MUTED}${WPRINT3D_SYMBOL_PENDING} ${total_progress_lines} startup steps tracked${WPRINT3D_COLOR_RESET}")
        else
            head_count=$(( (max_progress_lines - 1) / 2 ))

            if [[ "$head_count" -lt 1 ]]; then
                head_count=1
            elif [[ "$head_count" -gt 3 ]]; then
                head_count=3
            fi

            tail_count=$((max_progress_lines - head_count - 1))

            if [[ "$tail_count" -lt 0 ]]; then
                tail_count=0
            fi

            for ((progress_index = 0; progress_index < head_count; progress_index++)); do
                visible_progress_lines+=("${progress_lines[$progress_index]}")
            done

            hidden_progress_count=$((total_progress_lines - head_count - tail_count))

            if [[ "$hidden_progress_count" -lt 1 ]]; then
                hidden_progress_count=1
            fi

            visible_progress_lines+=("${WPRINT3D_COLOR_MUTED}${WPRINT3D_SYMBOL_PENDING} ${hidden_progress_count} more startup steps${WPRINT3D_COLOR_RESET}")

            if [[ "$tail_count" -gt 0 ]]; then
                for ((progress_index = total_progress_lines - tail_count; progress_index < total_progress_lines; progress_index++)); do
                    visible_progress_lines+=("${progress_lines[$progress_index]}")
                done
            fi
        fi
    fi

    for line in "${visible_progress_lines[@]}"; do
        panel_lines+=("$(wprint3d_box_line_text "$line")")
    done

    panel_lines+=("$(wprint3d_box_border_text '-' "$WPRINT3D_COLOR_ACCENT")")
    panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_TITLE}Live startup progress${WPRINT3D_COLOR_RESET}")")

    log_capacity=$((content_capacity - ${#visible_progress_lines[@]}))

    if [[ "$log_capacity" -lt 1 ]]; then
        log_capacity=1
    fi

    if [[ "${#WPRINT3D_EVENT_LOG[@]}" -gt "$log_capacity" ]]; then
        log_start_index=$(( ${#WPRINT3D_EVENT_LOG[@]} - log_capacity ))
    fi

    for ((log_index = log_start_index; log_index < ${#WPRINT3D_EVENT_LOG[@]}; log_index++)); do
        panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_MUTED}${WPRINT3D_EVENT_LOG[$log_index]}${WPRINT3D_COLOR_RESET}")")
    done

    displayed_count=$(( ${#WPRINT3D_EVENT_LOG[@]} - log_start_index ))
    missing_count=$(( log_capacity - displayed_count ))

    while [[ "$missing_count" -gt 0 ]]; do
        panel_lines+=("$(wprint3d_box_line_text '')")
        missing_count=$((missing_count - 1))
    done

    panel_lines+=("$(wprint3d_box_border_text '-' "$WPRINT3D_COLOR_ACCENT")")

    if [[ "$WPRINT3D_PANEL_SHOW_MENU" -eq 1 ]]; then
        panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_INFO}L${WPRINT3D_COLOR_RESET} View Logs | ${WPRINT3D_COLOR_INFO}R${WPRINT3D_COLOR_RESET} Restart Services | ${WPRINT3D_COLOR_INFO}W${WPRINT3D_COLOR_RESET} Access Web Interface | ${WPRINT3D_COLOR_INFO}F${WPRINT3D_COLOR_RESET} Send Feedback | ${WPRINT3D_COLOR_INFO}B${WPRINT3D_COLOR_RESET} Background Mode | ${WPRINT3D_COLOR_ERROR}S${WPRINT3D_COLOR_RESET} Shutdown")")
        panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_ERROR}Shutdown${WPRINT3D_COLOR_RESET} is destructive for the current session and will ask for confirmation.")")
    else
        startup_tick="$WPRINT3D_SPINNER_TICK"
        startup_spinner="$(wprint3d_startup_spinner_frame "$startup_tick")"
        startup_phrase="$(wprint3d_startup_phrase "$startup_tick")"
        WPRINT3D_SPINNER_TICK=$((WPRINT3D_SPINNER_TICK + 1))

        startup_footer_row=$(( ${#panel_lines[@]} + 1 ))
        panel_lines+=("$(wprint3d_box_split_line_text "${WPRINT3D_COLOR_WARN}Starting...${WPRINT3D_COLOR_RESET}" "${WPRINT3D_COLOR_INFO}${startup_spinner}${WPRINT3D_COLOR_RESET} ${WPRINT3D_COLOR_WARN}${startup_phrase}${WPRINT3D_COLOR_RESET}" "  ")")
    fi

    if [[ -n "$WPRINT3D_FINAL_MESSAGE" ]]; then
        panel_lines+=("$(wprint3d_box_border_text '-' "$WPRINT3D_COLOR_ACCENT")")
        panel_lines+=("$(wprint3d_box_line_text "${WPRINT3D_COLOR_OK}${WPRINT3D_FINAL_MESSAGE}${WPRINT3D_COLOR_RESET}")")
    fi

    panel_lines+=("$(wprint3d_box_border_text '=' "$WPRINT3D_COLOR_TITLE")")

    if [[ "$startup_footer_row" -gt 0 ]]; then
        for ((signature_index = 1; signature_index <= ${#panel_lines[@]}; signature_index++)); do
            if [[ "$signature_index" -eq "$startup_footer_row" ]]; then
                continue
            fi

            panel_static_signature+="${panel_lines[$((signature_index - 1))]}"$'\n'
        done

        if [[ "$WPRINT3D_PANEL_HAS_DRAWN" -eq 1 ]] \
            && [[ "$panel_static_signature" == "$WPRINT3D_LAST_PANEL_STATIC_SIGNATURE" ]] \
            && [[ "$startup_footer_row" -eq "$WPRINT3D_LAST_PANEL_FOOTER_ROW" ]]; then
            wprint3d_output_panel_footer_row "$startup_footer_row" "${panel_lines[$((startup_footer_row - 1))]}"
            return 0
        fi

        WPRINT3D_LAST_PANEL_STATIC_SIGNATURE="$panel_static_signature"
        WPRINT3D_LAST_PANEL_FOOTER_ROW="$startup_footer_row"
    else
        WPRINT3D_LAST_PANEL_STATIC_SIGNATURE=''
        WPRINT3D_LAST_PANEL_FOOTER_ROW=0
    fi

    wprint3d_output_panel_lines panel_lines
}

wprint3d_log_progress() {
    local key="$1"
    local state="$2"
    local message="$3"
    local detail="${4:-}"

    if [[ -z "${WPRINT3D_PROGRESS_LABELS[$key]+x}" ]]; then
        wprint3d_register_progress_item "$key" "$key"
    fi

    WPRINT3D_PROGRESS_STATES["$key"]="$state"
    WPRINT3D_PROGRESS_MESSAGES["$key"]="$message"
    WPRINT3D_PROGRESS_DETAILS["$key"]="$detail"

    wprint3d_append_event_log "$message"

    if [[ -n "$detail" ]] && [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
        wprint3d_append_event_log "$detail"
    fi

    if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
        wprint3d_render_control_panel
    else
        printf '%s\n' "$message"
    fi
}

wprint3d_register_startup_progress() {
    local service_name

    WPRINT3D_PROGRESS_ORDER=()
    WPRINT3D_PROGRESS_LABELS=()
    WPRINT3D_PROGRESS_STATES=()
    WPRINT3D_PROGRESS_MESSAGES=()
    WPRINT3D_PROGRESS_DETAILS=()
    WPRINT3D_EVENT_LOG=()

    wprint3d_register_progress_item runtime 'Runtime'
    wprint3d_register_progress_item images 'Images'

    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]] && [[ "${NO_BUILD:-0}" != '1' ]]; then
        wprint3d_register_progress_item build 'Local build'
    fi

    wprint3d_register_progress_item cleanup 'Cleanup'

    while IFS= read -r service_name; do
        wprint3d_register_progress_item "$service_name" "$(wprint3d_service_label "$service_name")"
    done < <(wprint3d_service_sequence "$WPRINT3D_CURRENT_ENV")
}

wprint3d_open_url() {
    local url="$1"

    if command -v xdg-open > /dev/null 2>&1; then
        xdg-open "$url" > /dev/null 2>&1 &
        return 0
    fi

    if command -v open > /dev/null 2>&1; then
        open "$url" > /dev/null 2>&1 &
        return 0
    fi

    if command -v wslview > /dev/null 2>&1; then
        wslview "$url" > /dev/null 2>&1 &
        return 0
    fi

    if command -v cmd.exe > /dev/null 2>&1; then
        cmd.exe /c start "" "$url" > /dev/null 2>&1
        return 0
    fi

    return 1
}

wprint3d_sanitize_captured_line() {
    local line="$1"

    line="$(wprint3d_strip_ansi "$line")"
    line="${line//$'\r'/}"
    line="${line//$'\t'/    }"
    line="$(printf '%s' "$line" | tr -d '\000-\010\013\014\016-\037\177')"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"

    printf '%s\n' "$line"
}

wprint3d_should_keep_captured_line() {
    local line="$1"

    if [[ -z "$line" ]]; then
        return 1
    fi

    if [[ "$line" =~ ^[[:xdigit:]]{24,}$ ]]; then
        return 1
    fi

    if [[ "$line" =~ ^--\>[[:space:]]+[[:xdigit:]]{10,}$ ]]; then
        return 1
    fi

    if [[ "$line" =~ ^podman-compose[[:space:]]version: ]]; then
        return 1
    fi

    if [[ "$line" == "['podman', '--version', '']" ]]; then
        return 1
    fi

    if [[ "$line" =~ ^using[[:space:]]podman[[:space:]]version: ]]; then
        return 1
    fi

    if [[ "$line" =~ ^exit[[:space:]]code:[[:space:]]0$ ]]; then
        return 1
    fi

    return 0
}

wprint3d_capture_exit_code_override() {
    local current_exit_code="${1:-0}"
    local line="$2"

    if [[ "$line" =~ ^exit[[:space:]]code:[[:space:]]([0-9]+)$ ]]; then
        printf '%s\n' "${BASH_REMATCH[1]}"
        return 0
    fi

    printf '%s\n' "$current_exit_code"
}

wprint3d_command_supports_pty_capture() {
    local command_name="${1:-}"

    case "$command_name" in
        run_host_compose|force_cleanup_stuck_containers)
            return 0
            ;;
    esac

    if declare -F "$command_name" > /dev/null 2>&1; then
        return 1
    fi

    return 0
}

wprint3d_write_capture_wrapper() {
    local wrapper_path="$1"

    cat > "$wrapper_path" <<'EOF'
#!/usr/bin/env bash
set +e

export WPRINT3D_RUN_SH_SOURCE_ONLY=1
source "$WPRINT3D_RUN_SH_PATH"

if [[ -n "${HOST_COMPOSE_COMMAND:-}" ]]; then
    read -r -a HOST_COMPOSE_COMMAND_ARGS <<< "$HOST_COMPOSE_COMMAND"
fi

if declare -F "$1" > /dev/null 2>&1; then
    "$@"
else
    exec "$@"
fi
EOF

    chmod +x "$wrapper_path"
}

wprint3d_run_captured_command_via_pty() {
    local output_pipe=''
    local wrapper_path=''
    local command_string=''
    local pid=''
    local exit_code=0
    local output_exit_code_override=0
    local render_interval="${WPRINT3D_SPINNER_INTERVAL_SECONDS:-0.08}"
    local previous_active_child_pid="${WPRINT3D_ACTIVE_CHILD_PID:-}"
    local capture_start_secs="$SECONDS"
    local capture_timeout_secs="${WPRINT3D_CAPTURE_TIMEOUT_SECS:-120}"
    local timed_out=0
    local line=''
    local arg=''

    output_pipe="$(mktemp -u)"
    wrapper_path="$(mktemp)"
    mkfifo "$output_pipe"
    wprint3d_write_capture_wrapper "$wrapper_path"

    command_string="$(printf '%q ' "$wrapper_path")"

    for arg in "$@"; do
        command_string+=$(printf '%q ' "$arg")
    done

    (
        WPRINT3D_RUN_SH_PATH="$SCRIPT_SOURCE" \
        HOST_CONTAINER_RUNTIME="${HOST_CONTAINER_RUNTIME:-}" \
        HOST_COMPOSE_COMMAND="${HOST_COMPOSE_COMMAND:-}" \
        HOST_PODMAN_ROOTFUL="${HOST_PODMAN_ROOTFUL:-0}" \
        CONTAINER_SOCKET_PATH="${CONTAINER_SOCKET_PATH:-}" \
        CONTAINER_LOG_DRIVER="${CONTAINER_LOG_DRIVER:-}" \
        IN_CONTAINER_CLI="${IN_CONTAINER_CLI:-}" \
        IN_CONTAINER_COMPOSE_COMMAND="${IN_CONTAINER_COMPOSE_COMMAND:-}" \
        script -qefc "$command_string" /dev/null > "$output_pipe" 2>&1
    ) < /dev/null &
    pid=$!
    WPRINT3D_ACTIVE_CHILD_PID="$pid"

    exec 3< "$output_pipe"

    while [[ "$WPRINT3D_INTERRUPT_REQUESTED" -eq 0 ]] && kill -0 "$pid" 2> /dev/null \
        && [[ "$((SECONDS - capture_start_secs))" -lt "$capture_timeout_secs" ]]; do
        if IFS= read -r -t "$render_interval" -u 3 line; then
            line="$(wprint3d_sanitize_captured_line "$line")"
            output_exit_code_override="$(wprint3d_capture_exit_code_override "$output_exit_code_override" "$line")"
            if wprint3d_should_keep_captured_line "$line"; then
                wprint3d_append_event_log "$line"
            fi
        fi

        while IFS= read -r -t 0.001 -u 3 line; do
            line="$(wprint3d_sanitize_captured_line "$line")"
            output_exit_code_override="$(wprint3d_capture_exit_code_override "$output_exit_code_override" "$line")"

            if wprint3d_should_keep_captured_line "$line"; then
                wprint3d_append_event_log "$line"
            fi
        done

        if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
            wprint3d_render_control_panel
        fi
    done

    if kill -0 "$pid" 2> /dev/null && [[ "$WPRINT3D_INTERRUPT_REQUESTED" -eq 0 ]]; then
        timed_out=1
        wprint3d_append_event_log "Command timed out after ${capture_timeout_secs}s — terminating stuck process..."
        wprint3d_signal_process_tree "$pid" TERM
        sleep 0.5
        kill -0 "$pid" 2> /dev/null && wprint3d_signal_process_tree "$pid" KILL
    fi

    while IFS= read -r -t 0.01 -u 3 line; do
        line="$(wprint3d_sanitize_captured_line "$line")"
        output_exit_code_override="$(wprint3d_capture_exit_code_override "$output_exit_code_override" "$line")"
        wprint3d_should_keep_captured_line "$line" || continue
        wprint3d_append_event_log "$line"
    done

    exec 3<&-

    wait "$pid"
    exit_code=$?
    WPRINT3D_ACTIVE_CHILD_PID="$previous_active_child_pid"

    if [[ "$timed_out" -eq 1 ]] && [[ "$exit_code" -eq 0 ]]; then
        exit_code=124
    fi

    if [[ "$exit_code" -eq 0 ]] && [[ "$output_exit_code_override" -ne 0 ]]; then
        exit_code="$output_exit_code_override"
    fi

    rm -f "$output_pipe" "$wrapper_path"

    return "$exit_code"
}

wprint3d_run_captured_command() {
    local output_file=''
    local pid=''
    local exit_code=0
    local output_exit_code_override=0
    local last_line_count=0
    local current_line_count=0
    local render_interval="${WPRINT3D_SPINNER_INTERVAL_SECONDS:-0.08}"
    local previous_active_child_pid="${WPRINT3D_ACTIVE_CHILD_PID:-}"
    local line=''

    if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]] \
        && command -v script > /dev/null 2>&1 \
        && wprint3d_command_supports_pty_capture "$@"; then
        wprint3d_run_captured_command_via_pty "$@"

        return $?
    fi

    output_file="$(mktemp)"

    "$@" >"$output_file" 2>&1 &
    pid=$!
    WPRINT3D_ACTIVE_CHILD_PID="$pid"

    while [[ "$WPRINT3D_INTERRUPT_REQUESTED" -eq 0 ]] && kill -0 "$pid" 2> /dev/null; do
        current_line_count="$(wc -l < "$output_file" 2> /dev/null || printf '0')"

        if [[ "$current_line_count" =~ ^[0-9]+$ ]] && [[ "$current_line_count" -gt "$last_line_count" ]]; then
            while IFS= read -r line; do
                line="$(wprint3d_sanitize_captured_line "$line")"
                output_exit_code_override="$(wprint3d_capture_exit_code_override "$output_exit_code_override" "$line")"
                wprint3d_should_keep_captured_line "$line" || continue
                wprint3d_append_event_log "$line"
            done < <(sed -n "$((last_line_count + 1)),${current_line_count}p" "$output_file")

            last_line_count="$current_line_count"

            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_render_control_panel
            fi
        fi

        if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
            wprint3d_render_control_panel
        fi

        sleep "$render_interval"
    done

    wait "$pid"
    exit_code=$?
    WPRINT3D_ACTIVE_CHILD_PID="$previous_active_child_pid"

    current_line_count="$(wc -l < "$output_file" 2> /dev/null || printf '0')"

    if [[ "$current_line_count" =~ ^[0-9]+$ ]] && [[ "$current_line_count" -gt "$last_line_count" ]]; then
        while IFS= read -r line; do
            line="$(wprint3d_sanitize_captured_line "$line")"
            output_exit_code_override="$(wprint3d_capture_exit_code_override "$output_exit_code_override" "$line")"
            wprint3d_should_keep_captured_line "$line" || continue
            wprint3d_append_event_log "$line"
        done < <(sed -n "$((last_line_count + 1)),${current_line_count}p" "$output_file")

        if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
            wprint3d_render_control_panel
        fi
    fi

    if [[ "$exit_code" -eq 0 ]] && [[ "$output_exit_code_override" -ne 0 ]]; then
        exit_code="$output_exit_code_override"
    fi

    rm -f "$output_file"

    return "$exit_code"
}

wprint3d_compose_args() {
    WPRINT3D_COMPOSE_ARGS=()

    if [[ -n "${1:-}" ]]; then
        WPRINT3D_COMPOSE_ARGS=(-f "$1")
    fi
}

wprint3d_compose_project_name() {
    local compose_project="${COMPOSE_PROJECT_NAME:-$(basename "$SCRIPT_PATH")}"

    compose_project="${compose_project,,}"

    while [[ -n "$compose_project" ]] && [[ "${compose_project:0:1}" =~ [^a-zA-Z0-9] ]]; do
        compose_project="${compose_project:1}"
    done

    printf '%s\n' "$compose_project"
}

wprint3d_last_service_log_line() {
    local service_name="$1"
    local compose_file="${2:-}"

    wprint3d_compose_args "$compose_file"
    run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" logs --tail 10 "$service_name" 2>&1 | tail -n 1
}

wprint3d_container_id_for_service() {
    local service_name="$1"
    local compose_file="${2:-}"
    local compose_project=''

    compose_project="$(wprint3d_compose_project_name)"

    run_host_container_cli ps -a \
        --filter "label=com.docker.compose.project=${compose_project}" \
        --filter "label=com.docker.compose.service=${service_name}" \
        --format '{{.ID}}' 2> /dev/null | head -n 1
}

wprint3d_container_status() {
    local container_id="$1"

    if [[ -z "$container_id" ]]; then
        return 1
    fi

    run_host_container_cli inspect --format '{{.State.Status}}' "$container_id" 2> /dev/null
}

wprint3d_container_process_matches() {
    local container_id="$1"
    shift

    local process_list
    local pattern

    process_list="$(run_host_container_cli top "$container_id" 2> /dev/null)" || return 1

    for pattern in "$@"; do
        if printf '%s\n' "$process_list" | tail -n +2 | grep -F "$pattern" > /dev/null 2>&1; then
            return 0
        fi
    done

    return 1
}

wprint3d_endpoint_ready() {
    local url="$1"

    if [[ "$url" == https://* ]]; then
        curl --silent --show-error --insecure --fail --max-time 3 "$url" > /dev/null 2>&1
    else
        curl --silent --show-error --fail --max-time 3 "$url" > /dev/null 2>&1
    fi
}

wprint3d_service_is_ready() {
    local service_name="$1"
    local container_id="$2"

    case "$service_name" in
        redis)
            wprint3d_container_process_matches "$container_id" 'redis-server'
            ;;
        mongo)
            wprint3d_container_process_matches "$container_id" 'mongod'
            ;;
        backend)
            wprint3d_container_process_matches "$container_id" 'php'
            ;;
        proxy)
            wprint3d_endpoint_ready "$(wprint3d_web_interface_url "$WPRINT3D_CURRENT_ENV")" || wprint3d_container_process_matches "$container_id" 'nginx'
            ;;
        mapper)
            wprint3d_container_process_matches "$container_id" 'udevadm' || [[ "$(wprint3d_container_status "$container_id")" == 'running' ]]
            ;;
        streamer)
            wprint3d_container_process_matches "$container_id" 'inotifywait'
            ;;
        web)
            if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
                wprint3d_endpoint_ready 'http://localhost:8081'
            else
                [[ "$(wprint3d_container_status "$container_id")" == 'running' ]]
            fi
            ;;
        memcached)
            wprint3d_container_process_matches "$container_id" 'memcached'
            ;;
        yv-streamer-software)
            [[ "$(wprint3d_container_status "$container_id")" == 'running' ]]
            ;;
        *)
            [[ "$(wprint3d_container_status "$container_id")" == 'running' ]]
            ;;
    esac
}

wprint3d_wait_for_service_ready() {
    local service_name="$1"
    local compose_file="${2:-}"
    local timeout_secs="${3:-90}"
    local waited=0
    local container_id=''
    local status=''

    while [[ "$waited" -lt "$timeout_secs" ]]; do
        container_id="$(wprint3d_container_id_for_service "$service_name" "$compose_file")"

        if [[ -n "$container_id" ]]; then
            status="$(wprint3d_container_status "$container_id" 2> /dev/null || true)"

            if [[ "$status" == 'running' ]]; then
                return 0
            fi

            if [[ "$status" == 'exited' ]] || [[ "$status" == 'dead' ]]; then
                return 1
            fi
        fi

        local render_ticks=10
        while [[ "$render_ticks" -gt 0 ]]; do
            sleep 0.1
            render_ticks=$((render_ticks - 1))
            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_render_control_panel
            fi
        done
        waited=$((waited + 1))
    done

    return 1
}

wprint3d_start_services_with_progress() {
    local env_name="$1"
    local compose_file="${2:-}"
    local service_name=''
    local service_label=''
    local detail=''

    while IFS= read -r service_name; do
        service_label="$(wprint3d_service_label "$service_name")"
        wprint3d_register_progress_item "$service_name" "$service_label"
        wprint3d_log_progress "$service_name" starting "Starting ${service_label}..."
        wprint3d_append_event_log "Launching compose service for ${service_label}."

        wprint3d_compose_args "$compose_file"

        if [[ "$env_name" == 'production' ]]; then
            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_run_captured_command run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" up -d --no-deps --force-recreate "$service_name" || {
                    detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
                    wprint3d_log_progress "$service_name" failed "${service_label} failed to start." "$detail"
                    return 1
                }
            else
                run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" up -d --no-deps --force-recreate "$service_name" || {
                    detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
                    wprint3d_log_progress "$service_name" failed "${service_label} failed to start." "$detail"
                    return 1
                }
            fi
        else
            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_run_captured_command run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" up -d --no-deps "$service_name" || {
                    detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
                    wprint3d_log_progress "$service_name" failed "${service_label} failed to start." "$detail"
                    return 1
                }
            else
                run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" up -d --no-deps "$service_name" || {
                    detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
                    wprint3d_log_progress "$service_name" failed "${service_label} failed to start." "$detail"
                    return 1
                }
            fi
        fi

        local container_id='' container_status=''
        local wait_secs=0

        while [[ "$wait_secs" -lt 5 ]]; do
            container_id="$(wprint3d_container_id_for_service "$service_name" "$compose_file")"
            container_status="$(wprint3d_container_status "$container_id" 2>/dev/null || true)"

            if [[ "$container_status" == 'running' ]]; then
                break
            fi

            if [[ "$container_status" == 'exited' ]] || [[ "$container_status" == 'dead' ]]; then
                detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
                wprint3d_log_progress "$service_name" failed "${service_label} exited immediately." "$detail"
                return 1
            fi

            sleep 1
            wait_secs=$((wait_secs + 1))

            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_render_control_panel
            fi
        done

        if [[ "$container_status" != 'running' ]]; then
            detail="$(wprint3d_last_service_log_line "$service_name" "$compose_file")"
            wprint3d_log_progress "$service_name" failed "${service_label} did not start in time." "$detail"
            return 1
        fi

        wprint3d_log_progress "$service_name" ready "${service_label} is up!"
    done < <(wprint3d_service_sequence "$env_name")
}

wprint3d_wait_for_web_interface() {
    local url
    local waited=0

    url="$(wprint3d_web_interface_url "$WPRINT3D_CURRENT_ENV")"

    while [[ "$waited" -lt 30 ]]; do
        if wprint3d_endpoint_ready "$url"; then
            return 0
        fi

        sleep 1
        waited=$((waited + 1))
    done

    return 1
}

wprint3d_read_menu_choice() {
    local choice=''

    if [[ -r /dev/tty ]]; then
        read -r choice < /dev/tty || return 1
    else
        read -r choice || return 1
    fi

    printf '%s\n' "$choice"
}

wprint3d_read_confirmation() {
    local confirm=''

    if [[ -r /dev/tty ]]; then
        read -r confirm < /dev/tty || return 1
    else
        read -r confirm || return 1
    fi

    printf '%s\n' "$confirm"
}

wprint3d_stream_logs_view() {
    local compose_file="${1:-}"
    local log_exit_code=0
    local consecutive_failures=0
    local compose_project=''
    local container_ids=()

    trap '' INT TERM
    wprint3d_restore_terminal
    echo 'Streaming logs. Press Ctrl+C to return to the control panel.'
    wprint3d_compose_args "$compose_file"
    compose_project="$(wprint3d_compose_project_name)"

    while true; do
        if [[ "${HOST_CONTAINER_RUNTIME:-}" == 'podman' ]]; then
            # podman-compose logs -f tries all services from the compose file, including
            # stopped ones, and fails with exit 125 when any container name is not found.
            # Use podman logs directly on only the running project containers instead.
            container_ids=()
            while IFS= read -r cid; do
                [[ -n "$cid" ]] && container_ids+=("$cid")
            done < <(run_host_container_cli ps \
                --filter "label=com.docker.compose.project=${compose_project}" \
                --format '{{.ID}}' 2>/dev/null)

            if [[ "${#container_ids[@]}" -eq 0 ]]; then
                echo "No running containers found for project '${compose_project}'."
                echo "Press Enter to return to the control panel."
                (trap - INT TERM; read -r < /dev/tty) || true
                break
            fi

            (trap - INT TERM; run_host_container_cli logs --follow --tail 80 --names "${container_ids[@]}")
        else
            (trap - INT TERM; run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" logs -f --tail 80)
        fi
        log_exit_code=$?

        if [[ "$log_exit_code" -eq 130 ]] || [[ "$log_exit_code" -eq 143 ]]; then
            break
        fi

        consecutive_failures=$((consecutive_failures + 1))

        if [[ "$consecutive_failures" -ge 3 ]]; then
            echo
            echo "Log streaming failed after ${consecutive_failures} attempts (last exit code: ${log_exit_code})."
            echo "Press Enter to return to the control panel."
            (trap - INT TERM; read -r < /dev/tty) || true
            break
        fi

        echo
        echo "Log stream disconnected (exit code: ${log_exit_code}). Reconnecting in 1 second. Press Ctrl+C to return."
        (trap - INT TERM; sleep 1)
        if [[ $? -eq 130 ]] || [[ $? -eq 143 ]]; then
            break
        fi
    done

    wprint3d_prepare_terminal
    wprint3d_install_panel_traps
}

wprint3d_run_menu() {
    local access_url feedback_url choice confirm

    if wprint3d_bool_is_true "${WPRINT3D_SKIP_MENU:-0}"; then
        return 0
    fi

    access_url="$(wprint3d_web_interface_url "$WPRINT3D_CURRENT_ENV")"
    feedback_url="$(wprint3d_feedback_url)"
    WPRINT3D_PANEL_SHOW_MENU=1

    while true; do
        wprint3d_render_control_panel

        printf '\n%s Choose an action [L/R/W/F/B/S]: ' "$WPRINT3D_SYMBOL_MENU"
        choice="$(wprint3d_read_menu_choice)" || {
            WPRINT3D_FINAL_MESSAGE="Interactive input is no longer available. Services will keep running in the background at ${access_url}"
            wprint3d_render_control_panel
            echo
            break
        }

        case "${choice^^}" in
            L)
                wprint3d_stream_logs_view "$WPRINT3D_CURRENT_COMPOSE_FILE"
                ;;
            R)
                wprint3d_log_progress runtime starting 'Restarting services...'
                wprint3d_compose_args "$WPRINT3D_CURRENT_COMPOSE_FILE"
                run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" restart || {
                    wprint3d_log_progress runtime failed 'Service restart failed.'
                    continue
                }

                if wprint3d_wait_for_web_interface; then
                    wprint3d_log_progress runtime ready 'Services restarted successfully.'
                    WPRINT3D_FINAL_MESSAGE="All services are up and running again at ${access_url}"
                else
                    wprint3d_log_progress runtime failed 'Services restarted, but the web interface did not come back in time.'
                fi
                ;;
            W)
                if wprint3d_open_url "$access_url"; then
                    WPRINT3D_FINAL_MESSAGE="Opening ${access_url} in your browser."
                else
                    WPRINT3D_FINAL_MESSAGE="Open ${access_url} in your browser."
                fi
                ;;
            F)
                if wprint3d_open_url "$feedback_url"; then
                    WPRINT3D_FINAL_MESSAGE='Opening the GitHub issue page for feedback.'
                else
                    WPRINT3D_FINAL_MESSAGE="Open ${feedback_url} to send feedback."
                fi
                ;;
            B)
                WPRINT3D_FINAL_MESSAGE="Interactive mode closed. Services will keep running in the background at ${access_url}"
                wprint3d_render_control_panel
                echo
                break
                ;;
            '')
                WPRINT3D_FINAL_MESSAGE='Press L, R, W, F, B, or S to use the control panel.'
                ;;
            S)
                printf '%sShutdown all services and exit? [y/N] %s' "$WPRINT3D_COLOR_ERROR" "$WPRINT3D_COLOR_RESET"
                confirm="$(wprint3d_read_confirmation)" || {
                    WPRINT3D_FINAL_MESSAGE='Shutdown cancelled because confirmation input was unavailable.'
                    continue
                }

                case "$confirm" in
                    y|Y|yes|YES)
                        wprint3d_log_progress runtime starting 'Stopping services...'
                        wprint3d_compose_args "$WPRINT3D_CURRENT_COMPOSE_FILE"
                        run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" down --timeout 5
                        WPRINT3D_FINAL_MESSAGE='All services have been stopped. Goodbye.'
                        wprint3d_render_control_panel
                        echo
                        return 0
                        ;;
                esac
                ;;
            *)
                WPRINT3D_FINAL_MESSAGE='Unknown action. Choose L, R, W, F, B, or S.'
                ;;
        esac
    done
}

wprint3d_pull_and_build_images() {
    local compose_file="${1:-}"

    wprint3d_log_progress images starting 'Pulling container images...'
    wprint3d_compose_args "$compose_file"

    if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
        wprint3d_run_captured_command run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" pull || {
            wprint3d_log_progress images failed 'Failed to pull container images.'
            return 1
        }
    else
        run_host_compose "${WPRINT3D_COMPOSE_ARGS[@]}" pull || {
            wprint3d_log_progress images failed 'Failed to pull container images.'
            return 1
        }
    fi

    wprint3d_log_progress images ready 'Container images are ready.'

    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]] && [[ "${NO_BUILD:-0}" != '1' ]]; then
        wprint3d_log_progress build starting 'Building local development images...'

        if [[ "$HOST_COMPOSE_COMMAND" == 'podman-compose' ]]; then
            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_run_captured_command run_host_compose -f "$compose_file" build || {
                    wprint3d_log_progress build failed 'Development image build failed.'
                    return 1
                }
            else
                run_host_compose -f "$compose_file" build || {
                    wprint3d_log_progress build failed 'Development image build failed.'
                    return 1
                }
            fi
        else
            if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
                wprint3d_run_captured_command run_host_compose -f "$compose_file" build --progress plain || {
                    wprint3d_log_progress build failed 'Development image build failed.'
                    return 1
                }
            else
                run_host_compose -f "$compose_file" build --progress plain || {
                    wprint3d_log_progress build failed 'Development image build failed.'
                    return 1
                }
            fi
        fi

        wprint3d_log_progress build ready 'Development images are built.'
    fi
}

wprint3d_prepare_runtime_for_environment() {
    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
        if [[ ! -d 'frontend' ]]; then
            echo 'The frontend directory is missing. Restore it from git and try again.'

            return 1
        fi

        if declare -F detect_host_container_runtime > /dev/null 2>&1; then
            detect_host_container_runtime || return 1
            HOST_CONTAINER_RUNTIME="${DETECTED_HOST_CONTAINER_RUNTIME}"
            export HOST_CONTAINER_RUNTIME
        fi

        if declare -F configure_podman_host_access > /dev/null 2>&1; then
            configure_podman_host_access || return 1
        fi

        if declare -F migrate_docker_volumes_to_podman > /dev/null 2>&1; then
            migrate_docker_volumes_to_podman "$WPRINT3D_CURRENT_ENV" || return 1
        fi

        init_container_runtime || return 1

        if declare -F frontend_node_modules_needs_permission_repair > /dev/null 2>&1; then
            offer_frontend_node_modules_reownership || return 1
        fi

        if declare -F run_host_container_cli > /dev/null 2>&1; then
            ensure_podman_development_ports_supported || return 1
        fi

        WPRINT3D_CURRENT_COMPOSE_FILE='docker-compose-development.yml'
    else
        if declare -F detect_host_container_runtime > /dev/null 2>&1; then
            detect_host_container_runtime || return 1
            HOST_CONTAINER_RUNTIME="${DETECTED_HOST_CONTAINER_RUNTIME}"
            export HOST_CONTAINER_RUNTIME
        fi

        if declare -F configure_podman_host_access > /dev/null 2>&1; then
            configure_podman_host_access || return 1
        fi

        if declare -F migrate_docker_volumes_to_podman > /dev/null 2>&1; then
            migrate_docker_volumes_to_podman "$WPRINT3D_CURRENT_ENV" || return 1
        fi

        init_container_runtime || return 1
        WPRINT3D_CURRENT_COMPOSE_FILE=''
    fi

    return 0
}

wprint3d_cleanup_before_startup() {
    local container_name=''

    wprint3d_log_progress cleanup starting 'Cleaning up old helper containers and stale states...'

    if [[ "$HOST_CONTAINER_RUNTIME" == 'docker' ]]; then
        for container_name in $(run_host_container_cli ps --format '{{ .Names }}' | grep buildx_buildkit_builder || true); do
            run_host_container_cli stop "$container_name" > /dev/null 2>&1 || true
        done
    fi

    if declare -F force_cleanup_stuck_containers > /dev/null 2>&1; then
        if [[ "$WPRINT3D_PANEL_ACTIVE" -eq 1 ]]; then
            wprint3d_run_captured_command force_cleanup_stuck_containers || return 1
        else
            force_cleanup_stuck_containers || return 1
        fi
    fi

    wprint3d_log_progress cleanup ready 'Startup cleanup finished.'
}

wprint3d_activate_panel() {
    wprint3d_init_ui
    wprint3d_register_startup_progress
    WPRINT3D_PANEL_ACTIVE=1
    WPRINT3D_PANEL_SHOW_MENU=0
    WPRINT3D_PANEL_HAS_DRAWN=0
    WPRINT3D_SPINNER_TICK=0
    WPRINT3D_LAST_PANEL_STATIC_SIGNATURE=''
    WPRINT3D_LAST_PANEL_FOOTER_ROW=0
    WPRINT3D_ACTIVE_CHILD_PID=''
    WPRINT3D_INTERRUPT_REQUESTED=0
    wprint3d_prepare_terminal
    wprint3d_install_panel_traps
    wprint3d_render_control_panel
}

wprint3d_start_environment_interactive() {
    local access_url

    wprint3d_activate_panel
    wprint3d_log_progress runtime ready "Using ${HOST_CONTAINER_RUNTIME} with ${HOST_COMPOSE_COMMAND}."
    wprint3d_pull_and_build_images "$WPRINT3D_CURRENT_COMPOSE_FILE" || return 1
    wprint3d_cleanup_before_startup || return 1

    if ! wprint3d_start_services_with_progress "$WPRINT3D_CURRENT_ENV" "$WPRINT3D_CURRENT_COMPOSE_FILE"; then
        WPRINT3D_FINAL_MESSAGE="One or more services failed to start. Check the event log for details."
        WPRINT3D_PANEL_SHOW_MENU=1
        wprint3d_render_control_panel
        wprint3d_run_menu
        return 0
    fi

    if declare -F ensure_podman_forward_rules > /dev/null 2>&1; then
        ensure_podman_forward_rules || return 1
    fi

    access_url="$(wprint3d_web_interface_url "$WPRINT3D_CURRENT_ENV")"

    if wprint3d_wait_for_web_interface; then
        WPRINT3D_FINAL_MESSAGE="All services are up and running! Access the web interface at ${access_url}"
    else
        WPRINT3D_FINAL_MESSAGE="Services started, but the web interface did not answer yet. Try ${access_url} in a moment."
    fi

    WPRINT3D_PANEL_SHOW_MENU=1
    wprint3d_render_control_panel
    wprint3d_run_menu
}

wprint3d_start_environment_noninteractive() {
    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
        run_host_compose -f docker-compose-development.yml pull || return 1

        if [[ "${NO_BUILD:-0}" != '1' ]]; then
            if [[ "$HOST_COMPOSE_COMMAND" == 'podman-compose' ]]; then
                run_host_compose -f docker-compose-development.yml build || return 1
            else
                run_host_compose -f docker-compose-development.yml build --progress plain || return 1
            fi
        fi
    else
        run_host_compose pull || return 1
    fi

    if [[ "$HOST_CONTAINER_RUNTIME" == 'docker' ]]; then
        for container_name in $(run_host_container_cli ps --format '{{ .Names }}' | grep buildx_buildkit_builder || true); do
            run_host_container_cli stop "$container_name"
        done
    fi

    if declare -F force_cleanup_stuck_containers > /dev/null 2>&1; then
        force_cleanup_stuck_containers || return 1
    fi

    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
        echo 'Starting development environment...'

        if [[ -f 'docker-compose.override.yml' ]]; then
            run_host_compose -f docker-compose-development.yml -f docker-compose.override.yml up -d --remove-orphans || return 1
        else
            run_host_compose -f docker-compose-development.yml up -d --remove-orphans || return 1
        fi
    else
        echo 'Starting production environment...'
        run_host_compose up -d --remove-orphans --force-recreate || return 1
    fi

    if declare -F ensure_podman_forward_rules > /dev/null 2>&1; then
        ensure_podman_forward_rules || return 1
    fi
}

wprint3d_ensure_prebuilts_storage() {
    if [[ -d 'bin' ]]; then
        return 0
    fi

    printf 'Creating prebuilts storage... '
    mkdir bin

    if [[ $? -eq 0 ]]; then
        printf 'OK\n'
    else
        return 1
    fi
}

wprint3d_ensure_inotify_capacity() {
    if [[ ! -r /proc/sys/fs/inotify/max_user_watches ]]; then
        return 0
    fi

    if [[ $(cat /proc/sys/fs/inotify/max_user_watches) -lt 65536 ]]; then
        printf '%s\n' fs.inotify.max_user_watches=65536 | run_with_elevation tee -a /etc/sysctl.conf > /dev/null && run_with_elevation sysctl -p
    fi
}

wprint3d_parse_args() {
    NO_BUILD=0
    WPRINT3D_CURRENT_ENV='production'

    if [[ "${1:-}" == '-h' ]] || [[ "${1:-}" == '--help' ]]; then
        printf 'Usage \n\n%s [-e dev | --environment dev] (builds and runs the image locally)\n' "$0"

        return 2
    elif [[ "${1:-}" == '-e' ]] || [[ "${1:-}" == '--environment' ]]; then
        case "${2:-}" in
            dev)
                WPRINT3D_CURRENT_ENV='dev'
                ;;
            '')
                printf 'The environment cannot be empty.\n'
                return 1
                ;;
            *)
                printf 'Invalid environment "%s".\n' "$2"
                return 1
                ;;
        esac
    fi

    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]] && [[ "${3:-}" == '-n' || "${3:-}" == '--no-build' ]]; then
        NO_BUILD=1
    fi

    return 0
}

wprint3d_ensure_production_compose_file() {
    local temp_file=''
    local default_branch=''

    if [[ "$WPRINT3D_CURRENT_ENV" == 'dev' ]]; then
        return 0
    fi

    if [[ ! -f 'docker-compose.yml' ]] || grep -q 'wprint3d' 'docker-compose.yml' || [[ ! -s 'docker-compose.yml' ]]; then
        if [[ ! -f 'docker-compose.yml' ]]; then
            echo 'The docker-compose.yml file is missing, downloading it...'
        else
            echo 'Updating the docker-compose.yml file...'
        fi

        temp_file="$(mktemp --suffix=-wprint3d-docker-compose)"

        default_branch="$(curl -sfL -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' https://api.github.com/repos/wprint3d/wprint3d-core | grep default | sed 's/.*: "//' | sed 's/".*//')"

        if [[ -z "$default_branch" ]]; then
            echo 'Failed to get the default branch.'

            return 1
        fi

        curl -fL "https://raw.githubusercontent.com/wprint3d/wprint3d/${default_branch}/docker-compose.yml" > "$temp_file"

        if [[ ! -f "$temp_file" ]] || [[ ! -s "$temp_file" ]]; then
            if [[ -f 'docker-compose.yml' ]]; then
                echo 'Failed to download the new docker-compose.yml file.'
            else
                echo 'Failed to download the docker-compose.yml file.'
            fi

            return 1
        fi

        if [[ -f 'docker-compose.yml' ]]; then
            mv -fv 'docker-compose.yml' 'docker-compose.yml.bak'
            echo 'The old docker-compose.yml file was renamed to docker-compose.yml.bak.'
        fi

        mv -fv "$temp_file" 'docker-compose.yml'
        normalize_compose_logging_driver 'docker-compose.yml'
        echo 'The docker-compose.yml file was updated.'

        if [[ -f 'docker-compose.yml.bak' ]]; then
            echo 'You can remove the old docker-compose.yml file by running: rm docker-compose.yml.bak'
        fi
    elif ! grep -q 'wprint3d' 'docker-compose.yml'; then
        echo 'The docker-compose.yml file present is not compatible with this project, please create a new directory, cd into it and run this script again.'

        return 1
    fi

    return 0
}

main() {
    local parse_result=0

    wprint3d_parse_args "$@" || parse_result=$?

    if [[ "$parse_result" -eq 2 ]]; then
        exit 0
    elif [[ "$parse_result" -ne 0 ]]; then
        exit "$parse_result"
    fi

    wprint3d_ensure_production_compose_file || exit 1
    prepare_startup_elevation || exit 1
    wprint3d_ensure_prebuilts_storage || exit 1
    wprint3d_ensure_inotify_capacity || exit 1
    wprint3d_prepare_runtime_for_environment || exit 1

    if wprint3d_should_use_interactive_ui; then
        wprint3d_start_environment_interactive || exit 1
    else
        wprint3d_start_environment_noninteractive || exit 1
    fi
}

if [[ "${WPRINT3D_RUN_SH_SOURCE_ONLY:-0}" == '1' ]]; then
    return 0 2>/dev/null || exit 0
fi

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
