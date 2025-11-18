#!/usr/bin/env bash
set -euo pipefail

# --testdox keeps output flowing so Travis doesn't think the build is stuck.
ddev phpunit --testdox
