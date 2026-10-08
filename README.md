# hugmesh/forumapi — phpBB 3.3 headless Forum API extension

The **server side** the (already-built) `hugmesh-forum-bff-api` client calls. phpBB
ships no first-class REST API; this extension registers routes under
`/api/v1/hugmesh/**` on phpBB's Symfony HttpKernel, validates the Casdoor JWT,
re-derives authority **server-side**, and performs reads/writes through phpBB's **own**
services (`submit_post()`, `content_visibility`, the group functions, `user_add()`) so
permission/consistency logic is never bypassed and the schema is version-locked to the
install.

Installs at `ext/hugmesh/forumapi/` (composer name `hugmesh/forumapi`, PHP namespace
`hugmesh\forumapi`). The composer `name` MUST equal the install directory path and may
contain no hyphen: phpBB validates the extension name against
`^[A-Za-z0-9_]{2,}/[A-Za-z0-9_]{2,}$` and requires `name == ext/<dir>` — a hyphenated
`hugmesh/forum-api` is silently un-enableable (`extension:enable` reports "does not
exist").

---

## Why an extension (not the alternatives)

phpBB 3.1+ runs on Symfony. An extension can register routes in `config/routing.yml`,
return Symfony `JsonResponse`, and inject phpBB's DI services. Rejected alternatives:
the unofficial community REST extension (alpha, cookie-auth), `phpbbcli` (stateful, no
HTTP boundary), and direct R2DBC against the phpBB schema (bypasses phpBB
permission/ban checks). This matches the rationale documented in the forum-bff
`PhpbbApiClient`.

---

## The contract

Base path: **`/api/v1/hugmesh`**. Every request carries the Casdoor JWT as **both**
`Authorization: Bearer <jwt>` **and** `X-HugMesh-JWT: <jwt>`. JSON field names/types
match the forum-bff Jackson records exactly; timestamps are ISO-8601 strings.

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
> JWT `sub` (the post owner). A caller cannot author under another user.

---

## Auth (security-critical)

1. The JWT is extracted from `Authorization: Bearer` and/or `X-HugMesh-JWT`. When
   both are present they must be the **same** token (no header-confusion downgrade).
2. **RS256 signature** is verified against the Casdoor JWKS — fetched (and cached) from
   the issuer's `jwks_uri` (`HUGMESH_JWKS_URI`), using `firebase/php-jwt`. The URI MUST
   be `https://`. Only the key set's RS256 keys are kept. The cached set lives one hour
   (`jwks_provider::CACHE_TTL`). On a signature or key-lookup failure the key set is
   refreshed once (rotation) before failing closed. A dev-only knob,
   `HUGMESH_JWKS_ALLOW_INSECURE_HTTP=1`, permits a plain `http://` JWKS URI (the compose
   lane only; never set it in a cluster; it never relaxes an `https://` URI).
3. `exp` / `nbf` are enforced by `firebase/php-jwt`. `iss` is verified here against
   `HUGMESH_JWT_ISSUER`, which is **required** — if it is unset, every token is
   rejected with `401` (fail-closed; we never fall open and skip the issuer check). `aud`
   is **optional** and verified here **iff** `HUGMESH_JWT_AUDIENCE` is set
   (`firebase/php-jwt` does not verify `iss`/`aud` itself).
4. **Authority is re-derived server-side** from the token claims — mirroring the
   hugmesh `CasdoorAuthorities` convention exactly:
   - `roles[]` (strings or `{name}` objects) → `ROLE_<UPPER>`;
   - `permissions[]` verbatim, **except** entries starting with a reserved prefix
     (`ROLE_` / `DOMAIN_`) are **dropped** (authority-injection defense);
   - `isAdmin: true` → `ROLE_ADMIN`;
   - floor `ROLE_USER`.
   Caller-supplied group/role data in a request body is **never** trusted as authority.
5. **Per-route authorization** (fail-closed): reads = any authenticated user; writes =
   `ROLE_USER`+ (edit/delete enforce post ownership unless MOD/ADMIN); moderation =
   `ROLE_MOD`+; `/admin/**`, `ensure`, group-sync = `ROLE_ADMIN`. Status codes: 401
   (no/invalid token), 403 (wrong role/ownership), 404 (missing), 400 (bad body), 500
   (any other error, with a generic message). Errors are JSON:
   `{error:true,status,message}`.

> **One deliberate exception:** `GET /health` is **unauthenticated** — a Kubernetes
> liveness/readiness probe carries no JWT. It returns a static `{status,service}` literal
> (no DB query, no caller input, no forum data) and is safe to expose because the phpBB
> Service is ClusterIP-only with no ingress and a NetworkPolicy that admits only
> forum-bff / admin-bff / the sync Job. It is still wrapped in the fail-closed JSON
> error envelope.

Config is read from env first, then the phpBB config table, then a default — **no
secret is committed**. See `.env.example`.

| Env var | Config-table key | Default |
|---|---|---|
| `HUGMESH_JWKS_URI` (required) | `hugmesh_forumapi_jwks_uri` | none |
| `HUGMESH_JWT_ISSUER` (required) | `hugmesh_forumapi_issuer` | none (all tokens rejected) |
| `HUGMESH_JWT_AUDIENCE` | `hugmesh_forumapi_audience` | empty (no audience check) |
| `HUGMESH_JWT_LEEWAY` | `hugmesh_forumapi_leeway` | `30` seconds |
| `HUGMESH_DEFAULT_FORUM_ID` | `hugmesh_forumapi_default_forum_id` | `2` (used when `CreateTopic.categoryId` is 0 or absent) |

---

## phpBB mechanics

- **Casdoor sub → phpBB user_id** via the side table `phpbb_hugmesh_casdoor_map`
  (migration `migrations/m1_initial_schema.php`). `POST /users/{sub}/ensure` (and a lazy
  resolve before any acts-as-user write) calls `user_add()` keyed by the sub and inserts
  the mapping row.
- **Acts-as-user posting** uses `submit_post()` with the mapped poster's `user_id`. To
  attribute every internal code path (last-post bookkeeping, notifications) to that user,
  the posting service briefly impersonates the target's row in `$user->data` around the
  call and restores it afterwards (`posting_service::as_user()`).
- **Moderation** (`hide`/`delete`) uses the `content.visibility` service
  (`set_post_visibility` / `set_topic_visibility` with `ITEM_DELETED` / `ITEM_APPROVED`);
  `lock` sets `topic_status` to locked (there is no unlock action). `hide` and `delete`
  do the same thing: both soft-delete the topic.
- **Group sync** (`PUT /users/{sub}/groups`) is a **declarative converge**: the user ends
  up in exactly the target managed group (`ADMINISTRATORS` / `GLOBAL_MODERATORS` /
  `REGISTERED`) and is removed from the other managed groups (`group_user_add` /
  `group_user_del`). Non-managed memberships are untouched. A valid `group` in the body
  (`ADMINISTRATORS`, `GLOBAL_MODERATORS`, `REGISTERED` or `NONE`) wins. Otherwise the
  target comes from `roles[]`: `ADMIN` gives `ADMINISTRATORS`; `MOD` with `FORUM` gives
  `GLOBAL_MODERATORS`; `FORUM` alone gives `REGISTERED`; anything else gives `NONE`.
  `REGISTERED` membership is never removed.
- **Bans** (`POST`/`DELETE /admin/users/{sub}/ban`): the sub must already be mapped (404
  otherwise). A ban upserts `phpbb_banlist`, changes `user_type`, drops non-`REGISTERED`
  groups and kills the user's sessions. An unban removes the entry, restores
  `user_type = 0`, ensures `REGISTERED` and kills stale sessions.
- **Reads** query phpBB tables via the injected DBAL connection and map to the DTOs;
  stats are `COUNT` queries (`source = "live"`).
- **Sticker tokens**: `:snap-sticker:<slug>:` in stored post text is rendered server-side
  (`text/sticker_tokens.php`) into a same-origin `<img class="forum-sticker">` tag before
  `Reply.body` / `QueueItem.excerpt` are returned — so those two JSON fields can contain
  embedded HTML, not just plain text. `event/main_listener.php` also hooks phpBB's own
  s9e text-formatter (`core.text_formatter_s9e_configure_after`) so the same token
  grammar renders identically if phpBB's own pipeline ever displays these posts.

---

## Install

### Baked image (deployment)

The `Dockerfile` builds the phpBB image: official phpBB 3.3 (`PHPBB_VERSION`, default
3.3.11) on `php:8.1-apache`, this extension at `ext/hugmesh/forumapi/`, `composer install`
for `firebase/php-jwt`, and an entrypoint that regenerates `config.php` from env on every
boot and enables the extension via `phpbbcli` (idempotent). `docker-compose.yml` tags it
`hugmesh-systems/phpbb:dev`.

**How the cluster finds the image.** The manifest
`hugmesh-generator specs/cluster/manifests/forum/phpbb.yaml` (namespace `hugmesh-forum`)
names the image with the placeholder `hugmesh/phpbb:dev` in both the `bootstrap`
initContainer and the `phpbb` container. The kustomization next to it
(`images:`) rewrites that to `registry.hugmesh.net/hugmesh/hugmesh-phpbb:main`, pulled with
the `harbor-registry` secret. The manifest already sets `PHPBB_EXTENSION=hugmesh/forumapi`,
`HUGMESH_JWKS_URI` and `HUGMESH_JWT_ISSUER`, runs `runAsNonRoot` as UID 33 with
`capabilities.drop: ["ALL"]`, and probes `/api/v1/hugmesh/health`. The Service maps port
80 to the named container port 8080.

This repo does not push to Harbor. `.gitlab-ci.yml` pushes `$CI_REGISTRY_IMAGE:<tag>` and
`:dev` to the GitLab registry. The manual-only `.github/workflows/release.yml` pushes
`ghcr.io/hugmesh/hugmesh-forum-api:<sha>` and `:dev`.

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
document root (the `Dockerfile` does). Without it only `X-HugMesh-JWT` arrives and the
dual-header agreement check is silently off.

---

## How the forum-bff turns this on

The forum-bff `PhpbbApiClient` / `PhpbbWebClientConfig` are gated by `phpbb.enabled`
(env `PHPBB_ENABLED`, default `false` in `application.yml`). While off, every extension
call returns a typed degraded/empty result (the client maps writes to 202-pending) and
the forum boots clean against a stub. Turning it on is **config-only**:

```yaml
# forum-bff env
PHPBB_ENABLED: "true"
PHPBB_API_BASE_URL: "http://phpbb.hugmesh-forum.svc.cluster.local:80"
```

`PHPBB_API_BASE_URL` already defaults to that value. The generator's
`specs/cluster/manifests/forum-bff-api/deployment.yaml` sets `PHPBB_ENABLED: "true"` (and
`EXTERNAL_FORUM_ENABLED: "true"`).

The client forwards the user's Casdoor JWT (Bearer + `X-HugMesh-JWT`) on every
call; this extension validates it and re-derives authority. On success it returns real
bodies (and real 4xx/5xx on failure).

---

## Develop / validate

```bash
composer install
composer validate --no-check-publish
find . -path ./vendor -prune -o -name '*.php' -print | xargs -n1 php -l   # php -l everything
vendor/bin/phpunit                                                        # unit suite (tests/*_test.php)
```

The **unit suite needs no live phpBB**. It has ten files in `tests/`. The JWT/JWKS auth
chain (`tests/jwt_verifier_test.php`) covers: valid token accepted; bad-signature /
expired / wrong-issuer / wrong-audience rejected; authority re-derived from
`roles`/`permissions`/`isAdmin`; reserved-prefix injection dropped; body-supplied
`authorSubject` ignored; dual-header agreement; ownership/role gates. The other files
cover the controller error envelope, `/health`, the `as_user()` identity restore, group
convergence, ban/unban, and sticker-token rendering.

**Live phpBB smoke test** (the part unit tests cannot cover — real `submit_post()` /
`content_visibility` / `user_add` against a real schema). The harness is
**cluster-shaped**: Postgres 16 (the same dbms the cluster deployment runs) and a fully
**headless** bring-up — the phpBB web installer is unreachable by design (the image's
Apache lockdown denies every `.php` except `app.php`, and the entrypoint removes
`install/` before serving):

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

CI is GitLab (`.gitlab-ci.yml`): `php -l` → `composer validate` → `phpunit` → **`build:image`**
(bakes + pushes the custom phpBB image; default branch / tags only, from the runner tagged
`hugmesh-x86, privileged`). The only GitHub workflow is `.github/workflows/release.yml`.
It is `workflow_dispatch` only, runs the same three checks and pushes the image to GHCR.

---

## Go-live status (honest)

**Live-proven** (against real **phpBB 3.3.11**):

- The custom image **builds** (official phpBB 3.3.11 on `php:8.1-apache` + this extension at
  `ext/hugmesh/forumapi/` + `firebase/php-jwt`), and the `build:image` CI stage pushes it.
- The extension **enables** under the de-hyphenated name `hugmesh/forumapi` (the hyphenated
  package would not enable at all — see the composer-name note above).
- The schema **migration runs** — the `phpbb_hugmesh_casdoor_map` table is created.
- The routes **register and match** — the full authed contract round-trip
  (acts-as-user authorship, moderation visibility, group sync, ownership/role gates) plus
  every auth negative passed a docker-compose smoke (30/30) against a real install. That
  full round-trip was proven on the **pre-migration MySQL-based harness**; since the local
  harness moved to the cluster-shaped Postgres setup (PR #12), only the shallow checks have
  been re-confirmed there (`/health` 200, `/stats` 401 fail-closed, board-lockdown 403,
  restart-idempotent schema detection) — the deep round-trip has not yet been re-run
  against Postgres, which is part of the gap called out below.

**NOT yet live-proven:** end-to-end authed HTTP posting **through the route on a
cluster-shaped (pgsql, init→main split) harness**. The bootstrap/lockdown concerns were
resolved in the entrypoint rewrite (PR #7): the entrypoint regenerates `config.php` from
env every boot, removes `install/` before serving, runs Apache rootless on port 8080, and
includes the pgsql driver. The remaining live-prove gap is end-to-end authed HTTP posting
on the cluster-shaped pgsql harness; this is a kubeadm/generator follow-up, not an
extension bug.

**Generator state:**

- The image name is not drift. `phpbb.yaml` uses the placeholder `hugmesh/phpbb:dev` on
  purpose and the kustomization `images:` entry rewrites it (see Install). Nothing needs
  repointing to `hugmesh-systems/phpbb:dev`. What this repo does not show is which job
  pushes `registry.hugmesh.net/hugmesh/hugmesh-phpbb:main`.
- `PHPBB_EXTENSION`, `HUGMESH_JWKS_URI` and `HUGMESH_JWT_ISSUER` are already correct in
  `phpbb.yaml`. `PHPBB_ENABLED` is already `"true"` in the forum-bff deployment.
- Keep the board locked down via the image-level Apache lockdown / NetworkPolicy, not the
  board-disable flag.
- The pod `securityContext` (`runAsNonRoot` + `drop: ["ALL"]`) is **resolved**: Apache now
  binds port 8080 (non-privileged), the image defaults to `USER 33:33` at build time, and
  the two-container harness confirmed `runAsNonRoot` + `capabilities.drop:[ALL]` works. The
  Service maps port 80 → targetPort 8080.

This extension is the previously-"unbuilt external contract" the forum-bff
(`hugmesh-forum-bff-api`) `PhpbbApiClient` was written against — now built.

---

## License

GPL-2.0-only (phpBB extension convention).
