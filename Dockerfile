FROM alpine:3.16

ENV TZ=Europe/Rome

RUN apk --update --no-cache add \
  php81 \
  php81-common \
  php81-ctype \
  php81-curl \
  php81-fileinfo \
  php81-mbstring \
  php81-zip \
  php81-json \
  php81-phar \
  php81-openssl \
  php81-iconv \
  php81-intl \
  php81-pecl-memcache \
  wget \
  curl \
  git \
  tzdata \
  openssl \
  && \
  ln $(which php81) /usr/local/bin/php \
  && \
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
  && cp /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
  && rm -rf /var/cache/apk/* /root/.composer/cache/*

# fix work iconv library with alphine
RUN apk add --no-cache --repository http://dl-cdn.alpinelinux.org/alpine/edge/community/ --allow-untrusted gnu-libiconv
ENV LD_PRELOAD /usr/lib/preloadable_libiconv.so php

COPY local/conf/php.ini /etc/php81/php.ini

WORKDIR /app

CMD ["php", "-S", "0.0.0.0:80", "-t", "public/"]
