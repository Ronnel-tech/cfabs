FROM php:8.3-apache-bookworm
ENV APP_ENV=production PORT=10000 COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates unzip libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libxml2-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && for extension in pdo_mysql gd mbstring zip curl opcache dom simplexml xml xmlreader xmlwriter; do \
         php -r 'exit(extension_loaded($argv[1] === "opcache" ? "Zend OPcache" : $argv[1]) ? 0 : 1);' "$extension" \
         || docker-php-ext-install -j"$(nproc)" "$extension" || exit 1; \
       done \
    && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev
COPY . .
COPY deployment/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deployment/php.ini /usr/local/etc/php/conf.d/cfabs.ini
RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && sed -i 's/\r$//' deployment/start.sh && chmod +x deployment/start.sh \
    && mkdir -p runtime/cache runtime/logs runtime/session \
    && chown -R www-data:www-data runtime
EXPOSE 10000
CMD ["/var/www/html/deployment/start.sh"]
