# HugMesh phpBB image with the hugmesh/forum-api headless extension baked in.
#
# Produces the image the deployment references as `hugmesh-systems/phpbb:dev`:
#   - official phpBB 3.3 on php:8.1-apache
#   - this extension copied to ext/hugmesh/forumapi/
#   - composer install for the extension's runtime dep (firebase/php-jwt)
#   - the extension enabled (offline, via phpbbcli) at build time when a DB is present,
#     otherwise enabled on first boot by the entrypoint.
#
# The deployment (hugmesh-generator specs/cluster/manifests/forum/phpbb.yaml) sets:
#   PHPBB_EXTENSION=hugmesh/forumapi
#   HUGMESH_JWKS_URI / HUGMESH_JWT_ISSUER  (Casdoor JWKS issuer ref; via Secret)
# and locks down native login/ACP. Routes are served under app.php/api/v1/hugmesh/**.

# ---- stage 1: fetch phpBB + composer deps ---------------------------------
FROM php:8.1-cli AS build

ARG PHPBB_VERSION=3.3.11
ENV PHPBB_VERSION=${PHPBB_VERSION}

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip curl git ca-certificates libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Composer (installed via the official installer; pinned to major 2).
RUN curl -sS https://getcomposer.org/installer | php -- --2 --install-dir=/usr/local/bin --filename=composer \
    && composer --version

# Fetch the official phpBB release.
WORKDIR /opt
RUN curl -fsSL -o phpbb.zip "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.zip" \
    && unzip -q phpbb.zip \
    && mv phpBB3 phpbb \
    && rm phpbb.zip

# Copy the extension into ext/hugmesh/forumapi/ (NOTE the de-hyphenated package dir).
COPY . /opt/phpbb/ext/hugmesh/forumapi/

# Resolve the extension's runtime dependency (firebase/php-jwt) into its own vendor dir
# (phpBB autoloads ext/<vendor>/<pkg>/vendor/). Dev deps excluded from the image.
WORKDIR /opt/phpbb/ext/hugmesh/forumapi
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer config --no-plugins allow-plugins.composer/installers true \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf /opt/phpbb/ext/hugmesh/forumapi/.git

# ---- stage 2: runtime ------------------------------------------------------
FROM php:8.1-apache AS runtime

LABEL org.opencontainers.image.title="hugmesh-systems/phpbb" \
      org.opencontainers.image.description="phpBB 3.3 with the hugmesh/forum-api headless extension" \
      org.opencontainers.image.source="https://github.com/HugMesh/hugmesh-forum-api"

# phpBB runtime PHP extensions.
# NOTE: pgsql is REQUIRED — the hugmesh-forum deployment runs phpBB against Postgres
# ($dbms = phpbb\db\driver\postgres). Without the pgsql PHP extension phpBB cannot
# connect to phpbb-db and the headless install/boot fails. libpq-dev provides its headers.
# mysqli is kept for operators running a MANUAL MySQL-backed install only — the local
# docker-compose smoke is now cluster-shaped Postgres (the entrypoint's DDL-once schema
# probe is pgsql, so the headless install path requires Postgres).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libpq-dev default-mysql-client postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pgsql zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Pass the HTTP Authorization header through to PHP. With mod_php, apache strips it by
# default (and phpBB ships the HTTP_AUTHORIZATION .htaccess rewrite commented out), so the
# `Authorization: Bearer <jwt>` header never reaches the extension — leaving ONLY the
# X-HugMesh-JWT header to authenticate and silently disabling the dual-header
# agreement (header-confusion) check. CGIPassAuth makes the Bearer header available so the
# extension sees both headers and enforces that they match.
RUN printf '<Directory /var/www/html>\n    CGIPassAuth On\n</Directory>\n' \
        > /etc/apache2/conf-available/hugmesh-passauth.conf \
    && a2enconf hugmesh-passauth

# Headless lockdown (image-level, deterministic): the ONLY PHP entrypoint that may run is
# app.php (the Symfony front controller that dispatches the hugmesh/forumapi routes).
# Every other phpBB PHP script — index.php, ucp.php, posting.php, viewforum.php, the whole
# adm/ ACP, etc. — is DENIED at the web-server layer, so a board/login/ACP page can never
# be served even on the pod loopback. This is the in-image complement to the deployment's
# NetworkPolicy + no-ingress (defense in depth) and replaces phpBB's own board_disable
# config, which CANNOT be used here: phpBB enforces board_disable in $user->setup() inside
# app.php BEFORE the extension dispatches, so it would also kill /api/v1/hugmesh/**.
# The .htaccess front-controller rewrite (!-f !-d -> app.php) still routes the clean
# /api/v1/hugmesh/** URLs into app.php, which is allow-listed below.
RUN printf '%s\n' \
        '<FilesMatch "\.php$">' \
        '    Require all denied' \
        '</FilesMatch>' \
        '<Files "app.php">' \
        '    Require all granted' \
        '</Files>' \
        > /etc/apache2/conf-available/hugmesh-lockdown.conf \
    && a2enconf hugmesh-lockdown

# ROOTLESS: bind apache on the NON-PRIVILEGED port 8080 (a non-root user cannot bind
# <1024). The pod runs runAsNonRoot + runAsUser 33 + capabilities.drop:["ALL"] with NO
# CAP_NET_BIND_SERVICE, so port 80 is impossible; 8080 lets www-data (UID 33) bind it.
# Rewrite Listen 80 -> 8080 and the default vhost *:80 -> *:8080. The Service maps its
# port 80 -> targetPort 8080 so the in-cluster ClusterIP contract (forum-bff baseUrl
# ...svc.cluster.local:80) is unchanged.
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's!\*:80>!*:8080>!' /etc/apache2/sites-available/000-default.conf \
    && grep -q '^Listen 8080$' /etc/apache2/ports.conf \
    && grep -q '\*:8080>' /etc/apache2/sites-available/000-default.conf

ENV PHPBB_EXTENSION=hugmesh/forumapi \
    APACHE_DOCUMENT_ROOT=/var/www/html

COPY --from=build /opt/phpbb/ /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/hugmesh-entrypoint.sh

# ROOTLESS ownership: everything apache writes at RUNTIME is owned by the runtime user
# (www-data == UID/GID 33) at BUILD time, so under runAsNonRoot the per-boot entrypoint
# never needs to chown (its chown is already guarded to root-only and simply no-ops).
#   * the doc root (so config.php — regenerated from env each boot — is writable, and the
#     phpBB compiled cache/ + store/ are writable);
#   * the apache pid/log/lock dirs (so the master process can start as www-data: the base
#     image already makes /var/run/apache2 + /var/log/apache2 www-data-owned, but we set
#     them explicitly to be robust);
#   * the front-controller cache is pre-purged so it rebuilds cleanly as UID 33.
RUN chown -R www-data:www-data /var/www/html /var/run/apache2 /var/log/apache2 \
    && rm -rf /var/www/html/cache/production \
    && chmod -R u+rwX /var/www/html/cache /var/www/html/store \
    && chmod +x /usr/local/bin/hugmesh-entrypoint.sh

# Run as the non-root runtime user by default (the pod also pins runAsUser 33). apache's
# master process then starts as www-data and binds 8080 without any privilege.
USER 33:33

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/hugmesh-entrypoint.sh"]
CMD ["apache2-foreground"]
