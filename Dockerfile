FROM php:8.2-cli

# Install MySQL PDO driver
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . .

CMD php -S 0.0.0.0:$PORT