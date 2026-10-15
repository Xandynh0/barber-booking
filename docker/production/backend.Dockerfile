# Production image of the Laravel API: PHP-FPM, no dev dependencies, no
# source bind mount, no .env inside. Built from the repository root:
#   docker compose -f docker-compose.prod.yml --env-file .env.production build
# The development image (backend/Dockerfile, `php artisan serve`) is separate.
FROM php:8.4-fpm-alpine

# fcgi provides cgi-fcgi for the healthcheck (FPM ping, see php-fpm.conf).
RUN apk add --no-cache \
        fcgi \
        icu-libs \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install pdo_mysql bcmath intl opcache \
    && apk del .build-deps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/production/php.ini $PHP_INI_DIR/conf.d/zz-barber-booking.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-barber-booking.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ ./
# dump-autoload also runs package:discover (post-autoload-dump), so the
# package manifest only lists the production packages.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && rm -f /usr/bin/composer \
    && rm -rf tests \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/production/entrypoint.sh /usr/local/bin/barber-entrypoint
RUN chmod 0755 /usr/local/bin/barber-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["barber-entrypoint"]
CMD ["php-fpm"]
