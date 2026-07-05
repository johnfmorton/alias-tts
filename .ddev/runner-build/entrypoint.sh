#!/bin/sh
set -e
# The runner expects ALIAS_* names; derive them from the Laravel .env that the
# compose env_file loads (TTS_INTERNAL_SECRET), and default the base URL to the
# DDEV web service. Only the /run path needs these — /pronounce just needs
# REPLICATE_API_TOKEN (also loaded from .env).
export ALIAS_INTERNAL_SECRET="${ALIAS_INTERNAL_SECRET:-$TTS_INTERNAL_SECRET}"
export ALIAS_BASE_URL="${ALIAS_BASE_URL:-http://web}"
exec "$@"
