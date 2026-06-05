#!/usr/bin/env bash
#
# Build an installable Shopware plugin package (zip) from this repo.
#
# Produces  OrderIntegration-<version>.zip  with a single top-level folder
# `OrderIntegration/` (the plugin technical name), as Shopware's "Upload
# extension" and custom/plugins/ expect. Uses `git archive`, so only committed
# files are included and dev-only paths are excluded via .gitattributes
# (export-ignore). No vendor dir is bundled — the plugin has no runtime composer
# deps (shopware/core is provided by the host).
#
#   bin/build-plugin-zip.sh [git-ref] [output-dir]
#     git-ref     commit/tag/branch to package (default: HEAD)
#     output-dir  where to write the zip (default: current dir)
#
set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN="OrderIntegration"
REF="${1:-HEAD}"
OUT_DIR="${2:-$PWD}"

VERSION="$(grep -m1 '"version"' composer.json | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/' || true)"
[ -n "$VERSION" ] || VERSION="0.0.0"

command -v zip >/dev/null || { echo "error: 'zip' is required"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Lay the committed tree out under OrderIntegration/ (export-ignore applied).
git archive --format=tar --prefix="${PLUGIN}/" "$REF" | tar -x -C "$TMP"

ZIP="${OUT_DIR%/}/${PLUGIN}-${VERSION}.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rq "$ZIP" "$PLUGIN" )

echo "built: $ZIP"
echo "contents:"; ( cd "$TMP" && find "$PLUGIN" -maxdepth 2 -type f | sort | sed 's/^/  /' | head -30 )
