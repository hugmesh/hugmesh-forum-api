# hugmesh/forumapi — phpBB 3.3 headless Forum API extension

This is the **server side** of the `hugmesh-forum-bff-api` client. phpBB ships no REST
API, so this extension adds one. It registers routes under `/api/v1/hugmesh/**` on
phpBB's Symfony HttpKernel, validates the Casdoor JWT, and works out the caller's
authority **on the server**. It reads and writes through phpBB's **own** services
(`submit_post()`, `content_visibility`, the group functions, `user_add()`). Permission
checks are never bypassed, and the schema stays locked to the install.

It installs at `ext/hugmesh/forumapi/` (composer name `hugmesh/forumapi`, PHP namespace
`hugmesh\forumapi`). The composer `name` MUST equal the install directory path and may
contain no hyphen. phpBB checks the name against `^[A-Za-z0-9_]{2,}/[A-Za-z0-9_]{2,}$` and
requires `name == ext/<dir>`. A hyphenated `hugmesh/forum-api` cannot be enabled, and
`extension:enable` reports "does not exist".

---

## Why an extension (not the alternatives)

phpBB 3.1+ runs on Symfony. An extension can register routes in `config/routing.yml`,
return Symfony `JsonResponse`, and inject phpBB's DI services. Three alternatives lost:
the unofficial community REST extension (alpha, cookie auth), `phpbbcli` (stateful, no
HTTP boundary), and direct R2DBC against the phpBB schema (skips phpBB's permission and
ban checks). The forum-bff `PhpbbApiClient` documents the same reasoning.

---

## The contract

Base path: **`/api/v1/hugmesh`**. Every request carries the Casdoor JWT twice: as
`Authorization: Bearer <jwt>` **and** as `X-HugMesh-JWT: <jwt>`. JSON field names and types
match the forum-bff Jackson records exactly. Timestamps are ISO-8601 strings.

### Endpoints

| Method | Path | Body | Returns | Authz |
|---|---|---|---|---|
| GET | `/health` | — | `{status,service}` (200) | **none** (k8s probe) |
| GET | `/categories` | — | `Category[]` | user |
| GET | `/topics?categoryId={id}` | — | `Topic[]` | user |
| GET | `/topics/{id}` | — | `Topic` | user |
| GET | `/topics/{id}/replies` | — | `Reply[]` | user |
| GET | `/posts/{id}` | — | `Reply` | user |
| GET | `/stats` | — | `Stats` | user |
| POST | `/topics` | `CreateTopic` | `Topic` (201) | user (acts-as-user) |
| POST | `/topics/{id}/replies` | `CreateReply` | `Reply` (201) | user (acts-as-user) |
| PUT | `/posts/{id}` | `EditPost` | `Reply` | owner, or MOD/ADMIN |
| DELETE | `/posts/{id}` | — | 204 | owner, or MOD/ADMIN |
| POST | `/moderation/topics/{id}` | `ModerationAction` | 204 | MOD+ |
| POST | `/users/{sub}/ensure` | — | `ForumUser` (201) | ADMIN |
| PUT | `/users/{sub}/groups` | `GroupAssignment` | 204 | ADMIN |
| POST | `/admin/styles` | `["name"]` | 204 | ADMIN |
| POST | `/admin/extensions` | `["name"]` | 204 | ADMIN |
| GET | `/admin/users` | — | `ForumUser[]` | ADMIN |
| GET | `/admin/users/{sub}` | — | `ForumUser` | ADMIN |
| POST | `/admin/categories` | `CreateCategory` | `Category` (201) | ADMIN |
| GET | `/admin/moderation-queue` | — | `QueueItem[]` | ADMIN |
| POST | `/admin/moderation/{id}/approve` | — | 204 | ADMIN |
| POST | `/admin/moderation/{id}/reject` | — | 204 | ADMIN |
| POST | `/admin/users/{sub}/ban` | `BanRequest?` | 204 | ADMIN |
| DELETE | `/admin/users/{sub}/ban` | — | 204 | ADMIN |

### JSON shapes

