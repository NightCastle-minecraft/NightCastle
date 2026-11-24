FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && a2dismod -f autoindex

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sitegate.ini

WORKDIR /var/www/html

# Composer stage: зависимости кешируются отдельным слоем
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY public ./public
COPY src ./src
COPY templates ./templates
COPY migrations ./migrations
COPY bin ./bin
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p public/uploads \
    && chown -R www-data:www-data public/uploads

EXPOSE 80
