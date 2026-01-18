FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
 && docker-php-ext-install pdo pdo_sqlite \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY . /var/www/html/
WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html