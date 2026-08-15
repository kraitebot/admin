#!/usr/bin/env bash

set -euo pipefail

if [[ "${CI:-}" =~ ^(1|true)$ ]]; then
    exec php ./vendor/bin/pest --no-tia "$@"
fi

tia_index_file=''

cleanup_tia_index() {
    if [[ -n "${tia_index_file}" && -f "${tia_index_file}" ]]; then
        unlink "${tia_index_file}"
        tia_index_file=''
    fi
}

prepare_tia_index() {
    local skill_root tracked_paths

    for skill_root in .agents/skills/laravel-best-practices .claude/skills/laravel-best-practices; do
        [[ -L "${skill_root}" ]] || continue
        tracked_paths="$(git ls-files -- "${skill_root}/")"
        [[ -n "${tracked_paths}" ]] || continue

        if [[ -z "${tia_index_file}" ]]; then
            tia_index_file="$(mktemp -t kraite-tia-index)"
            cp "$(git rev-parse --git-path index)" "${tia_index_file}"
        fi

        printf '%s\n' "${tracked_paths}" |
            GIT_INDEX_FILE="${tia_index_file}" git update-index --skip-worktree --stdin
    done

    if [[ -n "${tia_index_file}" ]]; then
        export GIT_INDEX_FILE="${tia_index_file}"
        trap cleanup_tia_index EXIT INT TERM
    fi
}

run_pest() {
    local test_result

    if "$@"; then
        test_result=0
    else
        test_result=$?
    fi

    cleanup_tia_index
    exit "${test_result}"
}

prepare_tia_index

if command -v herd >/dev/null 2>&1 && herd list --raw 2>/dev/null | grep -q '^coverage '; then
    php_version="$(php -r 'echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;')"
    herd_ini="${HOME}/Library/Application Support/Herd/config/php/${php_version}/php.ini"

    if [[ -f "${herd_ini}" ]]; then
        PHP_INI_SCAN_DIR="$(dirname "${herd_ini}")"
        export PHP_INI_SCAN_DIR
        run_pest herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
    fi

    run_pest herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

if php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'; then
    export XDEBUG_MODE=coverage
    run_pest php -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

echo 'Pest TIA requires PCOV or Xdebug coverage. Install a coverage driver or run through Laravel Herd.' >&2
exit 1
