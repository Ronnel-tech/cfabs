# Render backend and Vercel frontend

Configured URLs:

- Frontend: `https://cfabs-frontend.vercel.app`
- LavaLust API origin: `https://cfabs-1.onrender.com`
- Database: keep the existing hosted MySQL database.

## Source repositories

The local Git root is `Booking-System/Lavalust/`. The sibling `frontend/` is
outside that repository. Commit/push the full backend implementation, migration
files, `composer.lock`, Docker/deployment files and `render.yaml` to the repository
Render builds. Include the application source as well as deployment files when
pushing changes; committing only the Dockerfile does not publish missing API
controllers or routes. Never stage the backend `.env`.

Put the frontend source in the repository your existing Vercel project builds.
For a separate frontend repository, its root is the current `frontend/` directory.
If you deliberately use a parent monorepo instead, set Vercel's Root Directory to
`frontend` and adjust Render's root/context to `Lavalust`. Do not move the projects
inside each other or deploy frontend build output through Apache.

## Render settings

Use the existing `cfabs-1` service. Set **Language: Docker**, Dockerfile `./Dockerfile`,
Docker context `.`, and health check `/healthz.php`. No custom start/build command
is required. `render.yaml` is an alternative setup template naming the service
`cfabs-1`; verify it links the existing service instead of creating a duplicate. The template
uses free compute and manual deploys; select a different plan only deliberately.

The image serves only `public/`, installs locked Composer dependencies and required
PHP extensions, binds Apache to Render's `PORT`, and validates production settings
before startup. `.dockerignore` excludes local secrets, runtime files and tests.
The health endpoint tests Apache/PHP liveness; API smoke checks also exercise
framework/database initialization. No migrations run during container startup.

Set these values in **Render → Environment**:

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_URL` | `https://cfabs-1.onrender.com` |
| `CORS_ALLOWED_ORIGINS` | `https://cfabs-frontend.vercel.app` |
| `TRUST_PROXY` | `1` |
| `APP_KEY` | Existing private backend `.env` value; preserve it for encrypted queues |
| `JWT_SECRET` | Existing private backend `.env` value |
| `REFRESH_TOKEN_KEY` | Existing distinct private backend `.env` value |
| `DB_DRIVER` | `mysql` |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Existing private database settings |
| `DB_PORT` | Existing provider port, normally `3306` |
| `DB_CHARSET` | `utf8mb4` |
| `DB_PREFIX` | Empty |
| `GOOGLE_CLIENT_ID` | Existing public Web OAuth client ID |
| `MAIL_APP_URL` | `https://cfabs-frontend.vercel.app` |
| `MAIL_PROVIDER` | `resend` |
| `MAIL_ENABLED` | `0` until sender/key/live delivery are configured |

Allow Render's outbound connections at the existing MySQL provider. This project
uses MySQL-specific SQL and migrations; do not substitute Render PostgreSQL.
The current framework database adapter does not expose custom MySQL CA/TLS options;
a provider requiring additional TLS configuration needs a compatible adapter setup.

Keep signing/encryption secrets out of Git and Docker build arguments. For a new
database, use new strong keys; for the existing database, preserve `APP_KEY` rather
than regenerating it. Back up the database before intentional migrations. Check
`php lava migration status` first: this workspace currently has 13 applied and no
pending migrations. Existing tables must not be recreated or reset.

## Vercel settings

Use the existing `cfabs-frontend` project and the repository containing frontend
source. Framework preset **Vite**, Node **22.x** (at least 22.22), install
`npm ci`, build `npm run build:vercel`, output `dist`.

`vercel.json` supplies SPA deep-link rewrites and allows camera access for the
frontend's own HTTPS origin. Public `.env.production` sets
`VITE_API_ORIGIN=https://cfabs-1.onrender.com`. Vercel's Environment Variables may
override it; remove any stale override or set it to that exact origin, without
`/api`. Redeploy after changing build-time values. Never add backend keys to any
`VITE_` variable: those values are shipped to every browser.

Local `npm run dev` still uses the existing Vite `/api` proxy to port 3000.
Production calls the Render origin directly, including authentication, private
photos and report downloads. Backend CORS permits the exact production frontend
origin with bearer headers; cookies and wildcard preview origins are not enabled.

Add `https://cfabs-frontend.vercel.app` to the Google Web client's **Authorized
JavaScript origins**, retaining localhost origins for development. No Google
client secret belongs in the frontend. Add any later custom frontend domain to
Google, Render CORS and `MAIL_APP_URL`, then redeploy as needed.

## Redeploy and verify

1. Push the complete backend and frontend source to their connected repositories.
2. Enter Render environment values privately and deploy the latest backend commit.
3. Redeploy the frontend with the new production API origin.
4. Run from the backend root (PowerShell 7):

   ```powershell
   ./deployment/smoke.ps1
   ```

5. Test login, an authorized booking, private photo, Excel/PDF download, Google
   Sign-In and phone camera scanning. Reload `/asset-problems` directly to test
   SPA routing. Frontend login after a full reload is expected: tokens remain
   memory-only by design.

The replacement `cfabs-1` deployment passed the live smoke script: Vercel root
HTTP 200, PHP health HTTP 200, CORS preflight HTTP 204, and unauthenticated API
HTTP 401 with the exact frontend CORS origin. The deployed frontend API chunk
uses `https://cfabs-1.onrender.com`; `/asset-problems` also loads directly.
See [live-verification.md](live-verification.md) for demo-account checks and
remaining acceptance work. Future local changes still require redeployment.

## Remaining operations

Runtime cache/session/rate-limit files are ephemeral; business data, photos,
refresh tokens and email outbox live in MySQL. No disk or extra paid service was
provisioned. No notification scheduler is deployed by this configuration. When
ready, configure a scheduler to run `php lava notifications:dispatch --send` and
then `php lava email:dispatch --send` with backend environment settings. Live mail
still needs a fresh private Resend key, sender setup and delivery verification.

Local checks: 21 backend deployment checks, 113 frontend tests, Vercel production
build, lint, PHP syntax and startup shell syntax pass. Docker is unavailable on
this machine, so the image has not been built/run here. The first Render build
was followed by successful live smoke verification. Interactive acceptance and
the remaining operations above are still required before real campus use.

References: [Render Docker](https://render.com/docs/docker),
[Render Blueprint fields](https://render.com/docs/blueprint-spec),
[Vercel Vite routing](https://vercel.com/docs/frameworks/frontend/vite),
[Vite environment variables](https://vite.dev/guide/env-and-mode).

Environment settings were saved on Render service `cfabs-1` and Vercel Production.
Existing database and signing secrets were preserved. Mail remains disabled.
The deployed replacement service now passes live smoke verification.
