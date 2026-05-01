FROM php:8.3-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage \
    && chown -R www-data:www-data bootstrap/cache \
    && chmod -R 755 storage \
    && chmod -R 755 bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]

