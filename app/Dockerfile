FROM php:8.3-apache

# --- System dependencies ------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates \
        libpq-dev libxml2-dev libonig-dev libzip-dev libgd-dev libicu-dev \
        libxslt-dev libcurl4-openssl-dev \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ------------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg 2>/dev/null || true \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli pdo pdo_mysql pgsql pdo_pgsql \
        curl xml mbstring zip gd bcmath intl soap sockets

# --- Composer + Node -----------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# --- Apache config -------------------------------------------------------------
RUN a2enmod rewrite headers
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# --- FacturaScripts ------------------------------------------------------------
WORKDIR /var/www/html
RUN git clone --depth 1 https://github.com/NeoRazorX/facturascripts.git facturas
WORKDIR /var/www/html/facturas

# PHP dependencies (composer)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Frontend dependencies (npm)
RUN npm install --omit=dev

# Enable FS .htaccess routing
RUN mv htaccess-sample .htaccess

# Permissions
RUN chown -R www-data:www-data /var/www/html/facturas

# --- Entrypoint ----------------------------------------------------------------
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
