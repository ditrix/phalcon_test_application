FROM php:7.2-apache-buster

# Repoint Debian Buster to archive and disable date check to keep builds reproducible
RUN sed -i 's#http://deb.debian.org/debian#http://archive.debian.org/debian#g; s#http://security.debian.org/debian-security#http://archive.debian.org/debian-security#g' /etc/apt/sources.list && \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until && \
    apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        git \
        gcc \
        make \
        re2c \
        autoconf \
        libpcre3-dev \
        zlib1g-dev \
        libzip-dev \
        unzip \
        libpng-dev \
        libpng16-16 \
        libjpeg62-turbo \
        libjpeg62-turbo-dev \
        libfreetype6 \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        libssl-dev \
        curl \
        ca-certificates \
        libonig-dev \
        libgmp-dev \
        libmemcached-dev \
        zlib1g-dev \
        pkg-config \
        libcurl4-openssl-dev \
        default-mysql-client && \
    docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ && \
    docker-php-ext-install pdo_mysql mysqli mbstring gd zip intl opcache && \
    a2enmod rewrite && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /tmp && git clone --depth 1 --branch v3.4.5 https://github.com/phalcon/cphalcon.git && \
    cd /tmp/cphalcon/build/php7/64bits && \
    phpize && \
    ./configure --enable-phalcon && \
    make -j"$(nproc)" && \
    make install && \
    echo 'extension=phalcon.so' > /usr/local/etc/php/conf.d/docker-php-ext-phalcon.ini && \
    sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf && \
    sed -i '/<Directory \/var\/www\/html\/public>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf && \
    apt-get purge -y --auto-remove \
        git \
        gcc \
        make \
        re2c \
        autoconf \
        libpcre3-dev \
        zlib1g-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        libssl-dev \
        libonig-dev \
        libgmp-dev \
        libmemcached-dev \
        pkg-config \
        libcurl4-openssl-dev && \
    rm -rf /tmp/cphalcon && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY app/ /var/www/html/

EXPOSE 80
CMD ["apache2-foreground"]
