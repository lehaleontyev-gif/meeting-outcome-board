FROM php:8.2-apache

# Включаем rewrite (нужно для роутинга /api/* на index.php)
RUN a2enmod rewrite

# Кладем публичную папку backend в DocumentRoot
WORKDIR /var/www/html
COPY backend/public/ /var/www/html/

# Разрешаем .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80