```
Category    { id:long, name:string, description:string, topicCount:int }
Topic       { id:long, categoryId:long, title:string, authorSubject:string, replyCount:int, createdAt:ISO8601 }
Reply       { id:long, topicId:long, authorSubject:string, body:string, createdAt:ISO8601 }
ForumUser   { casdoorSubject:string, phpbbUserId:long, group:string }
Stats       { topics:long, posts:long, activeUsers:long, moderationQueueDepth:long, source:string }
QueueItem   { id:string, topicId:long, authorSubject:string, excerpt:string, state:string, createdAt:ISO8601 }

CreateTopic     { categoryId:long, title:string, body:string }
CreateReply     { body:string }
EditPost        { body:string }
ModerationAction{ action:string(hide|lock|delete), reason:string }
GroupAssignment { casdoorSubject:string, group:string, roles:string[], lastSynced:ISO8601 }
CreateCategory  { name:string, description:string, parentId:long? }
BanRequest      { reason:string? }
```

> **`authorSubject` is NEVER read from a request body.** It is always the validated
> JWT `sub` (the post owner). A caller cannot post under another user's name.

---

## Auth (security-critical)

1. The JWT comes from `Authorization: Bearer` and/or `X-HugMesh-JWT`. When both are
   present they must be the **same** token. Two different tokens are refused, so a caller
   cannot downgrade by confusing the headers.
2. The **RS256 signature** is checked against the Casdoor JWKS. The key set is fetched
   from the issuer's `jwks_uri` (`HUGMESH_JWKS_URI`) with `firebase/php-jwt`, and cached
   for one hour (`jwks_provider::CACHE_TTL`). The URI MUST be `https://`. Only RS256 keys
   are kept. If a signature or key lookup fails, the key set is refreshed once (key
   rotation) before the request fails closed. A dev-only knob,
   `HUGMESH_JWKS_ALLOW_INSECURE_HTTP=1`, permits a plain `http://` JWKS URI. The compose
   lane uses it. Never set it in a cluster. It never relaxes an `https://` URI.
3. `firebase/php-jwt` enforces `exp` and `nbf`. It does not check `iss` or `aud`, so this
   extension does. `iss` is checked against `HUGMESH_JWT_ISSUER`, which is **required**.
   If it is unset, every token gets `401`. The check never fails open. `aud` is
   **optional** and checked **only if** `HUGMESH_JWT_AUDIENCE` is set.
4. **Authority is re-derived on the server** from the token claims, following the hugmesh
   `CasdoorAuthorities` convention exactly:
   - `roles[]` (strings or `{name}` objects) → `ROLE_<UPPER>`;
   - `permissions[]` verbatim, **except** entries starting with a reserved prefix
     (`ROLE_` / `DOMAIN_`), which are **dropped** (defense against authority injection);
   - `isAdmin: true` → `ROLE_ADMIN`;
   - floor `ROLE_USER`.

   Group or role data in a request body is **never** trusted as authority.
5. **Per-route authorization** fails closed. Reads need any authenticated user. Writes need
   `ROLE_USER`+, and edit/delete also need post ownership unless the caller is MOD/ADMIN.
   Moderation needs `ROLE_MOD`+. `/admin/**`, `ensure` and group sync need `ROLE_ADMIN`.
   Status codes: 401 (no or invalid token), 403 (wrong role or ownership), 404 (missing),
   400 (bad body), 500 (any other error, with a generic message). Errors are JSON:
   `{error:true,status,message}`.

> **One deliberate exception:** `GET /health` is **unauthenticated**, because a Kubernetes
> liveness/readiness probe carries no JWT. It returns a static `{status,service}` literal:
> no DB query, no caller input, no forum data. That is safe to expose because the phpBB
> Service is ClusterIP-only with no ingress, and a NetworkPolicy admits only forum-bff,
> admin-bff and the sync Job. It still sits inside the fail-closed JSON error envelope.

Config comes from env first, then the phpBB config table, then a default. **No secret is
committed.** See `.env.example`.

| Env var | Config-table key | Default |
|---|---|---|
| `HUGMESH_JWKS_URI` (required) | `hugmesh_forumapi_jwks_uri` | none |
| `HUGMESH_JWT_ISSUER` (required) | `hugmesh_forumapi_issuer` | none (all tokens rejected) |
| `HUGMESH_JWT_AUDIENCE` | `hugmesh_forumapi_audience` | empty (no audience check) |
| `HUGMESH_JWT_LEEWAY` | `hugmesh_forumapi_leeway` | `30` seconds |
| `HUGMESH_DEFAULT_FORUM_ID` | `hugmesh_forumapi_default_forum_id` | `2` (used when `CreateTopic.categoryId` is 0 or absent) |

---

## phpBB mechanics

