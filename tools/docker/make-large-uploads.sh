#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

# Generate large, mostly-incompressible-ish files in uploads to reach ~1-2GB total.
# Note: /dev/urandom is slower but less compressible. We mix both to keep runtime reasonable.

TARGET_DIR="/var/www/html/wp-content/uploads/restoreone-large"

echo "[docker] creating large files under uploads..."
docker compose exec -T wordpress bash -lc "
  set -e
  mkdir -p \"$TARGET_DIR\"
  cd \"$TARGET_DIR\"
  # 10 x 50MB random (~500MB)
  for i in \$(seq 1 10); do
    test -f \"rand-\$i.bin\" || dd if=/dev/urandom of=\"rand-\$i.bin\" bs=1M count=50 status=none
  done
  # 20 x 50MB zero (~1GB)
  for i in \$(seq 1 20); do
    test -f \"zero-\$i.bin\" || dd if=/dev/zero of=\"zero-\$i.bin\" bs=1M count=50 status=none
  done
  chown -R www-data:www-data \"$TARGET_DIR\"
  du -sh .
"

echo "[done] large uploads generated."

