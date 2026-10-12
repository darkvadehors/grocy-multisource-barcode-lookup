#!/usr/bin/env bash
# Télécharge Grocy (SHA-256 vérifié) dans .cache/grocy-<version> pour les tests.
set -euo pipefail
VERSION="${GROCY_VERSION:-4.7.1}"
SHA256="${GROCY_SHA256:-c4a10e96ed76664baf465e9b27dc7a31aa08c0e3ad876afdfae4f1ff9ab2fc19}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/.cache/grocy-$VERSION"
[ -f "$DEST/packages/autoload.php" ] && { echo "$DEST"; exit 0; }
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
curl -fsSL -o "$work/grocy.zip" "https://github.com/grocy/grocy/releases/download/v${VERSION}/grocy_${VERSION}.zip"
echo "$SHA256  $work/grocy.zip" | shasum -a 256 -c --quiet - || { echo "SHA-256 invalide" >&2; exit 1; }
mkdir -p "$ROOT/.cache" && unzip -q "$work/grocy.zip" -d "$work/x" && mv "$work/x" "$DEST"
echo "$DEST"