- **Casdoor sub → phpBB user_id** goes through the side table `phpbb_hugmesh_casdoor_map`
  (migration `migrations/m1_initial_schema.php`). `POST /users/{sub}/ensure` calls
  `user_add()` keyed by the sub and inserts the mapping row. Any acts-as-user write
  does the same, lazily, if the user has no row yet.
- **Acts-as-user posting** calls `submit_post()` with the mapped poster's `user_id`.
  Last-post bookkeeping and notifications must name that user too. So the posting service
  briefly swaps the target's row into `$user->data` around the call, then restores the
  original (`posting_service::as_user()`).
- **Moderation** (`hide`/`delete`) uses the `content.visibility` service
  (`set_post_visibility` / `set_topic_visibility` with `ITEM_DELETED` / `ITEM_APPROVED`).
  `lock` sets `topic_status` to locked. There is no unlock action. `hide` and `delete` do
  the same thing: both soft-delete the topic.
- **Group sync** (`PUT /users/{sub}/groups`) is a **declarative converge**. The managed
  groups are `ADMINISTRATORS` and `GLOBAL_MODERATORS`: the user is added to the target one
  (if any) and removed from the other (`group_user_add` / `group_user_del`), and is always
  (re)added to `REGISTERED`. Other memberships are untouched. A valid `group` in the body
  (`ADMINISTRATORS`, `GLOBAL_MODERATORS`, `REGISTERED` or `NONE`) wins. Otherwise the
  target comes from `roles[]`: `ADMIN` gives `ADMINISTRATORS`; `MOD` with `FORUM` gives
  `GLOBAL_MODERATORS`; `FORUM` alone gives `REGISTERED`; anything else gives `NONE`.
  `REGISTERED` membership is never removed.
- **Bans** (`POST`/`DELETE /admin/users/{sub}/ban`): the sub must already be mapped, or
  the answer is 404. A ban upserts `phpbb_banlist`, changes `user_type`, drops all groups
  except `REGISTERED`, and kills the user's sessions. An unban removes the entry,
  restores `user_type = 0`, ensures `REGISTERED`, and kills stale sessions.
- **Reads** query phpBB tables through the injected DBAL connection and map the rows to
  the DTOs. Stats are `COUNT` queries (`source = "live"`).
- **Sticker tokens**: `:snap-sticker:<slug>:` in stored post text is rendered on the server
  (`text/sticker_tokens.php`) into a same-origin `<img class="forum-sticker">` tag. This
  happens before `Reply.body` and `QueueItem.excerpt` are returned. Those two fields can
  therefore hold HTML, not only plain text. `event/main_listener.php` also hooks phpBB's
  s9e text formatter (`core.text_formatter_s9e_configure_after`), so the same token
  renders the same way if phpBB's own pipeline ever shows these posts.

---

## Install

### Baked image (deployment)

The `Dockerfile` builds the phpBB image: official phpBB 3.3 (`PHPBB_VERSION`, default
3.3.11) on `php:8.1-apache`, this extension at `ext/hugmesh/forumapi/`, and
`composer install` for `firebase/php-jwt`. Its entrypoint regenerates `config.php` from
env on every boot and enables the extension via `phpbbcli` (idempotent).
`docker-compose.yml` tags the image `hugmesh-systems/phpbb:dev`.

**How the cluster finds the image.** The manifest
`hugmesh-generator specs/cluster/manifests/forum/phpbb.yaml` (namespace `hugmesh-forum`)
names the image with the placeholder `hugmesh/phpbb:dev`, in both the `bootstrap`
initContainer and the `phpbb` container. The `images:` entry in the kustomization next to
it rewrites that to `registry.hugmesh.net/hugmesh/hugmesh-phpbb:main`, pulled with the
`harbor-registry` secret. The manifest already sets `PHPBB_EXTENSION=hugmesh/forumapi`,
`HUGMESH_JWKS_URI` and `HUGMESH_JWT_ISSUER`. It runs `runAsNonRoot` as UID 33 with
`capabilities.drop: ["ALL"]`, and probes `/api/v1/hugmesh/health`. The Service maps port
80 to the named container port 8080.

This repo does not push to Harbor. `.gitlab-ci.yml` pushes `$CI_REGISTRY_IMAGE:<tag>` and
`:dev` to the GitLab registry. `.github/workflows/release.yml` pushes
`ghcr.io/hugmesh/hugmesh-forum-api` (see "Versions and releases" below).

### Manual (existing phpBB 3.3)

