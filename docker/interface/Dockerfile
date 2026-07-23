FROM php:8.4-fpm

# Instalar Nginx + dependências
RUN apt-get update && apt-get install -y \
    apt-utils \
    libzip-dev \
    zlib1g-dev \
    git \
    unzip \
    nginx \
    cron \
    librdkafka-dev \
    libgd-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions (gd com webp — o app converte uploads para .webp via imagewebp())
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql zip gd mbstring pcntl

# Redis
RUN pecl install redis && docker-php-ext-enable redis

# RdKafka
RUN pecl install rdkafka && docker-php-ext-enable rdkafka

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Nginx config — remove default, add ours
RUN rm /etc/nginx/sites-enabled/default
COPY default.conf /etc/nginx/sites-available/dotly.conf
RUN ln -s /etc/nginx/sites-available/dotly.conf /etc/nginx/sites-enabled/dotly.conf

# PHP config
COPY php.ini /usr/local/etc/php/php.ini

# PHP-FPM pool override — permite getenv() acessar vars do Nginx
COPY zz-dotly.conf /usr/local/etc/php-fpm.d/zz-dotly.conf

# Diretórios lib
RUN mkdir -p /var/www/app/site/app/inc/lib \
    && mkdir -p /var/www/app/manager/app/inc/lib

# Permissões
RUN chown -R www-data:www-data /var/www/app
RUN mkdir -p /var/log/nginx && chown -R www-data:www-data /var/log/nginx

# Crontab + entrypoint
COPY crontab /etc/cron.txt
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
