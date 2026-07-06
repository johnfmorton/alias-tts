#!/usr/bin/env bash
#
# Entrypoint for the single-image Docker package. Runs as root: assembles the
# persistent /data volume, generates missing secrets, migrates, warms caches,
# then hands off to supervisord, which runs every process as the "app" user.
#
# Configuration model: /data/.env (seeded from .env.container on first boot)
# holds the persistent config; a real environment variable passed to the
# container ALWAYS wins over the file — both for PHP (Laravel prefers real env)
# and for the Python sidecars (this script exports file values no-clobber).
set -euo pipefail

APP_DIR=/var/www/html
DATA_DIR=/data
ENV_FILE="$DATA_DIR/.env"

log() { echo "[entrypoint] $*"; }

# ── /data layout ────────────────────────────────────────────────────────────
mkdir -p "$DATA_DIR" "$DATA_DIR/asr-models" "$DATA_DIR/genblaze" \
    "$DATA_DIR/caddy/data" "$DATA_DIR/caddy/config"

if [ ! -f "$ENV_FILE" ]; then
    log "seeding $ENV_FILE from the image template"
    cp "$APP_DIR/.env.container" "$ENV_FILE"
fi
ln -sfn "$ENV_FILE" "$APP_DIR/.env"

# Storage skeleton → /data/storage (the app's storage/ is a symlink to it).
if [ ! -d "$DATA_DIR/storage/app" ]; then
    log "seeding $DATA_DIR/storage"
    mkdir -p "$DATA_DIR/storage"
    cp -a "$APP_DIR/storage.skel/." "$DATA_DIR/storage/"
fi

[ -f "$DATA_DIR/database.sqlite" ] || touch "$DATA_DIR/database.sqlite"

# ── Secrets (generate once, persist in /data/.env) ──────────────────────────
# Skipped when the value arrives as a container env var — the real env wins
# and nothing is written, so removing the -e later falls back cleanly.
if [ -z "${APP_KEY:-}" ] && ! grep -Eq '^APP_KEY=.+$' "$ENV_FILE"; then
    log "generating APP_KEY"
    php "$APP_DIR/artisan" key:generate --force --no-interaction
fi

if [ -z "${TTS_INTERNAL_SECRET:-}" ] && ! grep -Eq '^TTS_INTERNAL_SECRET=.+$' "$ENV_FILE"; then
    log "generating TTS_INTERNAL_SECRET (app <-> Genblaze runner)"
    secret=$(openssl rand -hex 32)
    if grep -q '^TTS_INTERNAL_SECRET=' "$ENV_FILE"; then
        sed -i "s/^TTS_INTERNAL_SECRET=$/TTS_INTERNAL_SECRET=${secret}/" "$ENV_FILE"
    else
        printf '\nTTS_INTERNAL_SECRET=%s\n' "$secret" >>"$ENV_FILE"
    fi
fi

