FROM php:7.4-cli

# Update Apt
RUN apt update && apt -y upgrade

# Install Tools
RUN apt install wget

# Install PHP Extensions
RUN apt-get install -y \
        libzip-dev \
        zip \
  && docker-php-ext-install zip

# Install Composer
RUN EXPECTED_SIGNATURE=$(wget -q -O - https://composer.github.io/installer.sig) \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('SHA384', 'composer-setup.php') === '$EXPECTED_SIGNATURE') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" \
    && php composer-setup.php --install-dir=/usr/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"


COPY / /

CMD ["composer"]