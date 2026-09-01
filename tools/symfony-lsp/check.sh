#!/usr/bin/env bash
#
# Run symfony lsp:check for this package (see .symfony-lsp.json).
#
# Usage: tools/symfony-lsp/check.sh [github]
#
set -euo pipefail

PACKAGE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
CONFIG="${PACKAGE_DIR}/tools/symfony-lsp/.symfony-lsp.json"
FORMAT="${1:-human}"

cd "${PACKAGE_DIR}"

if [ -f bin/console ]; then
    php bin/console cache:warmup --env=dev --no-debug >/dev/null 2>&1 || true
fi

ARGS=(lsp:check "--config=${CONFIG}")
if [ "${FORMAT}" = "github" ]; then
    ARGS+=(--format=github)
fi

set +e
symfony "${ARGS[@]}"
LSP_EXIT=$?
if [ "${LSP_EXIT}" -eq 12 ]; then
    symfony "${ARGS[@]}"
    LSP_EXIT=$?
fi
set -e

exit "${LSP_EXIT}"
