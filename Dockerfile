# Välj en officiell PHP-bild med Apache
FROM php:8.2-apache

# Installera nödvändiga beroenden
RUN apt-get update && apt-get install -y \
    unzip \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Installera Composer (PHP:s pakethanterare)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Aktivera Apache mod_rewrite (viktigt för Slim)
RUN a2enmod rewrite

# Sätt arbetskatalogen i containern
WORKDIR /var/www/html

# Kopiera alla filer från projektet till containern
COPY . .

# Installera PHP-bibliotek via Composer
RUN composer install

# Exponera port 80 för webbservern
EXPOSE 80

# Starta Apache när containern körs
CMD ["apache2-foreground"]

