# API de cherryPOS.
#
# El código no se respalda, se reconstruye (docs/OPERACION.md): esta imagen es
# lo que se levanta en un equipo nuevo para volver a vender en menos de 30
# minutos.
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache postgresql-dev icu-dev oniguruma-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl opcache \
    && apk del linux-headers

# BCMath no es opcional: el motor de cálculo lo usa para no tocar coma flotante
# en ningún punto. Sin él, los totales del POS y los del ERP divergen.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

# Configuración de producción de opcache: el servidor del local es modesto y
# cada milisegundo de la caja se nota en la fila.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000
CMD ["php-fpm"]
