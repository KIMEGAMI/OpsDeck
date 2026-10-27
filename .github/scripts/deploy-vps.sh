#!/usr/bin/env bash
set -euo pipefail

readonly SSH_CONNECT_TIMEOUT_SECONDS=10
readonly HEALTH_CHECK_ATTEMPTS=10
readonly HEALTH_CHECK_DELAY_SECONDS=5
readonly HEALTH_CHECK_TIMEOUT_SECONDS=15
readonly QUEUE_STARTUP_SETTLE_SECONDS=3
readonly RUNTIME_RELOAD_WRAPPER=/usr/local/sbin/opsdeck-reload-runtime

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

validate_remote_root() {
    local root="$1"

    [[ "$root" == /var/www/* && "$root" != *..* && "$root" != *"//"* && "$root" != *" "* ]] || fail "OPSDECK_DEPLOY_ROOT must be a safe absolute path under /var/www."
}

validate_release_id() {
    local sha="$1"
    local deployment_id="$2"

    [[ "$sha" =~ ^[a-f0-9]{40}$ ]] || fail "The release commit SHA is invalid."
    [[ "$deployment_id" =~ ^[0-9]+-[0-9]+$ ]] || fail "The deployment run identifier is invalid."
}

remote_layout() {
    local root="$1"
    local php_bin="$2"
    local releases_path
    local current_target

    validate_remote_root "$root"
    [[ "$(id -u)" -ne 0 ]] || fail "The VPS deployment account must not be root."
    [[ "$php_bin" =~ ^/[A-Za-z0-9_./-]+$ && "$php_bin" != *"/../"* ]] || fail "OPSDECK_DEPLOY_PHP_BIN must be an absolute executable path."
    [[ -x "$php_bin" ]] || fail "The configured PHP CLI executable is unavailable on the VPS."
    [[ -d "$root/releases" && -d "$root/shared" && -d "$root/.incoming" ]] || fail "The VPS release directories are not provisioned."
    [[ -w "$root/.incoming" ]] || fail "The deployment account cannot write to the VPS incoming directory."
    [[ -f "$root/shared/.env" && -r "$root/shared/.env" ]] || fail "The shared production .env file is unavailable to the PHP CLI user."
    [[ -d "$root/shared/storage" && -w "$root/shared/storage" ]] || fail "The shared production storage directory is unavailable to the PHP CLI user."
    [[ ! -e "$root/current" || -L "$root/current" ]] || fail "The current application path must be a release symlink when present."
    [[ ! -e "$root/previous" || -L "$root/previous" ]] || fail "The previous release path must be a symlink when present."

    releases_path="$(realpath -e -- "$root/releases")" || fail "The VPS release directory cannot be resolved."
    if [[ -L "$root/current" ]]; then
        current_target="$(realpath -e -- "$root/current")" || fail "The current release symlink is invalid."
        case "$current_target" in
            "$releases_path"/*) ;;
            *) fail "The current release symlink points outside the configured releases directory." ;;
        esac
        [[ -f "$current_target/artisan" && -f "$current_target/vendor/autoload.php" ]] || fail "The current release is incomplete."
    elif [[ -L "$root/previous" ]]; then
        fail "A previous release exists without a current release; manual recovery is required."
    fi

    if ! sudo -n "$RUNTIME_RELOAD_WRAPPER" --check >/dev/null 2>&1; then
        fail "The root-owned runtime reload wrapper is missing or its read-only preflight failed."
    fi
}

remote_preflight() {
    local root="$1"
    local php_bin="$2"
    local current_target
    local readiness_output
    local readiness_json

    remote_layout "$root" "$php_bin"
    if [[ ! -L "$root/current" ]]; then
        printf '%s\n' '{"status":"first_deploy","checks":{"release_layout":"pass"}}'

        return
    fi

    current_target="$(realpath -e -- "$root/current")"
    if ! readiness_output="$("$php_bin" "$current_target/artisan" opsdeck:deployment-check --json --no-interaction 2>/dev/null)"; then
        fail "OpsDeck production readiness check failed; no release files were changed."
    fi
    readiness_json="$(printf '%s\n' "$readiness_output" | grep -m1 -E '^\{.*\}$' || true)"
    [[ -n "$readiness_json" ]] || fail "OpsDeck production readiness check did not return valid JSON."
    printf '%s\n' "$readiness_json"
}

atomic_symlink() {
    local root="$1"
    local link_name="$2"
    local target="$3"
    local deployment_id="$4"
    local temporary_link="$root/.${link_name}-${deployment_id}-$$.tmp"

    [[ ! -e "$temporary_link" && ! -L "$temporary_link" ]] || fail "A temporary deployment symlink already exists."
    ln -s -- "$target" "$temporary_link"
    mv -Tf -- "$temporary_link" "$root/$link_name"
}

rollback_after_readiness_failure() {
    local root="$1"
    local php_bin="$2"
    local has_previous_release="$3"
    local current_target="$4"
    local release_path="$5"
    local deployment_id="$6"

    if [[ "$has_previous_release" == true ]]; then
        atomic_symlink "$root" current "$current_target" "$deployment_id"
        atomic_symlink "$root" previous "$release_path" "$deployment_id"
        "$php_bin" "$current_target/artisan" queue:restart --no-interaction >/dev/null 2>&1 || true
    else
        "$php_bin" "$release_path/artisan" queue:restart --no-interaction >/dev/null 2>&1 || true
        rm -f -- "$root/current"
    fi

    sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || true
}
remote_deploy() {
    local root="$1"
    local sha="$2"
    local deployment_id="$3"
    local php_bin="$4"
    local releases_path
    local current_target
    local release_id
    local release_path
    local archive_path
    local staging_path
    local readiness_output
    local readiness_json
    local has_previous_release=false

    validate_release_id "$sha" "$deployment_id"
    umask 0022
    remote_layout "$root" "$php_bin"
    releases_path="$(realpath -e -- "$root/releases")"
    if [[ -L "$root/current" ]]; then
        current_target="$(realpath -e -- "$root/current")"
        has_previous_release=true
    else
        current_target=""
    fi
    release_id="$sha-$deployment_id"
    release_path="$releases_path/$release_id"
    archive_path="$root/.incoming/opsdeck-$release_id.tar.gz"
    [[ -s "$archive_path" ]] || fail "The tested release archive was not uploaded to the VPS."
    [[ ! -e "$release_path" && ! -L "$release_path" ]] || fail "The target release directory already exists."

    staging_path="$(mktemp -d "$releases_path/.staging-$release_id.XXXXXX")" || fail "A temporary release directory could not be created."
    cleanup_remote_files() {
        if [[ -n "${staging_path:-}" && -d "$staging_path" ]]; then
            rm -rf -- "$staging_path"
        fi
        rm -f -- "$archive_path"
    }
    trap cleanup_remote_files EXIT

    if tar --list --gzip --file="$archive_path" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
        fail "The release archive contains an unsafe path."
    fi
    tar --extract --gzip --file="$archive_path" --directory "$staging_path"
    if find "$staging_path" -type l -print -quit | grep -q .; then
        fail "The extracted release contains a symbolic link."
    fi
    [[ -f "$staging_path/artisan" && -f "$staging_path/vendor/autoload.php" && -f "$staging_path/public/build/manifest.json" ]] || fail "The tested release artifact is incomplete."
    [[ -d "$staging_path/bootstrap/cache" && -w "$staging_path/bootstrap/cache" ]] || fail "The new release bootstrap cache directory is not writable."

    ln -s -- "$root/shared/.env" "$staging_path/.env"
    ln -s -- "$root/shared/storage" "$staging_path/storage"

    if ! "$php_bin" "$staging_path/artisan" config:cache --no-interaction >/dev/null 2>&1; then
        fail "The new release configuration cache could not be created."
    fi
    if ! "$php_bin" "$staging_path/artisan" event:cache --no-interaction >/dev/null 2>&1; then
        fail "The new release event cache could not be created."
    fi
    if ! "$php_bin" "$staging_path/artisan" view:cache --no-interaction >/dev/null 2>&1; then
        fail "The new release view cache could not be created."
    fi
    if [[ "$has_previous_release" != true ]]; then
        if ! "$php_bin" "$staging_path/artisan" opsdeck:backup --no-interaction >/dev/null 2>&1; then
            fail "The initial pre-migration production database backup failed; the active release was not switched."
        fi
    fi
    if ! "$php_bin" "$staging_path/artisan" migrate --force --no-interaction >/dev/null 2>&1; then
        fail "The production database migration failed; the active release was not switched."
    fi
    if [[ "$has_previous_release" != true ]]; then
        if ! "$php_bin" "$staging_path/artisan" opsdeck:scheduler:heartbeat --no-interaction >/dev/null 2>&1; then
            fail "The initial scheduler heartbeat could not be recorded; the active release was not switched."
        fi
        if ! "$php_bin" "$staging_path/artisan" opsdeck:backup --no-interaction >/dev/null 2>&1; then
            fail "The initial production database backup failed; the active release was not switched."
        fi
        if ! "$php_bin" "$staging_path/artisan" opsdeck:backup:restore-test --no-interaction >/dev/null 2>&1; then
            fail "The initial backup restore test failed; the active release was not switched."
        fi
    fi
    if [[ "$has_previous_release" == true ]]; then
        if ! readiness_output="$("$php_bin" "$staging_path/artisan" opsdeck:deployment-check --json --no-interaction 2>/dev/null)"; then
            if [[ -n "$readiness_output" ]]; then
                printf 'Production readiness result: %s\n' "$readiness_output" >&2
            fi
            fail "The new release failed the production readiness check; the active release was not switched."
        fi
        if [[ -z "$(printf '%s\n' "$readiness_output" | grep -m1 -E '^\{.*\}$' || true)" ]]; then
            fail "The new release production readiness check did not return valid JSON."
        fi
    fi

    chmod 755 "$staging_path"
    mv -- "$staging_path" "$release_path"
    staging_path=""
    if [[ "$has_previous_release" == true ]]; then
        atomic_symlink "$root" previous "$current_target" "$deployment_id"
    fi
    atomic_symlink "$root" current "$release_path" "$deployment_id"

    if ! "$php_bin" "$release_path/artisan" queue:restart --no-interaction >/dev/null 2>&1; then
        if [[ "$has_previous_release" == true ]]; then
            atomic_symlink "$root" current "$current_target" "$deployment_id"
            atomic_symlink "$root" previous "$release_path" "$deployment_id"
        else
            rm -f -- "$root/current"
        fi
        sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || true
        if [[ "$has_previous_release" == true ]]; then
            fail "The queue workers could not be signalled to restart; the previous release was restored."
        fi
        fail "The queue workers could not be signalled to restart; the initial release was removed from current."
    fi
    if ! sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1; then
        if [[ "$has_previous_release" == true ]]; then
            atomic_symlink "$root" current "$current_target" "$deployment_id"
            atomic_symlink "$root" previous "$release_path" "$deployment_id"
            "$php_bin" "$current_target/artisan" queue:restart --no-interaction >/dev/null 2>&1 || true
        else
            rm -f -- "$root/current"
        fi
        sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || true
        if [[ "$has_previous_release" == true ]]; then
            fail "The runtime reload failed; the previous release was restored."
        fi
        fail "The runtime reload failed; the initial release was removed from current."
    fi
    sleep "$QUEUE_STARTUP_SETTLE_SECONDS"
    if ! readiness_output="$("$php_bin" "$release_path/artisan" opsdeck:deployment-check --json --no-interaction 2>/dev/null)"; then
        if [[ -n "$readiness_output" ]]; then
            printf 'Production readiness result: %s\n' "$readiness_output" >&2
        fi
        rollback_after_readiness_failure "$root" "$php_bin" "$has_previous_release" "$current_target" "$release_path" "$deployment_id"
        if [[ "$has_previous_release" == true ]]; then
            fail "The activated release failed the production readiness check; the previous release was restored."
        fi
        fail "The initial release failed the production readiness check; it was removed from current."
    fi
    readiness_json="$(printf '%s\n' "$readiness_output" | grep -m1 -E '^[{].*[}]$' || true)"
    if [[ -z "$readiness_json" ]]; then
        rollback_after_readiness_failure "$root" "$php_bin" "$has_previous_release" "$current_target" "$release_path" "$deployment_id"
        if [[ "$has_previous_release" == true ]]; then
            fail "The activated release production readiness check returned invalid JSON; the previous release was restored."
        fi
        fail "The initial release production readiness check returned invalid JSON; it was removed from current."
    fi

    cleanup_remote_files
    trap - EXIT
}

remote_rollback() {
    local root="$1"
    local php_bin="$2"
    local sha="$3"
    local deployment_id="$4"
    local releases_path
    local current_target
    local previous_target

    validate_remote_root "$root"
    validate_release_id "$sha" "$deployment_id"
    [[ "$(id -u)" -ne 0 ]] || fail "The VPS deployment account must not be root."
    [[ "$php_bin" =~ ^/[A-Za-z0-9_./-]+$ && "$php_bin" != *"/../" && -x "$php_bin" ]] || fail "The configured PHP CLI executable is unavailable on the VPS."
    [[ -d "$root/releases" && -L "$root/current" ]] || fail "The current release link is unavailable."
    [[ ! -e "$root/previous" || -L "$root/previous" ]] || fail "The previous release path must be a symlink when present."
    if ! sudo -n "$RUNTIME_RELOAD_WRAPPER" --check >/dev/null 2>&1; then
        fail "The root-owned runtime reload wrapper is missing or its read-only preflight failed."
    fi
    releases_path="$(realpath -e -- "$root/releases")" || fail "The VPS release directory cannot be resolved."
    current_target="$(realpath -e -- "$root/current")" || fail "The current release symlink is invalid."
    case "$current_target" in
        "$releases_path"/*) ;;
        *) fail "The current release symlink points outside the configured releases directory." ;;
    esac
    [[ "$(basename -- "$current_target")" == "$sha-$deployment_id" ]] || fail "The current release changed after the deployment health check; automatic rollback was stopped."

    if [[ ! -L "$root/previous" ]]; then
        "$php_bin" "$current_target/artisan" queue:restart --no-interaction >/dev/null 2>&1 || true
        rm -- "$root/current"
        sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || fail "The initial release was removed, but the runtime reload wrapper failed."

        return
    fi

    previous_target="$(realpath -e -- "$root/previous")" || fail "The previous release symlink is invalid."
    case "$previous_target" in
        "$releases_path"/*) ;;
        *) fail "The previous release symlink points outside the configured releases directory." ;;
    esac
    atomic_symlink "$root" current "$previous_target" "$deployment_id"
    atomic_symlink "$root" previous "$current_target" "$deployment_id"
    if ! "$php_bin" "$previous_target/artisan" queue:restart --no-interaction >/dev/null 2>&1; then
        sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || true
        fail "The previous release was restored, but queue workers could not be signalled to restart."
    fi
    sudo -n "$RUNTIME_RELOAD_WRAPPER" >/dev/null 2>&1 || fail "The previous release was restored, but the runtime reload wrapper failed."
}

remote_prune() {
    local root="$1"
    local retention_count="$2"
    local releases_path
    local current_target
    local previous_target
    local additional_to_keep
    local additional_kept=0
    local candidate
    local candidates=()

    validate_remote_root "$root"
    [[ "$(id -u)" -ne 0 ]] || fail "The VPS deployment account must not be root."
    [[ "$retention_count" =~ ^[0-9]+$ ]] || fail "The release retention count must be numeric."
    (( retention_count >= 2 )) || fail "The release retention count must preserve both current and previous releases."
    [[ -L "$root/current" ]] || fail "The current release link is required for cleanup."
    [[ ! -e "$root/previous" || -L "$root/previous" ]] || fail "The previous release path must be a symlink when present."
    releases_path="$(realpath -e -- "$root/releases")" || fail "The VPS release directory cannot be resolved."
    current_target="$(realpath -e -- "$root/current")" || fail "The current release symlink is invalid."
    case "$current_target" in
        "$releases_path"/*) ;;
        *) fail "The current release symlink points outside the configured releases directory." ;;
    esac
    previous_target=""
    if [[ -L "$root/previous" ]]; then
        previous_target="$(realpath -e -- "$root/previous")" || fail "The previous release symlink is invalid."
        case "$previous_target" in
            "$releases_path"/*) ;;
            *) fail "The previous release symlink points outside the configured releases directory." ;;
        esac
    fi
    if [[ -n "$previous_target" ]]; then
        additional_to_keep=$((retention_count - 2))
    else
        additional_to_keep=$((retention_count - 1))
    fi
    mapfile -t candidates < <(find "$releases_path" -mindepth 1 -maxdepth 1 -type d ! -name '.staging-*' -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)

    for candidate in "${candidates[@]}"; do
        [[ "$candidate" == "$releases_path/"* && ! -L "$candidate" ]] || fail "Release cleanup encountered an unsafe path."
        if [[ "$candidate" == "$current_target" || ( -n "$previous_target" && "$candidate" == "$previous_target" ) ]]; then
            continue
        fi
        if (( additional_kept < additional_to_keep )); then
            additional_kept=$((additional_kept + 1))
            continue
        fi
        rm -rf -- "$candidate"
    done
}

local_deploy() {
    local host="${OPSDECK_DEPLOY_HOST:-}"
    local port_text="${OPSDECK_DEPLOY_PORT:-}"
    local deploy_user="${OPSDECK_DEPLOY_USER:-}"
    local root="${OPSDECK_DEPLOY_ROOT:-}"
    local php_bin="${OPSDECK_DEPLOY_PHP_BIN:-}"
    local known_hosts="${OPSDECK_DEPLOY_KNOWN_HOSTS:-}"
    local healthcheck_url="${OPSDECK_DEPLOY_HEALTHCHECK_URL:-}"
    local retention_count="${OPSDECK_DEPLOY_RELEASE_RETENTION_COUNT:-}"
    local private_key="${OPSDECK_DEPLOY_SSH_PRIVATE_KEY:-}"
    local sha="${OPSDECK_DEPLOY_RELEASE_SHA:-}"
    local ci_run_id="${OPSDECK_DEPLOY_CI_RUN_ID:-}"
    local ci_run_attempt="${OPSDECK_DEPLOY_CI_RUN_ATTEMPT:-}"
    local archive="${OPSDECK_DEPLOY_ARCHIVE:-}"
    local checksum="${OPSDECK_DEPLOY_CHECKSUM:-}"
    local deployment_id
    local known_host_identity
    local ssh_directory
    local key_file
    local known_hosts_file
    local remote
    local release_id
    local archive_basename
    local remote_command
    local readiness_json
    local healthcheck_attempt
    local healthy=false
    local -a ssh_options
    local -a scp_options

    [[ -n "$host" && "$host" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || fail "OPSDECK_DEPLOY_HOST is missing or invalid."
    [[ "$port_text" =~ ^[0-9]{1,5}$ ]] || fail "OPSDECK_DEPLOY_PORT is missing or invalid."
    local port=$((10#$port_text))
    (( port >= 1 && port <= 65535 )) || fail "OPSDECK_DEPLOY_PORT is outside the TCP port range."
    [[ "$deploy_user" =~ ^[a-z_][a-z0-9_-]*$ && "$deploy_user" != root ]] || fail "OPSDECK_DEPLOY_USER must be a non-root Linux account."
    validate_remote_root "$root"
    [[ "$php_bin" =~ ^/[A-Za-z0-9_./-]+$ && "$php_bin" != *"/../"* ]] || fail "OPSDECK_DEPLOY_PHP_BIN is missing or invalid."
    [[ "$healthcheck_url" =~ ^https://[A-Za-z0-9.-]+/up$ ]] || fail "OPSDECK_DEPLOY_HEALTHCHECK_URL must be an HTTPS Laravel /up URL."
    [[ "$retention_count" =~ ^[0-9]+$ ]] && (( retention_count >= 2 )) || fail "OPSDECK_DEPLOY_RELEASE_RETENTION_COUNT must be at least 2."
    [[ -n "$known_hosts" ]] || fail "OPSDECK_DEPLOY_KNOWN_HOSTS is missing."
    [[ -n "$private_key" ]] || fail "OPSDECK_DEPLOY_SSH_PRIVATE_KEY is missing."
    [[ -f "$archive" && -f "$checksum" ]] || fail "The tested release artifact or checksum is missing."
    validate_release_id "$sha" "$ci_run_id-$ci_run_attempt"

    (
        cd "$(dirname "$checksum")"
        sha256sum --check "$(basename "$checksum")"
    ) || fail "The production release artifact checksum is invalid."
    if tar --list --gzip --file="$archive" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
        fail "The production release artifact contains an unsafe path."
    fi

    ssh_directory="$(mktemp -d "$RUNNER_TEMP/opsdeck-ssh.XXXXXX")"
    OPSDECK_DEPLOY_CREDENTIAL_DIRECTORY="$ssh_directory"
    key_file="$ssh_directory/id_ed25519"
    known_hosts_file="$ssh_directory/known_hosts"
    cleanup_local_credentials() {
        rm -rf -- "$OPSDECK_DEPLOY_CREDENTIAL_DIRECTORY"
    }
    trap cleanup_local_credentials EXIT
    umask 077
    printf '%s\n' "$private_key" > "$key_file"
    printf '%s\n' "$known_hosts" > "$known_hosts_file"
    chmod 600 "$key_file" "$known_hosts_file"
    ssh-keygen -y -P "" -f "$key_file" >/dev/null 2>&1 || fail "The configured SSH private key could not be read without prompting."

    known_host_identity="$host"
    if (( port != 22 )); then
        known_host_identity="[$host]:$port"
    fi
    ssh-keygen -F "$known_host_identity" -f "$known_hosts_file" >/dev/null 2>&1 || fail "OPSDECK_DEPLOY_KNOWN_HOSTS does not contain the configured host and port."

    remote="$deploy_user@$host"
    ssh_options=(
        -F /dev/null
        -i "$key_file"
        -o BatchMode=yes
        -o IdentitiesOnly=yes
        -o StrictHostKeyChecking=yes
        -o "UserKnownHostsFile=$known_hosts_file"
        -o GlobalKnownHostsFile=/dev/null
        -o "ConnectTimeout=$SSH_CONNECT_TIMEOUT_SECONDS"
        -p "$port"
    )
    scp_options=(
        -F /dev/null
        -i "$key_file"
        -o BatchMode=yes
        -o IdentitiesOnly=yes
        -o StrictHostKeyChecking=yes
        -o "UserKnownHostsFile=$known_hosts_file"
        -o GlobalKnownHostsFile=/dev/null
        -o "ConnectTimeout=$SSH_CONNECT_TIMEOUT_SECONDS"
        -P "$port"
    )

    printf -v remote_command 'bash -s -- remote-preflight %q %q' "$root" "$php_bin"
    if ! readiness_json="$(ssh "${ssh_options[@]}" "$remote" "$remote_command" < "$0")"; then
        fail "VPS pre-deploy readiness checks failed; no release files were changed."
    fi
    if ! jq -e '
        (.checks | type == "object" and length > 0)
        and ([.checks | to_entries[] | select(.value != "pass" and .value != "not_applicable")] | length == 0)
    ' <<< "$readiness_json" >/dev/null; then
        failed_checks="$(jq -r '.checks | to_entries[] | select(.value != "pass" and .value != "not_applicable") | "\(.key)=\(.value)"' <<< "$readiness_json" 2>/dev/null || true)"
        printf 'VPS pre-deploy readiness checks did not pass.\n%s\n' "$failed_checks" >&2
        exit 1
    fi

    deployment_id="$ci_run_id-$ci_run_attempt"
    release_id="$sha-$deployment_id"
    archive_basename="opsdeck-$release_id.tar.gz"
    scp "${scp_options[@]}" "$archive" "$remote:$root/.incoming/$archive_basename"

    printf -v remote_command 'bash -s -- remote-deploy %q %q %q %q' "$root" "$sha" "$deployment_id" "$php_bin"
    ssh "${ssh_options[@]}" "$remote" "$remote_command" < "$0"

    for healthcheck_attempt in $(seq 1 "$HEALTH_CHECK_ATTEMPTS"); do
        if curl --fail --silent --show-error --max-time "$HEALTH_CHECK_TIMEOUT_SECONDS" --output /dev/null "$healthcheck_url"; then
            healthy=true
            break
        fi
        if (( healthcheck_attempt < HEALTH_CHECK_ATTEMPTS )); then
            sleep "$HEALTH_CHECK_DELAY_SECONDS"
        fi
    done

    if [[ "$healthy" != true ]]; then
        printf -v remote_command 'bash -s -- remote-rollback %q %q %q %q' "$root" "$php_bin" "$sha" "$deployment_id"
        if ! ssh "${ssh_options[@]}" "$remote" "$remote_command" < "$0"; then
            fail "The HTTPS health check failed and automatic rollback could not be completed."
        fi
        fail "The HTTPS health check failed; the previous release was restored."
    fi

    printf -v remote_command 'bash -s -- remote-prune %q %q' "$root" "$retention_count"
    if ! ssh "${ssh_options[@]}" "$remote" "$remote_command" < "$0"; then
        printf '::warning::Deployment succeeded, but old release cleanup failed.\n' >&2
    fi
    printf 'Deployed release %s and verified %s.\n' "$sha" "$healthcheck_url"
}

if [[ -z "${BASH_SOURCE[0]:-}" || "${BASH_SOURCE[0]}" == "$0" ]]; then
    case "${1:-local}" in
        local)
            local_deploy
            ;;
        remote-preflight)
            shift
            remote_preflight "$@"
            ;;
        remote-deploy)
            shift
            remote_deploy "$@"
            ;;
        remote-rollback)
            shift
            remote_rollback "$@"
            ;;
        remote-prune)
            shift
            remote_prune "$@"
            ;;
        *)
            fail "Unsupported deployment script mode."
            ;;
    esac
fi
