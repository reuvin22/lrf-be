FROM php:8.5-fpm

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libzip-dev \
    && docker-php-ext-install pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]