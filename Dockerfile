FROM php:8.2-apache

# Aktifkan mod_rewrite
RUN a2enmod rewrite

# Aktifkan mod_ssl
RUN a2enmod ssl

# Install ekstensi PHP
RUN docker-php-ext-install pdo_mysql mysqli

# Copy konfigurasi SSL
COPY ./ssl/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
RUN a2ensite default-ssl

# Izinkan .htaccess override
RUN echo '<Directory /var/www/html/>\n\tAllowOverride All\n</Directory>' >> /etc/apache2/apache2.conf
