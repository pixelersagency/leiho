#!/usr/bin/env bash
# Builds the installable plugin zip: dist/leiho-<version>.zip, with a leiho/
# folder at its root (a zip without it would install next to an existing copy
# instead of replacing it).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n "s/^define( 'LEIHO_VERSION', '\(.*\)' );/\1/p" plugin/leiho.php)"
HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' plugin/leiho.php)"
STABLE_TAG="$(sed -n 's/^Stable tag: *//p' plugin/readme.txt)"
BRIDGE_VERSION="$(node -p "require('./bridge/package.json').version" 2>/dev/null || echo "$VERSION")"

for v in "$HEADER_VERSION" "$STABLE_TAG" "$BRIDGE_VERSION"; do
	if [ "$v" != "$VERSION" ]; then
		echo "Version mismatch: LEIHO_VERSION=$VERSION, header=$HEADER_VERSION, readme.txt=$STABLE_TAG, bridge=$BRIDGE_VERSION" >&2
		exit 1
	fi
done

if ! cmp -s bridge/dist/leiho-bridge.js plugin/assets/bridge/leiho-bridge.js; then
	echo "plugin/assets/bridge/leiho-bridge.js is stale: run 'npm run build' in bridge/." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/leiho"
rsync -a \
	--exclude tests \
	--exclude .DS_Store \
	plugin/ "$STAGE/leiho/"
cp LICENSE "$STAGE/leiho/LICENSE"
# Licences des parties tierces (licenses/, copié avec plugin/) : obligatoire.
if [ ! -f "$STAGE/leiho/licenses/stacki-MIT.txt" ]; then
	echo "licenses/stacki-MIT.txt is missing from the package." >&2
	exit 1
fi

mkdir -p dist
ZIP="$ROOT/dist/leiho-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr "$ZIP" leiho)

echo "$ZIP"
