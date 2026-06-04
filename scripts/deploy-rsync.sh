#!/usr/bin/env bash
# 로컬에서 실서버로 수동 rsync (GitHub Actions와 동일 exclude)
#
# 사용:
#   ./scripts/deploy-rsync.sh ec2-user@1.2.3.4:/home/ec2-user/baedal
#   RSYNC_RSH='ssh -i ~/Lightsail.pem' ./scripts/deploy-rsync.sh ec2-user@1.2.3.4:/home/ec2-user/baedal
set -euo pipefail

DEST="${1:-}"
if [[ -z "$DEST" ]]; then
  echo "Usage: $0 user@host:/absolute/path/to/project" >&2
  echo "  RSYNC_RSH='ssh -i key.pem' $0 ec2-user@IP:/home/ec2-user/baedal" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

RSYNC_OPTS=(-avz --delete)
if [[ -n "${RSYNC_RSH:-}" ]]; then
  RSYNC_OPTS+=(-e "$RSYNC_RSH")
fi

rsync "${RSYNC_OPTS[@]}" \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude '!.env.example' \
  --exclude 'uploads/' \
  --exclude 'storage/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  --exclude 'inc/*.flag' \
  --exclude '.DS_Store' \
  --exclude 'Thumbs.db' \
  --exclude '*.log' \
  --exclude 'check_content_db.php' \
  --exclude 'migrate_content.php' \
  --exclude 'migrate_settlement.php' \
  --exclude 'seed.php' \
  --exclude 'seed_content.php' \
  --exclude 'seed.sql' \
  --exclude 'seed_riders_from_settlement.php' \
  ./ "${DEST%/}/"

echo "Done: $DEST"
