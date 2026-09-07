# Railway deployment image for the OpenCart PoolBuy app.
# Unlike the dev Dockerfile (which downloads stock OpenCart), this bundles THIS
# repo's upload/ tree so the PoolBuy extension and custom theme are included.
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
      unzip \
      curl \
      default-mysql-client \
      libfreetype6-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libzip-dev \
      libcurl4-openssl-dev \
      libwebp-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" gd zip mysqli curl \
  && docker-php-ext-enable gd zip mysqli curl \
  && a2dismod mpm_event mpm_worker 2>/dev/null || true \
  && a2enmod mpm_prefork rewrite \
  && rm -rf /var/lib/apt/lists/*

# App code (the real OpenCart + PoolBuy extension from this repo)
COPY upload/ /var/www/html/
COPY upload/php.ini /usr/local/etc/php/conf.d/opencart.ini
COPY railway/railway_seed.php /var/www/html/railway_seed.php

# Provide the -dist configs (entrypoint overwrites config.php/admin/config.php at boot)
RUN cp -n /var/www/html/config-dist.php /var/www/html/config.php 2>/dev/null || true \
 && cp -n /var/www/html/admin/config-dist.php /var/www/html/admin/config.php 2>/dev/null || true \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html/system/storage

COPY railway/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
