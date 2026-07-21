# Docker (single image)

The fastest way to run Alias TTS: **one container carries the whole service**
— the web app, the queue worker (async jobs), the scheduler (cleanup + health
heartbeat), the **Whisper ASR sidecar** (transcript QA with automatic
re-roll/trim), and the **Genblaze runner** (whole-render orchestration and the
pronunciation pre-processor). Everything in [DEPLOYMENT.md](DEPLOYMENT.md)'s
process table, pre-wired. ffmpeg (≥ 8.1.2, the `tts:doctor` minimum) is baked
in, as is the default Whisper model, so first boot needs no downloads.

All state lives on a single `/data` volume: the SQLite database, generated
audio, voice clips, secrets, and ASR models. Back up that volume and you've
backed up the install.

## Quick start

Released images live on the GitHub Container Registry as
**`ghcr.io/johnfmorton/alias-tts`** (`X.Y.Z` + `latest`, amd64 and arm64 —
published per chosen release by a manual run of
`.github/workflows/docker-publish.yml`, so not every git tag has an image).
The package is **private**: you need pull access granted on GitHub, then a
one-time login with a [personal access token](https://github.com/settings/tokens)
that has the `read:packages` scope:

```bash
docker login ghcr.io -u <your-github-username>   # password: the PAT

docker run -d --name alias-tts \
  -p 8080:80 \
  -v alias-data:/data \
  -e REPLICATE_API_TOKEN=r8_xxx \
  -e ADMIN_EMAIL=you@example.com \
  -e ADMIN_PASSWORD=a-strong-password \
  ghcr.io/johnfmorton/alias-tts:latest
```

(With repo access you can instead build it yourself:
`docker build -t alias-tts .` from a checkout.)

Then open <http://localhost:8080> and sign in with the admin credentials. The
first boot generates `APP_KEY` and the app↔runner shared secret, migrates the
database, and creates the admin login — after that the two `ADMIN_*` variables
can be dropped (they're only read on first boot; later use
`docker exec -u app alias-tts php artisan admin:create`).

That's a complete install: point an ElevenLabs or OpenAI TTS client at
`http://localhost:8080/v1/...` with an API key from the dashboard.

Prefer compose? The same thing:

```yaml
services:
  alias-tts:
    image: ghcr.io/johnfmorton/alias-tts:latest
    ports:
      - "8080:80"
    volumes:
      - alias-data:/data
    environment:
      APP_URL: http://localhost:8080
      REPLICATE_API_TOKEN: r8_xxx
      ADMIN_EMAIL: you@example.com
      ADMIN_PASSWORD: a-strong-password
    restart: unless-stopped

volumes:
  alias-data:
```

## How configuration works

The container keeps its `.env` at **`/data/.env`**, seeded on first boot from
a template with container-appropriate defaults (SQLite on `/data`, logs to
`docker logs`, both sidecars wired up on loopback). Two ways to change
settings, both valid:

1. **Container environment** (`-e NAME=value` / compose `environment:`) — a
   real env var always beats the file. Good for secrets and anything you want
   in your compose file.
2. **Edit `/data/.env`** and restart the container. Good for permanent,
   file-managed config.

The fully annotated reference for every setting ships in the image at
`/var/www/html/.env.example`.

Settings you'll most likely touch:

| Variable | Default | Why change it |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | Set to the address users browse to — it drives generated links. |
| `REPLICATE_API_TOKEN` | *(empty)* | **Required** for real generation. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(empty)* | First-boot dashboard login. |
| `SERVER_NAME` | `:80` | Set to your domain for automatic HTTPS (below). |
| `TTS_STORAGE_DISK` | `local` (on `/data`) | `s3` + the `AWS_*` block for any S3-compatible bucket — also gives the Genblaze runner its provenance archive. |
| `DB_CONNECTION` | `sqlite` (on `/data`) | Point `DB_*` at MySQL/MariaDB if you'd rather. Migrations run automatically on boot either way. |
| `ASR_MODEL` | `tiny` | A bigger Whisper model (`base`, `small`) for the QA gate; downloads once to `/data/asr-models`. |
| `TTS_ASR_ENABLED` | `true` | The QA sidecar is bundled, so it's on by default; `false` opts out. |
| `TTS_PRONUNCIATION_ENABLED` | `false` | Turn on the LLM pronunciation pre-processor (uses your Replicate token by default). |

## HTTPS

Two good options:

- **Your own reverse proxy / tunnel** (nginx, Traefik, Caddy, Cloudflare):
  keep the default `:80` listener, set `APP_URL=https://your-domain`,
  `TRUSTED_PROXIES=*` (or the proxy's IPs), and — once TLS is end-to-end —
  `SESSION_SECURE_COOKIE=true`. The `.env.example` notes on these two apply
  verbatim.
- **Built-in automatic HTTPS**: the web server is FrankenPHP (Caddy), so
  `-e SERVER_NAME=tts.example.com -p 80:80 -p 443:443` obtains and renews a
  Let's Encrypt certificate by itself (the domain must resolve to the host).
  Certificate state persists under `/data/caddy`. Set
  `APP_URL=https://tts.example.com` and `SESSION_SECURE_COOKIE=true` to match.

## What's running inside

One supervisord tree (see `docker/supervisord.conf`), all as a non-root user:

| Process | What it does |
|---|---|
| `web` — FrankenPHP | The app, on `:80`/`:443` plus a loopback-only listener `127.0.0.1:8081` used by the runner callback and the container health check. |
| `queue` — `queue:work` | Async `/v1/.../jobs` generation + Genblaze run jobs. |
| `scheduler` — `schedule:work` | Daily audio-TTL cleanup, recovery-project and clip pruning, the doctor's cron heartbeat. |
| `asr` — uvicorn `:8765` | Whisper transcript QA; scores every chunk, feeds auto re-roll/trim. |
| `runner` — uvicorn `:8800` | Genblaze whole-render pipeline + pronunciation LLM calls. |

Sidecar ports stay inside the container — only 80/443 are exposed.

Health and debugging:

```bash
docker ps                                   # HEALTHCHECK hits /up via the loopback listener
docker exec -u app alias-tts php artisan tts:doctor --deep   # full config/health audit
docker exec -u app alias-tts php artisan tts:asr:health      # ASR sidecar reachability + model
docker exec alias-tts supervisorctl status            # per-process state
docker logs alias-tts                       # all processes log to stdout/stderr
```

## Data, backup, updating

`/data` layout: `database.sqlite`, `.env` (holds the generated `APP_KEY` —
losing it orphans encrypted data), `storage/` (generated audio, voice clips,
sealed finals), `asr-models/`, `genblaze/` (runner work area), `caddy/`
(TLS state). **Backup = snapshot the volume** (stop the container first, or at
least accept a crash-consistent SQLite copy).

To update: build/pull the new image, then

```bash
docker stop alias-tts && docker rm alias-tts
docker run -d --name alias-tts ... same flags ...   # or: docker compose up -d
```

Migrations and cache warming run automatically on boot. Nothing outside
`/data` needs to survive.

## Building notes

- `docker build` produces the image for the host architecture; both `amd64`
  and `arm64` work (a per-arch static ffmpeg is downloaded and checksum-verified
  at build time). Multi-arch:
  `docker buildx build --platform linux/amd64,linux/arm64 .`
- `--build-arg ASR_BAKE_MODEL=small` bakes a bigger Whisper model into the
  image instead of `tiny` (useful for air-gapped hosts).
- The bundled ffmpeg is a static [BtbN](https://github.com/BtbN/FFmpeg-Builds)
  **GPL** build (sources available at that project); the app invokes it as a
  separate binary. The build tracks that project's permanent `latest` release
  (`n8.1-latest` asset, the tip of the 8.1 branch) so it stays ≥ 8.1.2 without
  pinning a dated `autobuild-*` tag that upstream would later prune.
- Want the fake provider for a demo without a Replicate token? Run with
  `-e TTS_PROVIDER=fake` — deterministic placeholder audio, full pipeline.