# ── Merge /data/.env into the process environment (no-clobber) ──────────────
# Supervisord's children (uvicorn sidecars included) inherit this, so the
# Python side sees the same REPLICATE_API_TOKEN / AWS_* / secret as the app.
while IFS= read -r line; do
    case "$line" in '' | '#'*) continue ;; esac
    key=${line%%=*}
    val=${line#*=}
    [[ $key =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    case "$val" in
    \"*\") val=${val#\"} val=${val%\"} ;;
    \'*\') val=${val#\'} val=${val%\'} ;;
    esac
    if [ -z "${!key+x}" ]; then
        export "$key=$val"
    fi
done <"$ENV_FILE"

# ── Ownership ────────────────────────────────────────────────────────────────
if [ ! -f "$DATA_DIR/.initialized" ]; then
    chown -R app:app "$DATA_DIR"
    touch "$DATA_DIR/.initialized"
fi
chown app:app "$DATA_DIR" "$ENV_FILE" "$DATA_DIR/database.sqlite"

# Repair strays: a `docker exec` artisan run as root can plant root-owned
# files/dirs (0700) in storage that the app user then can't write past.
# Prefer `docker exec -u app` — this keeps an accidental root run from
# breaking generation after the next restart.
find "$DATA_DIR/storage" ! -user app -exec chown app:app {} + 2>/dev/null || true

# ── Database ─────────────────────────────────────────────────────────────────
# Retry to ride out an external MySQL that is still starting up.
migrated=0
for attempt in $(seq 1 10); do
    if runuser -u app -- php "$APP_DIR/artisan" migrate --force --no-interaction; then
        migrated=1
        break
    fi
    log "migrate failed (attempt $attempt/10) — retrying in 3s"
    sleep 3
done
if [ "$migrated" != 1 ]; then
    log "FATAL: database migration failed — check DB_* settings"
    exit 1
fi

# ── First-boot admin ─────────────────────────────────────────────────────────
# Only ever runs once so a password later changed in the UI is never reset by
# a container restart. Re-run by hand any time:
#   docker exec -u app <container> php artisan admin:create
if [ ! -f "$DATA_DIR/.admin-created" ]; then
    if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
        log "creating dashboard admin ${ADMIN_EMAIL}"
        if runuser -u app -- php "$APP_DIR/artisan" admin:create --no-interaction; then
            touch "$DATA_DIR/.admin-created"
        else
            log "WARNING: admin:create failed — create the login later with: docker exec -u app <container> php artisan admin:create"
        fi
    else
        log "ADMIN_EMAIL / ADMIN_PASSWORD not set — create the dashboard login with: docker exec -u app <container> php artisan admin:create"
    fi
fi

# ── Caches ───────────────────────────────────────────────────────────────────
# Bake config/routes/views from the now-final environment. bootstrap/cache is
# in-image (not on the volume), so every container start re-bakes fresh.
runuser -u app -- php "$APP_DIR/artisan" optimize --no-interaction

# ── Whisper model ────────────────────────────────────────────────────────────
# The image ships the default model; seed it into the persistent model dir so
# first boot needs no network. A different ASR_MODEL downloads there once.
cp -an /opt/asr-models/. "$DATA_DIR/asr-models/" 2>/dev/null || true
chown -R app:app "$DATA_DIR/asr-models"

# ── Sidecar environment defaults ─────────────────────────────────────────────
export ASR_MODEL="${ASR_MODEL:-tiny}"
export ASR_DEVICE="${ASR_DEVICE:-cpu}"
export ASR_COMPUTE_TYPE="${ASR_COMPUTE_TYPE:-int8}"
export ASR_LANGUAGE="${ASR_LANGUAGE:-en}"
export ASR_DOWNLOAD_ROOT="${ASR_DOWNLOAD_ROOT:-$DATA_DIR/asr-models}"

# The runner calls the app back on the loopback listener (docker/Caddyfile),
# immune to whatever TLS/domain setup the public listener has.
export ALIAS_BASE_URL="${ALIAS_BASE_URL:-http://127.0.0.1:8081}"
export ALIAS_INTERNAL_SECRET="${ALIAS_INTERNAL_SECRET:-${TTS_INTERNAL_SECRET:-}}"
export GENBLAZE_MAX_CONCURRENCY="${GENBLAZE_MAX_CONCURRENCY:-1}"
export GENBLAZE_OUTPUT_DIR="${GENBLAZE_OUTPUT_DIR:-$DATA_DIR/genblaze}"
export PYTHONPATH="$APP_DIR/genblaze-runner:$APP_DIR/connectors/genblaze-alias"
export PYTHONUNBUFFERED=1
export PYTHONDONTWRITEBYTECODE=1

# Caddy state (auto-HTTPS certificates) persists on the volume.
export XDG_DATA_HOME="$DATA_DIR/caddy/data"
export XDG_CONFIG_HOME="$DATA_DIR/caddy/config"
chown -R app:app "$DATA_DIR/caddy"

log "starting supervisord (web + queue + scheduler + ASR + Genblaze runner)"
exec supervisord -c /etc/supervisor/supervisord.conf
