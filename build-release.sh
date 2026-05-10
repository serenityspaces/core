#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
DIRNAME="$(basename "$SCRIPT_DIR")"
VERSION="1.0.0"
OUTPUT="${PARENT_DIR}/serenityspaces-${VERSION}.zip"

echo "Building SerenitySpaces ${VERSION} release zip..."
echo "Source : $SCRIPT_DIR"
echo "Output : $OUTPUT"
echo ""

[ -f "$OUTPUT" ] && rm "$OUTPUT" && echo "Removed previous build."

cd "$PARENT_DIR"

zip -r "$OUTPUT" "$DIRNAME" \
    -x "*.DS_Store"                        \
    -x "*/__MACOSX/*"                      \
    -x "*/._*"                             \
    -x "${DIRNAME}/reports/*"              \
    -x "${DIRNAME}/reports"                \
    -x "${DIRNAME}/lib/*"                  \
    -x "${DIRNAME}/.claude/*"              \
    -x "${DIRNAME}/.csp-refactor*"         \
    -x "*.log"                             \
    -x "*.env"                             \
    -x "*.env.local"

echo ""
echo "Done."
echo "File : $OUTPUT"
echo "Size : $(du -sh "$OUTPUT" | cut -f1)"
