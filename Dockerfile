# Production-style image: the container, the AOP cache, the preload scripts and the OPcache file cache are built at
# image build time. The container runs with PREBUILT_CACHE on a read-only cache and serves its first request hot.
FROM php:8.5-cli

# OPcache is part of PHP 8.5 (before 8.5: RUN docker-php-ext-install opcache)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=prebuilt \
    APP_DEBUG=0
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .

# 1. Code generation and an authoritative class map
RUN php bin/generate.php --if-missing \
 && composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# 2. cache:clear from an empty var/: compiles the container and runs GoAopBundle's cache warmer, which weaves every
#    class of the include paths and writes var/cache/prebuilt/aop/_preload.php. Fails the build if a file can not be
#    woven
RUN rm -rf var && bin/console cache:clear

# 3. The OPcache file cache: GoAopBundle's primer compiles the AOP cache and Composer's class map; the entry points
#    and the compiled container are passed with --entry
COPY docker/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
RUN mkdir -p /var/cache/opcache \
 && php -d opcache.file_cache_only=1 -d opcache.file_cache_read_only=0 -d opcache.preload= \
        bin/console cache:warmup:opcache --file-cache=/var/cache/opcache \
        --entry=public/index.php --entry=config/preload.php \
        $(find var/cache/prebuilt -name '*.php' -not -path '*/aop/*' -printf '--entry=%p ') \
 && chown -R www-data:www-data /var/cache/opcache var \
 && chmod -R a-w var /var/cache/opcache

USER www-data
EXPOSE 8080
# The built-in server keeps the demo self-contained; in production run PHP-FPM with the same php.ini settings
CMD ["php", "-d", "variables_order=EGPCS", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
