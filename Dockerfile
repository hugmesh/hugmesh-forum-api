# Snapdragon phpBB image with the snapdragon/json-api headless extension baked in.
#
# Produces the image the deployment references as `snapdragon-systems/phpbb:dev`:
#   - official phpBB 3.3 on php:8.1-apache
#   - this extension copied to ext/snapdragon/jsonapi/
#   - composer install for the extension's runtime dep (firebase/php-jwt)
#   - the extension enabled (offline, via phpbbcli) at build time when a DB is present,
#     otherwise enabled on first boot by the entrypoint.
#
# The deployment (snapdragon-generator specs/cluster/manifests/forum/phpbb.yaml) sets:
#   PHPBB_EXTENSION=snapdragon/jsonapi
#   SNAPDRAGON_JWKS_URI / SNAPDRAGON_JWT_ISSUER  (Casdoor JWKS issuer ref; via Secret)
# and locks down native login/ACP. Routes are served under app.php/api/v1/snapdragon/**.

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

# Copy the extension into ext/snapdragon/jsonapi/ (NOTE the de-hyphenated package dir).
COPY . /opt/phpbb/ext/snapdragon/jsonapi/

# Resolve the extension's runtime dependency (firebase/php-jwt) into its own vendor dir
# (phpBB autoloads ext/<vendor>/<pkg>/vendor/). Dev deps excluded from the image.
WORKDIR /opt/phpbb/ext/snapdragon/jsonapi
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer config --no-plugins allow-plugins.composer/installers true \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf /opt/phpbb/ext/snapdragon/jsonapi/.git

# ---- stage 2: runtime ------------------------------------------------------
FROM php:8.1-apache AS runtime

LABEL org.opencontainers.image.title="snapdragon-systems/phpbb" \
      org.opencontainers.image.description="phpBB 3.3 with the snapdragon/json-api headless extension" \
      org.opencontainers.image.source="https://github.com/Snapdragon-Systems/snapdragon-json-api"

# phpBB runtime PHP extensions.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libzip-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Pass the HTTP Authorization header through to PHP. With mod_php, apache strips it by
# default (and phpBB ships the HTTP_AUTHORIZATION .htaccess rewrite commented out), so the
# `Authorization: Bearer <jwt>` header never reaches the extension — leaving ONLY the
# X-Snapdragon-JWT header to authenticate and silently disabling the dual-header
# agreement (header-confusion) check. CGIPassAuth makes the Bearer header available so the
# extension sees both headers and enforces that they match.
RUN printf '<Directory /var/www/html>\n    CGIPassAuth On\n</Directory>\n' \
        > /etc/apache2/conf-available/snapdragon-passauth.conf \
    && a2enconf snapdragon-passauth

ENV PHPBB_EXTENSION=snapdragon/jsonapi \
    APACHE_DOCUMENT_ROOT=/var/www/html

COPY --from=build /opt/phpbb/ /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/snapdragon-entrypoint.sh

RUN chown -R www-data:www-data /var/www/html \
    && chmod +x /usr/local/bin/snapdragon-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/snapdragon-entrypoint.sh"]
CMD ["apache2-foreground"]
