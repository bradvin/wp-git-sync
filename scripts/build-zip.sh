#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="$(basename "$ROOT_DIR")"

BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wpgs-build.XXXXXX")"
STAGING_DIR="$BUILD_ROOT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
ZIP_PATH="$DIST_DIR/${PLUGIN_SLUG}.zip"

cleanup() {
  rm -rf "$BUILD_ROOT"
}
trap cleanup EXIT

mkdir -p "$STAGING_DIR" "$DIST_DIR"
rm -f "$ZIP_PATH"

rsync -a "$ROOT_DIR"/ "$STAGING_DIR"/ \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude 'dist/' \
  --exclude 'reports/' \
  --exclude '.phpstan-cache/' \
  --exclude '.DS_Store' \
  --exclude '.idea/' \
  --exclude '.vscode/' \
  --exclude 'node_modules/' \
  --exclude 'tests/'

if [ -f "$STAGING_DIR/composer.json" ]; then
  rm -rf "$STAGING_DIR/vendor"
  (
    cd "$STAGING_DIR"
    composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --optimize-autoloader
  )
fi

(
  cd "$BUILD_ROOT"
  zip -r "$ZIP_PATH" "$PLUGIN_SLUG" >/dev/null
)

echo "Built ZIP: $ZIP_PATH"
