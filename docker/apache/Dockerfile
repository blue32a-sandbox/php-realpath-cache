FROM php:8.5-apache

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-experiment.ini
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=755 docker/switch.sh /usr/local/bin/switch

ENTRYPOINT ["sh", "/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
