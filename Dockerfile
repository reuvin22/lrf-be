FROM php:8.5-cli

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

EXPOSE 8080

CMD php artisan config:cache && \
    php artisan route:cache && \
    (if php artisan migrate --force; then \
        (while true; do php artisan queue:work --tries=2 --timeout=180 --max-time=3300; sleep 15; done &); \
    else \
        echo "Skipping queue worker: migrate failed (DB unreachable?). Uploads needing extraction will stay PROCESSING until this is fixed."; \
    fi) && \
    php artisan serve --host=0.0.0.0 --port=${PORT}