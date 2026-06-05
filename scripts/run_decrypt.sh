#!/bin/bash
# PHP(apache) → Python 복호화 래퍼 (PATH 고정)
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export LANG="${LANG:-C.UTF-8}"
DIR="$(cd "$(dirname "$0")" && pwd)"
PY="${SETTLEMENT_PYTHON_BIN:-/usr/bin/python3}"
exec "$PY" "$DIR/decrypt_xlsx.py" "$@"
