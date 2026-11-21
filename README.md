# Snapshot Site — Visual Release Review

A small release review for a very specific question: **is the Buy button still fully visible on mobile?**

Built with Symfony AI `1.0.0-BETA1` and the official PHP MCP SDK `0.10.0`, with exact dependency versions committed in `composer.lock`.

Give it two public URLs and a visual claim. Snapshot Site captures both pages at 390 × 844 pixels and produces a pixel comparison. A Symfony AI inspector examines the original screenshots. A second agent looks for evidence that contradicts the first. The report keeps both readings, their evidence and their limits.

If the agents disagree, the result is **uncertain**. This is a review of a visible page state, not an interaction test or permission to deploy.

## Demo — 89 seconds

https://github.com/user-attachments/assets/ec691a22-ca0b-4f0d-9cc3-6bc7c636c95b

[Watch on YouTube](https://youtu.be/GqdRkCIvGJc)

## Run it in five minutes

You need PHP 8.4+, Composer and the usual Symfony extensions, including DOM and XML. Node 20.9+ runs the local MCP server and the browser tests.

```sh
cd visual-release-review
composer install
npm ci
composer start
```

Open <http://127.0.0.1:8088>. Choose **Voir la régression** to explore the report without API credentials. The replay uses committed screenshots and written findings, clearly marked in the interface. It does not call Symfony AI or Snapshot Site.

The interface defaults to French. Use the **FR / EN** switch in the header, or open <http://127.0.0.1:8088/?lang=en> for English. The choice persists in the session and applies to forms, errors, example findings and both live agents. Example screenshots retain their original French product labels; the English findings refer to those visible labels.

The other examples show a harmless color change, an incomplete loading state and a disagreement. Their HTML source lives in `public/fixtures/product.html`; screenshots are generated from that page, not drawn placeholders.

The header uses Snapshot Site’s AVIF logo with a PNG fallback. The ICO favicon, PNG favicon and application icon are included in `public/images`.

## Docker deployment

The image includes PHP 8.4, FrankenPHP and Node 22 on Alpine Linux. `compose.yaml` runs it as UID 10001 with a read-only filesystem, temporary writable cache/session directories, no Linux capabilities, no privilege escalation, and CPU/memory/process limits. Dependencies are locked and base images are pinned by digest. Local API keys, Git history, development dependencies, `docs` and videos are excluded from the build context.

Create the private runtime configuration:

```sh
cp .env.docker.example .env.docker.local
chmod 600 .env.docker.local
openssl rand -hex 32
# Paste the generated value into APP_SECRET in .env.docker.local.
docker compose --env-file .env.docker.local up -d --build --wait
```

Open <http://127.0.0.1:8080> or <http://127.0.0.1:8080/?lang=en>. Empty API keys enable demo mode only. The image requires a random `APP_SECRET` of at least 32 characters and refuses to run with debug enabled. `.env.docker.local` is ignored by Git; keep its values out of build arguments.

To enable live comparisons, set both `OPENAI_API_KEY` and `SNAPSHOT_SITE_API_KEY` in `.env.docker.local`. Live mode also requires `REVIEW_BASIC_USER` and a bcrypt password hash. Generate the hash interactively:

```sh
docker run --rm -it --entrypoint frankenphp visual-release-review:local hash-password
```

Paste it as `REVIEW_BASIC_PASSWORD_HASH='$2a$...'`, keeping the single quotes so dollar signs remain literal. Re-run the Compose command to recreate the service. The browser requests credentials before accessing the app. The public `/healthz` endpoint boots Symfony and checks an application route, returning only `ok` or `unavailable` without creating a persistent session.

For **visual-review.snapshot-site.com**, point DNS to the server and use an HTTPS reverse proxy forwarding to `127.0.0.1:8080`. Preserve the Host header, forward `X-Forwarded-Proto`, and allow at least 360 seconds for a comparison response. Set `TRUSTED_PROXIES` to the exact proxy IP/CIDR as seen by the container; never trust every address. Only the configured proxy may declare HTTPS, which enables secure session cookies. Basic authentication requires HTTPS outside local development. Keep the published port bound to loopback. A proxy running in Docker should share a private network with `app` and use `app:8080` instead.

The image builds for the Docker host's architecture. Build on your deployment server, or use `docker buildx build --platform linux/amd64 --target production -t visual-release-review:amd64 --load .` for an x86 server. Set `REVIEW_IMAGE` to your image tag when using a separately built image and start Compose with `--no-build`. The local runtime checks were performed on ARM64; CI also builds and tests the image on GitHub's Linux runner.

```sh
python3 scripts/test-docker.py
docker compose --env-file .env.docker.local ps
docker compose --env-file .env.docker.local logs --tail=100 app
docker compose --env-file .env.docker.local down
```

The Docker tests use fake credentials and make no paid provider calls. They cover readiness failures, authentication, CSRF, secure proxy cookies, private files, the read-only runtime and a real PHP-to-Node MCP handshake. Cache and sessions are ephemeral; recreating the service resets browser sessions. Logs are rotated and the health check verifies PHP/Symfony, not just the web server.

### Security check — 2026-11-21

Production Composer and npm audits report no known advisories. Trivy 0.76.2 reports **0 critical vulnerabilities, 1 high vulnerability and 0 secrets** in the tested image. The remaining [CVE-2026-116587](https://github.com/go-jose/go-jose/security/advisories/GHSA-mgcc-cww5-fp2q) affects the bundled `go-jose/v3` dependency when parsing untrusted JWKs. This Caddy configuration enables PHP and bcrypt authentication, disables the administration API and automatic HTTPS, and does not expose JWT/JWK features. That is a configuration-based reachability assessment, not an upstream patch. Refresh image digests and re-run a full image scan before deploying; dependency audits alone do not cover system packages or bundled Go libraries. Provider quotas and the remote capture service's network restrictions still apply.

## Compare your own pages

Create `.env.local` in this directory:

```dotenv
SNAPSHOT_SITE_API_KEY=your_snapshot_site_key
OPENAI_API_KEY=your_model_key
OPENAI_MODEL=gpt-4o-mini
```

Restart the server, enter two public page URLs and a precise claim, then choose **Comparer mes pages**. Get a Snapshot Site key from the [console](https://console.snapshot-site.com/api-keys). The default `SNAPSHOT_SITE_MCP_TRANSPORT=stdio` runs the pinned official `@snapshot-site/mcp` package locally and passes the API key through the child process environment. It does not pass the OpenAI key to that process. Run `npm ci` before the first live comparison. To use a hosted server that accepts your authentication, set `SNAPSHOT_SITE_MCP_TRANSPORT=http`; its URL defaults to `https://mcp.snapshot-site.com/mcp`.

The live path performs a real MCP handshake with the official PHP SDK and calls `compare`. Two separate Symfony AI agents receive **both original images**, not just a diff score or the other agent’s summary. Their structured findings are validated before rendering. Missing keys, capture failures or malformed model output return an error; they never become a successful replay. Authentication, quota and analysis failures have distinct messages. Logs contain only the failed stage and a fixed reason code, never the raw provider response or credentials.

Page URLs are sent to Snapshot Site. Screenshot URLs and the claim are sent to the model provider, with response storage disabled. Keep credentials in `.env.local`, which is ignored by Git. API calls consume the respective providers’ quota.

The public capture service cannot reach `localhost`. To capture the included product page live, host the fixture files on a public origin and use these paths:

```text
/fixtures/product.html?state=before
/fixtures/product.html?state=broken
/fixtures/product.html?state=fixed
```

## HTTP errors

Application errors use a dedicated French/English page, including when debug mode is enabled. Opening `/review` directly returns a **405** page that links back to the form; it never launches a comparison. Missing pages return **404**. Unhandled exceptions return **500** with a generic message. Other 4xx/5xx statuses keep their HTTP code and protocol headers, including `Allow` and `Retry-After`.

Exception messages, stack traces and private paths are not rendered. Server errors log only a fixed event and status code. A minimal HTML fallback remains available if the error template cannot render. Existing form validation and provider errors keep their actionable form messages.

Try <http://127.0.0.1:8088/review?lang=fr> or <http://127.0.0.1:8088/page-absente?lang=en>. Tests inject server failures internally; there is no public route for triggering a 500.

## Code quality

```sh
composer quality
```

This runs Composer validation, PHP syntax checks, PHP CS Fixer in check mode, PHPStan at level 8, both PHPUnit suites, Twig linting and Symfony container validation. PHPStan includes the Symfony and PHPUnit extensions, with no baseline or ignored errors.

| Command | Purpose |
| --- | --- |
| `composer lint:php` | Check PHP syntax. |
| `composer cs:fix` | Apply the Symfony coding style. |
| `composer cs:check` | Check formatting without changing files. |
| `composer analyse` | Run PHPStan on source, tests and PHP entry points. |
| `composer test:unit` | Run the unit suite. |
| `composer test:functional` | Run the HTTP integration suite. |
| `composer quality:commit` | Run GrumPHP on versioned files. |
| `composer hooks:install` | Reinstall the Git hooks if needed. |

GrumPHP installs its pre-commit hook through Composer. It checks the Composer files, PHP syntax, coding style, static analysis and PHPUnit before a commit. The hook reports formatting problems; use `composer cs:fix` to fix them explicitly, then stage the changes again. Run `composer quality` for a full check even before the first commit, when files are still untracked.

GitHub Actions runs PHP quality checks, production dependency audits, browser tests and the Docker security smoke tests. Generated caches stay in ignored directories.

## Tests

```sh
composer test
npm ci
npx playwright install chromium
npm run test:e2e
```

PHPUnit covers verdict arbitration, validation, capture failure, MCP response parsing, the real PHP SDK handshake over a mocked HTTP transport, the real Symfony AI request format over mocked provider responses, session tokens and HTTP routes. No paid API is called.

Playwright exercises the full replay and form paths in desktop and mobile Chromium: all four outcomes, loaded evidence images, missing credentials, form preservation, rejected tokens, French/English switching and horizontal overflow. It also checks that the fixture’s banner actually covers the button, and that the fixed version does not. These deterministic tests do not measure model accuracy on arbitrary websites.

Regenerate the four committed evidence images while the local server is running:

```sh
npm run fixtures
```

The `.github/workflows/visual-release-review.yml` workflow runs PHPUnit and Playwright from this project’s root directory.

## How the pieces fit

![Visual Release Review architecture: Form to ReviewInput to PHP MCP SDK to Snapshot Site compare; both original images feed the inspector and critic, then validated findings produce an illustrated report.](assets/architecture-workflow.png)

The domain classes live in `src/Review`. Provider adapters live in `src/Infrastructure`. The controller handles session tokens, input errors and the HTML response. Findings are escaped by Twig; page and model text are treated as data in the prompts.

The initial URL check rejects private/reserved addresses, credentials and unusual ports. It is not a network sandbox: the remote capture service must also enforce its own redirect, DNS and subresource rules. The provided development server binds to loopback. Docker requires authentication for live mode. Add a shared quota limiter before offering access to a wider audience; the included session throttle only helps avoid accidental repeats.

The project is standalone. Keep `composer.lock`, `package-lock.json`, the fixtures and the license in version control; leave out `vendor`, `node_modules`, `var` and `.env.local`.

References: [Snapshot Site MCP](https://snapshot-site.com/mcp-server), [Symfony AI Agent](https://symfony.com/doc/current/ai/components/agent.html), [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).
