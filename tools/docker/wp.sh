#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

# Wrapper to run WP-CLI inside the docker compose stack.
docker compose run --rm wpcli "$@"