```bash
cd /path/to/phpBB/ext
mkdir -p hugmesh/forumapi
# copy this repo's contents into hugmesh/forumapi/
cd hugmesh/forumapi && composer install --no-dev
cd /path/to/phpBB
php bin/phpbbcli.php extension:enable hugmesh/forumapi
# set HUGMESH_JWKS_URI / HUGMESH_JWT_ISSUER in the web server environment
```

With `mod_php`, Apache strips the `Authorization` header. Set `CGIPassAuth On` for the
document root (the `Dockerfile` does). Without it only `X-HugMesh-JWT` arrives, and the
dual-header agreement check is silently off.

---

## How the forum-bff flips `phpbb.enabled=true`

The forum-bff `PhpbbApiClient` / `PhpbbWebClientConfig` sit behind `phpbb.enabled` (env
`PHPBB_ENABLED`, default `false` in `application.yml`). While it is off, every extension
call returns a typed degraded or empty result (the client maps writes to 202-pending), and
the forum boots clean against a stub. Turning it on is **config-only**:

```yaml
# forum-bff env
PHPBB_ENABLED: "true"
PHPBB_API_BASE_URL: "http://phpbb.hugmesh-forum.svc.cluster.local:80"
```

`PHPBB_API_BASE_URL` already defaults to that value. The generator's
`specs/cluster/manifests/forum-bff-api/deployment.yaml` sets `PHPBB_ENABLED: "true"` (and
`EXTERNAL_FORUM_ENABLED: "true"`).

The client forwards the user's Casdoor JWT (Bearer + `X-HugMesh-JWT`) on every call. This
extension validates it and re-derives authority. On success it returns real bodies, and
on failure real 4xx/5xx.

---

## Develop / validate

```bash
composer install
composer validate --no-check-publish
find . -path ./vendor -prune -o -name '*.php' -print | xargs -n1 php -l   # php -l everything
vendor/bin/phpunit                                                        # unit suite (tests/*_test.php)
```

The **unit suite needs no live phpBB**. It has nine test files in `tests/` (plus a
bootstrap and the RSA test keys). The JWT/JWKS auth
chain (`tests/jwt_verifier_test.php`) is covered for: valid token accepted;
bad-signature / expired / wrong-issuer / wrong-audience rejected; authority re-derived
from `roles`/`permissions`/`isAdmin`; reserved-prefix injection dropped; body-supplied
`authorSubject` ignored; dual-header agreement; ownership and role gates. The other files
cover the controller error envelope, `/health`, the `as_user()` identity restore, group
convergence, ban/unban, sticker-token rendering, and the s9e text-formatter hook.
`tests/release_workflow_test.php` guards the release setup: the beta version in
`composer.json`, no pull-request trigger in `release.yml`, one guarded `runs-on` line per
job, and no `:latest` tag.

**Live phpBB smoke test.** Unit tests cannot cover real `submit_post()` /
`content_visibility` / `user_add` against a real schema. This harness does. It is
**cluster-shaped**: Postgres 16 (the dbms the cluster runs) and a fully **headless**
bring-up. The phpBB web installer is unreachable by design: the image's Apache lockdown
denies every `.php` except `app.php`, and the entrypoint removes `install/` before
serving.

```bash
docker compose up --build
# First boot: the entrypoint probes the empty Postgres and runs the headless installer
# with docker/install-config.compose.yml (DEV-ONLY literal creds; DDL-once guarded),
# enables hugmesh/forumapi, removes install/, and serves rootless on :8080. Then:
curl -i http://localhost:8080/app.php/api/v1/hugmesh/health          # 200, no JWT
curl -i http://localhost:8080/app.php/api/v1/hugmesh/stats           # 401 fail-closed
curl -i http://localhost:8080/index.php                                 # 403 (lockdown)
# ...and /app.php/api/v1/hugmesh/** with a Casdoor JWT for the authed contract.
# Restarting the phpbb service must log "schema already present … NOT re-installing".
```

CI is GitLab (`.gitlab-ci.yml`): `php -l` → `composer validate` → `phpunit` →
**`build:image`**. `build:image` bakes and pushes the custom phpBB image, on the default
branch and tags only, from the runner tagged `hugmesh-x86, privileged`. The only GitHub
workflow is `.github/workflows/release.yml`. It runs the same three checks in the same
`php:8.1-cli` image, then pushes the image to GHCR.

### Versions and releases

