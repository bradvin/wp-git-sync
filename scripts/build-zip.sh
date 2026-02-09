#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PACKAGE_FILE="$ROOT_DIR/packages.json"

if [ ! -f "$PACKAGE_FILE" ]; then
  echo "Missing $PACKAGE_FILE. Cannot determine plugin version for ZIP name." >&2
  exit 1
fi

PLUGIN_SLUG="$(php -r '
$file = $argv[1];
$json = json_decode(file_get_contents($file), true);
if (!is_array($json) || empty($json["slug"]) || !is_string($json["slug"])) {
    fwrite(STDERR, "packages.json is missing a valid slug string\n");
    exit(1);
}
echo $json["slug"];
' "$PACKAGE_FILE")"

PLUGIN_VERSION="$(php -r '
$file = $argv[1];
$json = json_decode(file_get_contents($file), true);
if (!is_array($json) || empty($json["version"]) || !is_string($json["version"])) {
    fwrite(STDERR, "packages.json is missing a valid version string\n");
    exit(1);
}
echo $json["version"];
' "$PACKAGE_FILE")"

BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wpgs-build.XXXXXX")"
STAGING_DIR="$BUILD_ROOT/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"
ZIP_PATH="$DIST_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip"

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
  --exclude 'packages.json' \
  --exclude 'wp-cli.yml' \
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

# Exclude development/packaging-only files from final ZIP.
rm -f \
  "$STAGING_DIR/.gitignore" \
  "$STAGING_DIR/composer.json" \
  "$STAGING_DIR/composer.lock" \
  "$STAGING_DIR/phpstan.neon.dist" \
  "$STAGING_DIR/packages.json" \
  "$STAGING_DIR/wp-cli.yml"

(
  cd "$BUILD_ROOT"
  zip -r "$ZIP_PATH" "$PLUGIN_SLUG" >/dev/null
)

echo "Built ZIP: $ZIP_PATH"
