FROM php:8.3-apache

# --- System packages + PHP extensions osTicket requires/recommends
# (see README.md: mysqli required; ctype, fileinfo, gd, gettext, iconv,
# intl, json, mbstring, opcache, phar, xml, xml-dom, zip recommended)
#
# NOTE: the `imap` extension (used by osTicket for polling mailboxes via
# IMAP/POP as an alternative to email piping) is intentionally omitted.
# Debian trixie dropped the libc-client-dev package it depends on, and
# this deployment only needs the web-based "Open a Ticket" flow. Revisit
# if IMAP/POP mail fetching is ever required.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libicu-dev \
        libzip-dev \
        libxml2-dev \
        libonig-dev \
        default-mysql-client \
        gettext \
        curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        gd \
        intl \
        mbstring \
        zip \
        gettext \
        opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu opcache \
    && a2enmod rewrite

COPY docker/php-osticket.ini /usr/local/etc/php/conf.d/zz-osticket.ini
COPY docker/apache-osticket.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# App code (build context is the repo root, see docker-compose.yml)
COPY . /var/www/html

# docker/ only needed for the COPY steps above - don't ship deployment
# tooling inside the webroot alongside the app.
RUN rm -rf /var/www/html/docker \
    && chown -R www-data:www-data /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
