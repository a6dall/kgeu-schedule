FROM php:8.3-cli-alpine

# Устанавливаем curl и необходимые расширения
RUN apk add --no-cache curl-dev libcurl \
    && docker-php-ext-install curl

WORKDIR /app

# Копируем проект в образ
COPY . /app

EXPOSE 8000

# Запуск встроенного веб-сервера с роутером server.php
CMD ["php", "-S", "0.0.0.0:8000", "server.php"]
