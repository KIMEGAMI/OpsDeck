#!/usr/bin/env bash
set -euo pipefail

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$script_directory/deploy-vps.sh"

test_root="$(mktemp -d)"
mock_bin="$test_root/mock-bin"
application_root="$test_root/application"
php_bin="$mock_bin/php"
php_log="$test_root/php-commands.log"

cleanup_test_files() {
    rm -rf -- "$test_root"
}
trap cleanup_test_files EXIT

mkdir -p -- "$mock_bin" "$application_root/releases" "$application_root/shared/storage" "$application_root/.incoming"
printf 'APP_ENV=production\n' > "$application_root/shared/.env"

cat > "$mock_bin/sudo" <<'MOCK_SUDO'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == '-n' ]]
shift
[[ "${1:-}" == '/usr/local/sbin/opsdeck-reload-runtime' ]]
MOCK_SUDO

cat > "$php_bin" <<'MOCK_PHP'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_PHP_LOG"
if [[ " $* " == *" opsdeck:deployment-check "* ]]; then
    [[ -L "$FAKE_APPLICATION_ROOT/current" ]] || {
        echo "Readiness ran before the current release was switched." >&2
        exit 1
    }
    printf '%s\n' '{"status":"ready","checks":{"test":"pass"}}'
fi
MOCK_PHP

chmod 755 "$mock_bin/sudo" "$php_bin"
PATH="$mock_bin:$PATH"
export PATH FAKE_PHP_LOG="$php_log" FAKE_APPLICATION_ROOT="$application_root"

validate_remote_root() {
    [[ "$1" == "$application_root" ]] || fail "Test used an unexpected application root."
}

assert_true() {
    [[ "$1" == true ]] || fail "$2"
}

make_release_archive() {
    local sha="$1"
    local deployment_id="$2"
    local source_directory
    local archive_path="$application_root/.incoming/opsdeck-$sha-$deployment_id.tar.gz"

    source_directory="$(mktemp -d "$test_root/artifact.XXXXXX")"
    mkdir -p -- "$source_directory/vendor" "$source_directory/public/build" "$source_directory/bootstrap/cache"
    touch "$source_directory/artisan" "$source_directory/vendor/autoload.php" "$source_directory/public/build/manifest.json"
    tar --create --gzip --file="$archive_path" --directory="$source_directory" .
    rm -rf -- "$source_directory"
}

first_sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
first_deployment_id='100-1'
first_release="$application_root/releases/$first_sha-$first_deployment_id"

preflight_output="$(remote_preflight "$application_root" "$php_bin")"
[[ "$preflight_output" == *'"status":"first_deploy"'* ]] || fail "Initial preflight did not identify the first deployment."
make_release_archive "$first_sha" "$first_deployment_id"
remote_deploy "$application_root" "$first_sha" "$first_deployment_id" "$php_bin"
assert_true "$( [[ -L "$application_root/current" ]] && printf true || printf false )" "Initial deployment did not create current."
assert_true "$( [[ "$(realpath -e -- "$application_root/current")" == "$first_release" ]] && printf true || printf false )" "Initial deployment selected the wrong release."
assert_true "$( [[ ! -e "$application_root/previous" && ! -L "$application_root/previous" ]] && printf true || printf false )" "Initial deployment unexpectedly created previous."
grep -q 'opsdeck:scheduler:heartbeat' "$php_log" || fail "Initial deployment did not record a scheduler heartbeat."
backup_lines="$(grep -n 'opsdeck:backup --no-interaction' "$php_log" | cut -d: -f1)"
[[ "$(printf '%s\n' "$backup_lines" | wc -l)" -eq 2 ]] || fail "Initial deployment did not create pre- and post-migration backups."
pre_migration_backup_line="$(printf '%s\n' "$backup_lines" | head -n 1)"
post_migration_backup_line="$(printf '%s\n' "$backup_lines" | tail -n 1)"
migration_line="$(grep -n 'artisan migrate --force' "$php_log" | head -n 1 | cut -d: -f1)"
restore_test_line="$(grep -n 'opsdeck:backup:restore-test' "$php_log" | head -n 1 | cut -d: -f1)"
if ! (( pre_migration_backup_line < migration_line && migration_line < post_migration_backup_line && post_migration_backup_line < restore_test_line )); then
    fail "Initial deployment did not back up before migrations and verify the post-migration restore in order."
fi
grep -q 'opsdeck:backup:restore-test' "$php_log" || fail "Initial deployment did not test the backup restore."

live_preflight_output="$(remote_preflight "$application_root" "$php_bin")"
[[ "$live_preflight_output" == *'"checks":{"test":"pass"}'* ]] || fail "Preflight did not run the existing release readiness check."
remote_rollback "$application_root" "$php_bin" "$first_sha" "$first_deployment_id"
assert_true "$( [[ ! -L "$application_root/current" ]] && printf true || printf false )" "First-deployment rollback did not remove current."

old_release="$application_root/releases/existing-release"
mkdir -p -- "$old_release/vendor"
touch "$old_release/artisan" "$old_release/vendor/autoload.php"
ln -s -- "$old_release" "$application_root/current"
second_sha='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
second_deployment_id='101-1'
second_release="$application_root/releases/$second_sha-$second_deployment_id"
make_release_archive "$second_sha" "$second_deployment_id"
remote_deploy "$application_root" "$second_sha" "$second_deployment_id" "$php_bin"

assert_true "$( [[ "$(realpath -e -- "$application_root/current")" == "$second_release" ]] && printf true || printf false )" "Upgrade did not select the new release."
assert_true "$( [[ "$(realpath -e -- "$application_root/previous")" == "$old_release" ]] && printf true || printf false )" "Upgrade did not preserve the previous release."
remote_rollback "$application_root" "$php_bin" "$second_sha" "$second_deployment_id"
assert_true "$( [[ "$(realpath -e -- "$application_root/current")" == "$old_release" ]] && printf true || printf false )" "Upgrade rollback did not restore the prior release."
remote_prune "$application_root" 2
assert_true "$( [[ -d "$old_release" && -d "$second_release" && ! -d "$first_release" ]] && printf true || printf false )" "Release cleanup did not preserve the current and previous releases."

if bash -s -- unsupported < "$script_directory/deploy-vps.sh" > "$test_root/stdin-mode.log" 2>&1; then
    fail "The deployment entry point did not run when its script was provided on stdin."
fi
grep -q 'Unsupported deployment script mode.' "$test_root/stdin-mode.log" || fail "The stdin deployment entry point exited before dispatching its command."

printf '%s\n' 'PASS: first deploy readiness after activation, preflight, and rollback flows'
