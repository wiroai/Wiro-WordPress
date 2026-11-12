#!/usr/bin/env bash
# Builds build/wiro-ai/ and build/wiro-ai.zip, the package for wordpress.org.
# Files listed in .distignore stay out of the package. The folder is synced in
# place, so a WordPress container that mounts build/wiro-ai keeps working.
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p build/wiro-ai
rsync -a --delete --exclude-from=.distignore ./ build/wiro-ai/
rm -f build/wiro-ai.zip
(cd build && zip -qrX wiro-ai.zip wiro-ai)
echo "Built build/wiro-ai.zip"
