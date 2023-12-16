FROM --platform=$BUILDPLATFORM php:8.2-cli AS builder

ARG BUILD_VERSION=docker

RUN apt-get update \
	&& apt-get -y install wget unzip \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN wget https://raw.githubusercontent.com/composer/getcomposer.org/317db84d632c1a99d8617019ad4b000026bf7d16/web/installer -O - -q | php -- --force --install-dir=/usr/local/bin --filename=composer
RUN php -v

COPY . /usr/src/satis
WORKDIR /usr/src/satis

ENV COMPOSER_ALLOW_SUPERUSER 1

RUN composer install --no-interaction --no-progress --no-scripts --optimize-autoloader --no-dev --ignore-platform-req=ext-zip

RUN ./s3-satis app:build --build-version=${BUILD_VERSION} --ansi -vvv


FROM php:8.2-cli AS runtime

RUN apt-get update \
	&& apt-get -y install libzip-dev \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN docker-php-ext-install zip

COPY --from=builder /usr/src/satis/builds/s3-satis /usr/src/satis/s3-satis
WORKDIR /usr/src/satis

ENV AWS_ACCESS_KEY_ID=""
ENV AWS_SECRET_ACCESS_KEY=""
ENV AWS_DEFAULT_REGION="us-east-1"
ENV AWS_BUCKET=""
ENV AWS_URL=""
ENV AWS_ENDPOINT=""
ENV AWS_USE_PATH_STYLE_ENDPOINT="false"


ENTRYPOINT [ "php", "./s3-satis"]
