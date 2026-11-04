#!/usr/bin/env bash
# Builds the installable plugin zip: dist/okno-<version>.zip, with an okno/
# folder at its root (a zip without it would install next to an existing copy
# instead of replacing it).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n "s/^define( 'OKNO_VERSION', '\(.*\)' );/\1/p" plugin/okno.php)"
HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' plugin/okno.php)"
STABLE_TAG="$(sed -n 's/^Stable tag: *//p' plugin/readme.txt)"
BRIDGE_VERSION="$(node -p "require('./bridge/package.json').version" 2>/dev/null || echo "$VERSION")"

for v in "$HEADER_VERSION" "$STABLE_TAG" "$BRIDGE_VERSION"; do
	if [ "$v" != "$VERSION" ]; then
		echo "Version mismatch: OKNO_VERSION=$VERSION, header=$HEADER_VERSION, readme.txt=$STABLE_TAG, bridge=$BRIDGE_VERSION" >&2
		exit 1
	fi
done

if ! cmp -s bridge/dist/okno-bridge.js plugin/assets/bridge/okno-bridge.js; then
	echo "plugin/assets/bridge/okno-bridge.js is stale: run 'npm run build' in bridge/." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/okno"
rsync -a \
	--exclude tests \
	--exclude .DS_Store \
	plugin/ "$STAGE/okno/"
cp LICENSE "$STAGE/okno/LICENSE"
# Licences des parties tierces (licenses/, copié avec plugin/) : obligatoire.
if [ ! -f "$STAGE/okno/licenses/stacki-MIT.txt" ]; then
	echo "licenses/stacki-MIT.txt is missing from the package." >&2
	exit 1
fi

mkdir -p dist
ZIP="$ROOT/dist/okno-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr "$ZIP" okno)

echo "$ZIP"
