#!/usr/bin/env bash
set -euo pipefail
src=/tmp/museder-restoreone-src
dst=/var/www/html/wp-content/plugins/museder-restoreone
rm -rf "$dst"
mkdir -p "$dst"
tar -C "$src" \
  --exclude=./logs \
  --exclude=./docs \
  --exclude=./dist \
  --exclude=./release \
  --exclude=./.git \
  --exclude=./.cursor \
  --exclude=./museder-restoreone-pro \
  --exclude=./archive \
  -cf - . | tar -C "$dst" -xf -
echo "Synced plugin to $dst"
