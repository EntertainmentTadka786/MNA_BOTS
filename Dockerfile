FROM php:8.1-apache

# System updates and necessary tools
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions install karo
RUN docker-php-ext-install \
    mysqli \
    pdo \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd

# Apache mod_rewrite enable karo
RUN a2enmod rewrite
RUN a2enmod headers

# Apache configuration update karo
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN echo "AllowEncodedSlashes On" >> /etc/apache2/apache2.conf

# Custom Apache config for PHP apps
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Composer install karo
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Working directory set karo
WORKDIR /var/www/html

# Application files copy karo
COPY . .

# File permissions set karo
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html
RUN chmod 600 *.csv *.json 2>/dev/null || true
RUN chmod 644 *.php

# Environment variables setup (optional)
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data
ENV APACHE_LOG_DIR /var/log/apache2
ENV APACHE_PID_FILE /var/run/apache2.pid
ENV APACHE_RUN_DIR /var/run/apache2
ENV APACHE_LOCK_DIR /var/lock/apache2

# Port expose karo
EXPOSE 80

# Health check add karo
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Apache start karo
CMD ["apache2-foreground"]
