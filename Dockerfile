FROM php:8.2-cli

WORKDIR /app

COPY . /app

CMD php -d display_errors=1 -S 0.0.0.0:$PORTPORT
