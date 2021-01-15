FROM php:7.4-fpm

COPY php.ini /usr/local/etc/php/conf.d/zz-shipstream.ini

RUN curl https://raw.githubusercontent.com/colinmollenhour/modman/master/modman -o /usr/local/bin/modman && chmod +x /usr/local/bin/modman

# Allow XDebug to be added at build time
ARG XDEBUG
RUN if test -n "$XDEBUG"; then \
      pecl install xdebug && docker-php-ext-enable xdebug && rm -rf /tmp/* \
    ;fi
ENV XDEBUG_CONFIG="idekey=PHPSTORM client_port=9000 client_host=host.docker.internal"
ENV XDEBUG_MODE=develop,debug