FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql

RUN a2enmod rewrite

WORKDIR /var/www/html
COPY backend/public/ /var/www/html/

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80
