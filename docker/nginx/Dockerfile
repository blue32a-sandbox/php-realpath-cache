FROM php:8.5-fpm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-experiment.ini
COPY docker/nginx/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=755 docker/switch.sh /usr/local/bin/switch

ENTRYPOINT ["sh", "/usr/local/bin/entrypoint.sh"]
CMD ["sh", "-c", "php-fpm -D && exec nginx -g 'daemon off;'"]
