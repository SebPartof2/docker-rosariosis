# Dockerfile for RosarioSIS
# https://www.rosariosis.org/
# Best Dockerfile practices: https://docs.docker.com/develop/develop-images/dockerfile_best-practices/
# Lint Dockerfile: https://hadolint.github.io/hadolint/

# https://hub.docker.com/_/php/tags?name=apache
FROM php:8.1-apache-bookworm

LABEL maintainer="François Jacquet <francoisjacquet@users.noreply.github.com>"

ENV DBTYPE=postgresql \
    PGHOST=db \
    PGUSER=rosario \
    PGPASSWORD=rosariopwd \
    PGDATABASE=rosariosis \
    PGPORT=5432 \
    ROSARIOSIS_YEAR=2026 \
    ROSARIOSIS_LANG='en_US'

# Install postgresql-client, sendmail, nano editor, locales
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-client sendmail nano locales;

# Download and install wkhtmltopdf (avoid direct installation via apt, saves 115M :)
RUN curl -L https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_amd64.deb \
        --output wkhtmltox_0.12.6.1-3.bookworm_amd64.deb; \
    apt-get install -y --no-install-recommends ./wkhtmltox_0.12.6.1-3.bookworm_amd64.deb; \
    rm wkhtmltox_0.12.6.1-3.bookworm_amd64.deb;

# Set the SHELL option -o pipefail before RUN with a pipe in it
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Install PHP extensions build dependencies
# Note: $savedAptMark var must be assigned & used in the same RUN command.
RUN savedAptMark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends \
        libicu-dev libpq-dev libjpeg-dev libpng-dev libwebp-dev libldap2-dev libzip-dev libonig-dev; \
    \
    # Install PHP extensions (curl, mbstring & xml are already included).
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    debMultiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
    docker-php-ext-configure ldap --with-libdir="lib/$debMultiarch"; \
    docker-php-ext-install -j$(nproc) gd pgsql pdo_pgsql gettext intl zip ldap; \
    \
    # Reset apt-mark's "manual" list so that "purge --auto-remove" will remove all build dependencies
    extDir="$(php -r 'echo ini_get("extension_dir");')"; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    # Remove compilers & dev libraries
    apt-mark auto autoconf dpkg-dev g++ gcc libc6-dev make; \
    # `readlink -f` is required on bookworm: ldd reports /lib/<triplet>/... but
    # dpkg records /usr/lib/<triplet>/... after usrmerge, so dpkg-query -S finds
    # nothing, marks nothing manual, and --auto-remove strips libwebp7/libzip4.
    ldd "$extDir"/*.so \
        | awk '/=>/ { print $3 }' \
        | sort -u \
        | xargs -r readlink -f \
        | sort -u \
        | xargs -r dpkg-query -S 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -rt apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*;

# Belt and braces: restore any runtime library the purge above still managed to
# strip, then fail the build loudly if an extension does not actually load.
RUN apt-get update; \
    apt-get install -y --no-install-recommends \
        libwebp7 libzip4 libjpeg62-turbo libpng16-16 libfreetype6 libicu72 libpq5; \
    rm -rf /var/lib/apt/lists/*; \
    if ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so | grep 'not found'; then \
        echo "ERROR: unresolved shared library above" >&2; exit 1; \
    fi; \
    for ext in gd zip pgsql pdo_pgsql gettext intl ldap; do \
        php -m | grep -qx "$ext" || { echo "ERROR: $ext failed to load" >&2; exit 1; }; \
    done

# Set recommended PHP.ini settings
RUN { \
    echo 'max_execution_time = 240'; \
    echo 'max_input_vars = 4000'; \
    echo 'memory_limit = 512M'; \
    echo 'session.gc_maxlifetime = 3600'; \
    echo 'upload_max_filesize = 50M'; \
    echo 'post_max_size = 51M'; \
} > /usr/local/etc/php/conf.d/rosariosis-recommended.ini
# Set recommended PHP error logging
RUN { \
# https://www.php.net/manual/en/errorfunc.constants.php
    echo 'error_reporting = E_ERROR | E_WARNING | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_RECOVERABLE_ERROR'; \
    echo 'display_errors = Off'; \
    echo 'display_startup_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
    echo 'log_errors_max_len = 1024'; \
    echo 'ignore_repeated_errors = On'; \
    echo 'ignore_repeated_source = Off'; \
    echo 'html_errors = Off'; \
} > /usr/local/etc/php/conf.d/error-logging.ini

# Use php.ini-production
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Enable Apache mod_rewrite for .htaccess
RUN a2enmod rewrite

# Download and extract rosariosis
ENV ROSARIOSIS_VERSION 'v12.9.3'

# Set the SHELL option -o pipefail before RUN with a pipe in it
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN mkdir /usr/src/rosariosis && \
    curl -L https://gitlab.com/francoisjacquet/rosariosis/-/archive/${ROSARIOSIS_VERSION}/rosariosis-${ROSARIOSIS_VERSION}.tar.gz \
    | tar xz --strip-components=1 -C /usr/src/rosariosis

# --- Bundled add-ons -------------------------------------------------------
# Baked into /usr/src/rosariosis so /init re-applies them to /var/www/html on
# every container start. They therefore survive redeploys and cannot be lost
# by an accidental delete in the volume.
#
# Neither add-on publishes tags, so these pin a branch. Replace with commit
# SHAs once you have ones you trust:
#   docker build --build-arg REST_API_REF=<sha> --build-arg STUDENTS_IMPORT_REF=<sha> .
ARG REST_API_REF=master
ARG STUDENTS_IMPORT_REF=master

# REST_API is a *plugin* -> plugins/
RUN dest=/usr/src/rosariosis/plugins/REST_API; \
    mkdir -p "$dest"; \
    curl -fsSL "https://gitlab.com/francoisjacquet/REST_API/-/archive/${REST_API_REF}/REST_API-${REST_API_REF}.tar.gz" \
    | tar xz --strip-components=1 -C "$dest"; \
    echo "--- REST_API contents ---"; ls -la "$dest"; \
    test -n "$(ls -A "$dest")" || { echo "ERROR: REST_API extracted empty" >&2; exit 1; }

# Students_Import is a *module* -> modules/
RUN dest=/usr/src/rosariosis/modules/Students_Import; \
    mkdir -p "$dest"; \
    curl -fsSL "https://gitlab.com/francoisjacquet/Students_Import/-/archive/${STUDENTS_IMPORT_REF}/Students_Import-${STUDENTS_IMPORT_REF}.tar.gz" \
    | tar xz --strip-components=1 -C "$dest"; \
    echo "--- Students_Import contents ---"; ls -la "$dest"; \
    test -n "$(ls -A "$dest")" || { echo "ERROR: Students_Import extracted empty" >&2; exit 1; }
# ---------------------------------------------------------------------------

# Copy our configuration files.
COPY conf/config.inc.php /usr/src/rosariosis/config.inc.php
COPY bin/init /init

EXPOSE 80

ENTRYPOINT ["/init"]
CMD ["apache2-foreground"]
