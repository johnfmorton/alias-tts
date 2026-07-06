# syntax=docker/dockerfile:1
#
# Single-image package for Alias TTS: one container runs the whole service —
# web app (FrankenPHP), database-queue worker, scheduler, the Whisper ASR
# sidecar, and the Genblaze runner — supervised by supervisord and persisting
# all state (SQLite DB, audio, voice clips, secrets, models) on a /data volume.
#
#   docker build -t alias-tts .
#   docker run -d -p 8080:80 -v alias-data:/data \
#     -e REPLICATE_API_TOKEN=r8_xxx \
#     -e ADMIN_EMAIL=you@example.com -e ADMIN_PASSWORD=change-me \
#     alias-tts
#
# See docs/DOCKER.md for the full walkthrough.

# ─── Shared PHP base (used by the vendor stage and the final runtime) ─────────
FROM dunglas/frankenphp:1-php8.3 AS base

# pdo_sqlite/sqlite3 ship with the official PHP images; the rest mirrors CI
# (.github/workflows/ci.yml) plus pdo_mysql for installs that point DB_* at
# MySQL/MariaDB instead of the bundled SQLite.
RUN install-php-extensions bcmath intl pcntl pdo_mysql zip opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ─── Dashboard assets (Vite + Tailwind) ───────────────────────────────────────
# glibc image (not alpine): the rolldown/vite native binding is happiest there.
# Pinned to the BUILD platform: the output (public/build) is arch-independent,
# so a multi-arch CI build runs this once natively instead of under QEMU.
FROM --platform=$BUILDPLATFORM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ─── PHP dependencies ─────────────────────────────────────────────────────────
# Also build-platform-pinned: vendor/ and bootstrap/cache are plain PHP files,
# identical for every target arch.
FROM --platform=$BUILDPLATFORM base AS vendor
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# The full source is needed for the optimized autoloader + package manifest;
# artisan wants the storage skeleton to exist when it boots.
COPY . .
RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php artisan package:discover --ansi

# ─── Runtime ──────────────────────────────────────────────────────────────────
FROM base AS runtime
ARG TARGETARCH

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv \
        libgomp1 \
        supervisor tini \
        curl xz-utils openssl libcap2-bin util-linux \
    && rm -rf /var/lib/apt/lists/*

# ffmpeg + ffprobe: static BtbN build, pinned by version AND checksum. The
# health check (tts:doctor) requires >= 8.1.2 — the first release with the
# MagicYUV "PixelSmash" fix (CVE-2026-8461) — and distro packages are older.
RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) arch="linux64"; sha="df99ffb3803ee56dc68954f43f950ea9f33685a3595a5da8a3e73ef4bef37e3c" ;; \
        arm64) arch="linuxarm64"; sha="069d5c818de116003d717d194b5e97ee24c550a656d31c6a973952bb6df5e5ea" ;; \
        *) echo "unsupported TARGETARCH=${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/ffmpeg.tar.xz \
        "https://github.com/BtbN/FFmpeg-Builds/releases/download/autobuild-2026-07-06-14-19/ffmpeg-n8.1.2-22-g94138f6973-${arch}-gpl-8.1.tar.xz"; \
    echo "${sha}  /tmp/ffmpeg.tar.xz" | sha256sum -c -; \
    mkdir /tmp/ffmpeg; \
    tar -xJf /tmp/ffmpeg.tar.xz -C /tmp/ffmpeg --strip-components=1; \
    mv /tmp/ffmpeg/bin/ffmpeg /tmp/ffmpeg/bin/ffprobe /usr/local/bin/; \
    rm -rf /tmp/ffmpeg /tmp/ffmpeg.tar.xz; \
    ffmpeg -version | head -1

# Whisper ASR sidecar venv (faster-whisper on CPU).
COPY asr-sidecar/requirements.txt /tmp/asr-requirements.txt
RUN python3 -m venv /opt/asr \
    && /opt/asr/bin/pip install --no-cache-dir -r /tmp/asr-requirements.txt \
    && rm /tmp/asr-requirements.txt

# Genblaze runner venv. Published genblaze packages from PyPI (with [audio] so
# the provenance manifest embeds into finals — see genblaze-runner/pyproject.toml);
# the local packages (genblaze_runner, genblaze_alias) run from the app tree via
# PYTHONPATH, exactly like the Forge daemon layout in docs/GENBLAZE-SETUP.md.
RUN python3 -m venv /opt/runner \
    && /opt/runner/bin/pip install --no-cache-dir \
        "genblaze-core[audio]>=0.3,<0.4" \
        "genblaze-s3>=0.3,<0.4" \
        "genblaze-google>=0.3,<0.4" \
        "genblaze-openai>=0.3,<0.4" \
        "httpx>=0.27" \
        "fastapi>=0.110" \
        "uvicorn[standard]>=0.29" \
        "pydantic>=2"

# Bake the default Whisper model so first boot needs no network; the entrypoint
# seeds it into /data/asr-models (a bigger ASR_MODEL downloads there at runtime).
ARG ASR_BAKE_MODEL=tiny
RUN /opt/asr/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('${ASR_BAKE_MODEL}', device='cpu', compute_type='int8', download_root='/opt/asr-models')"

RUN useradd --uid 1000 --user-group --create-home --shell /usr/sbin/nologin app \
    # Non-root bind to 80/443 (Docker usually allows this anyway; belt & braces).
    && setcap cap_net_bind_service=+ep "$(readlink -f "$(which frankenphp)")"

WORKDIR /var/www/html

# Application code (root-owned, read-only to the app user) + built artifacts.
# Local checkouts can carry owner-only modes (e.g. a 0600 file), which COPY
# preserves — normalize so the non-root "app" user can read the tree.
COPY . .
RUN chmod -R a+rX . && chown root:root . && chmod 0755 .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=vendor /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --from=assets /app/public/build ./public/build

# Container plumbing. storage/ becomes a symlink onto the /data volume; the
# pristine skeleton ships as storage.skel for the entrypoint to seed from.
RUN cp docker/.env.container .env.container \
    && cp docker/Caddyfile /etc/frankenphp/Caddyfile \
    && cp docker/php.ini "$PHP_INI_DIR/conf.d/zz-alias.ini" \
    && cp docker/supervisord.conf /etc/supervisor/supervisord.conf \
    && install -m 0755 docker/entrypoint.sh /usr/local/bin/alias-entrypoint \
    && mkdir -p storage/app/private/speech storage/app/private/voices \
        storage/app/private/voice-clips storage/app/private/avatars storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs \
    && mv storage storage.skel \
    && ln -s /data/storage storage \
    && chown -R app:app storage.skel bootstrap/cache

ENV SERVER_NAME=":80"

EXPOSE 80 443 443/udp

# The loopback listener answers regardless of public TLS/domain config.
# start-period covers first-boot migration + cache warm + model seeding.
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8081/up || exit 1

LABEL org.opencontainers.image.title="Alias TTS" \
      org.opencontainers.image.description="Self-hosted TTS server speaking the ElevenLabs and OpenAI API dialects, with voice cloning, transcript QA, and provenance — complete in one image." \
      org.opencontainers.image.source="https://github.com/johnfmorton/alias-tts" \
      org.opencontainers.image.licenses="LicenseRef-Proprietary"

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/alias-entrypoint"]
