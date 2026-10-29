#!/usr/bin/env bash
#
# Build the extension (through tools/test.sh, so it is built the same way as for
# the tests) and run the overhead benchmark with it.
#
#   tools/bench/run.sh [repetitions]
#   make bench PHP=8.5
#
# Run it in the dev image for numbers that can be compared between runs.

set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
build="${BUILD_DIR:-/tmp/xhprof-build}"

BUILD_DIR="$build" "$root/tools/test.sh" tests/xhprof_001.phpt > /dev/null

# -n: no ini files, so nothing else (opcache, other profilers) changes the numbers.
php -n -d "extension=$build/modules/xhprof.so" "$root/tools/bench/bench.php" "$@"
