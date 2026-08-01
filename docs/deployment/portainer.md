# Deploying to Portainer

This stack builds directly from this Git repository — Portainer clones it and runs
`docker compose build` against the `Dockerfile` at the repo root on every deploy/redeploy.
No separate image registry is needed.

## Stack layout

- **`app`** — nginx + php-fpm (managed by supervisord inside one container), serves the web/API.
- **`worker`** — the same image, running `php artisan queue:work` continuously.
- **`scheduler`** — the same image, running `php artisan schedule:work` (fires `dub:cleanup-stale`
  and the daily janitors registered in `app/Console/Kernel.php`).
- **`mysql`** — `mysql:8`, with its own persistent volume.

`worker` and `scheduler` reuse the image `app` builds — Portainer/Compose builds it once.

## One-time setup, before the first deploy

1. **Generate `APP_KEY` once**, anywhere with PHP/Composer available:
   ```
   php artisan key:generate --show
   ```
   Save this value. It encrypts `SystemSetting`'s stored provider API keys (GenMax, image
   generation) — the container **refuses to start** if `APP_KEY` is unset (see
   `docker/entrypoint.sh`), and it must **never change** on an already-deployed stack, or every
   previously-saved encrypted setting becomes permanently unreadable.

2. **Find your existing reverse-proxy's Docker network name** on the host:
   ```
   docker network ls
   ```
   This is the network your Portainer nginx (or Traefik/NPM/etc.) is attached to. You'll set
   `PROXY_NETWORK_NAME` to this value below.

## Creating the stack in Portainer

1. **Stacks → Add stack → Repository.**
2. Point it at this Git repo, branch `master`, compose path `docker-compose.yml`.
3. Copy every variable from `.env.docker.example` into the stack's **Environment variables**
   section, filling in real values — at minimum: `APP_KEY`, `APP_URL`, `DB_PASSWORD`,
   `DB_ROOT_PASSWORD`, `SEPAY_WEBHOOK_TOKEN`, the AI provider keys (`GEMINI_API_KEY`,
   `GROQ_API_KEY`, `OPENROUTER_API_KEY`), and `PROXY_NETWORK_NAME`.
4. Deploy the stack.

## After every deploy that adds new migrations

Migrations do **not** run automatically (avoids a race between `app`/`worker`/`scheduler` all
starting at once and trying to migrate simultaneously). Run manually, once, after each deploy that
includes new migration files:

```
docker exec -it <app-container-name> php artisan migrate --force
```

## Reverse proxy

The `app` service joins the external network named by `PROXY_NETWORK_NAME` — it does not publish
any port to the host by default. Point your existing nginx/reverse-proxy at the `app` service by
its container/service name on that shared network, port `80`. If you'd rather bypass the existing
proxy and expose the app directly (e.g. for testing), uncomment the `ports:` mapping under `app` in
`docker-compose.yml`.

## Configuring provider settings after deploy

`genmax_api_key` and the four `image_gen_*` settings (base URL/API key/model/credits-per-image) are
NOT environment variables — they're stored in the `system_settings` table via the admin panel
(`/admin/tool-settings` for image generation; GenMax's key is set the same
`SystemSetting`-backed way). Log in at `/admin/login` after the first deploy to configure them.