The version is `composer.json` `"version"`, in the form `MAJOR.MINOR.PATCH-beta.N`. It is
`0.2.0-beta.1` today: built and tested (see "Go-live status" for the compose smoke runs),
never published, and not yet shown running in the default mesh. (It was `1.0.0` before; nothing was ever published under that
number.) A new beta of the same base raises `N`.

`release.yml` runs on:

| Event | Image tags pushed | GitHub Release |
|---|---|---|
| push to `main` | `:main`, `:sha-<12 hex>` | none |
| push of tag `v<version>` | `:<version>`, `:sha-<12 hex>`, `:beta` | pre-release, not latest |
| `workflow_dispatch` on `main` | same as a push to `main` | none |
| `workflow_dispatch` on a `v*` tag | re-runs that release | created if missing |

The version job refuses a tag that does not match `composer.json`, a tag that is not on
`main`, a development version, and any other ref. A published version tag is never
overwritten: a re-run of the same commit skips the push, anything else fails. A beta is
never tagged `:latest`. To cut a beta, merge the version bump, then push an annotated tag
on that `main` commit:

```bash
git tag -a v0.2.0-beta.1 -m "hugmesh-forum-api 0.2.0-beta.1"
git push origin v0.2.0-beta.1
```

There is no pull-request trigger. Each job runs on the runner named by the repository
variable `RUNS_ON_PUBLISH` (a JSON array such as `["self-hosted","Linux","X64"]`), but only
for `main` and `v*` tag refs. Otherwise, or when that variable is unset, it runs on hosted
`ubuntu-latest`. Deleting `RUNS_ON_PUBLISH` sends every job back to hosted runners.

---

## Go-live status (honest)

**Live-proven** (against real **phpBB 3.3.11**):

- The custom image **builds** (official phpBB 3.3.11 on `php:8.1-apache`, this extension at
  `ext/hugmesh/forumapi/`, `firebase/php-jwt`), and the `build:image` CI stage pushes it.
- The extension **enables** under the de-hyphenated name `hugmesh/forumapi`. The hyphenated
  package would not enable at all (see the composer-name note above).
- The schema **migration runs** and creates the `phpbb_hugmesh_casdoor_map` table.
- The routes **register and match**. The full authed round-trip passed a docker-compose
  smoke (30/30) against a real install: acts-as-user authorship, moderation visibility,
  group sync, ownership and role gates, and every auth negative. That round-trip ran on the
  **earlier MySQL-based harness**. The local harness has since moved to the
  cluster-shaped Postgres setup (PR #12). Only the shallow checks have been re-run there:
  `/health` 200, `/stats` 401 fail-closed, board lockdown 403, restart-idempotent schema
  detection. The deep round-trip has not been re-run against Postgres. That is the gap
  below.

**NOT yet live-proven:** end-to-end authed HTTP posting **through the route on a
cluster-shaped (pgsql, init→main split) harness**. The entrypoint rewrite (PR #7) settled
the bootstrap and lockdown concerns. The entrypoint regenerates `config.php` from env on
every boot, removes `install/` before serving, runs Apache rootless on port 8080, and
includes the pgsql driver. The gap that remains is a kubeadm/generator follow-up, not an
extension bug.

**Generator state:**

- The image name is not drift. `phpbb.yaml` uses the placeholder `hugmesh/phpbb:dev` on
  purpose, and the kustomization `images:` entry rewrites it (see Install). Nothing needs
  repointing to `hugmesh-systems/phpbb:dev`. This repo does not show which job pushes
  `registry.hugmesh.net/hugmesh/hugmesh-phpbb:main`.
- `PHPBB_EXTENSION`, `HUGMESH_JWKS_URI` and `HUGMESH_JWT_ISSUER` are already correct in
  `phpbb.yaml`. `PHPBB_ENABLED` is already `"true"` in the forum-bff deployment.
- Keep the board locked down with the image-level Apache lockdown and the NetworkPolicy,
  not the board-disable flag.
- The pod `securityContext` (`runAsNonRoot` + `drop: ["ALL"]`) is **resolved**. Apache
  binds the non-privileged port 8080, the image defaults to `USER 33:33`, and the
  two-container harness confirmed `runAsNonRoot` + `capabilities.drop:[ALL]` works. The
  Service maps port 80 → targetPort 8080.

This extension is the once-"unbuilt external contract" that the forum-bff
(`hugmesh-forum-bff-api`) `PhpbbApiClient` was written against. It is built now.

---

## License

GPL-2.0-only (phpBB extension convention). The full text is in [`LICENSE`](LICENSE).
