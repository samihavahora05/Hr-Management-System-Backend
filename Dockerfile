FROM php:8.3-fpm-alpine

# Install system dependencies & PHP extensions required for Laravel, PostgreSQL, and Redis
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev

RUN docker-php-ext-install pdo pdo_pgsql mbstring zip exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader || true

EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000
