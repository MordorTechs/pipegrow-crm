# Use uma imagem base do PHP com FPM
FROM php:8.2-fpm

# Instale dependências do sistema, incluindo Nginx, Git e outras necessárias
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip \
    && docker-php-ext-install pdo pdo_mysql

# Instale o Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Parte padrão para qualquer projeto
# ************** Obrigatorio para todos os containers ****************
COPY nginx/default.conf /etc/nginx/sites-available/default
COPY nginx/default.conf /etc/nginx/sites-enabled/default
# ************** Obrigatorio para todos os containers ****************
COPY certs/fullchain.pem /var/www/certs/fullchain.pem
COPY certs/privkey.pem /var/www/certs/privkey.pem
# ************** Obrigatorio para todos os containers ****************
WORKDIR /var/www
# ************** Obrigatorio para todos os containers ****************
EXPOSE 80
EXPOSE 443
EXPOSE 1443
# ************** Obrigatorio para todos os containers ****************
RUN git config --global --add safe.directory /var/www
# ************** Obrigatorio para todos os containers ****************

COPY ssh/id_rsa /root/.ssh/id_rsa
COPY ssh/id_rsa.pub /root/.ssh/id_rsa.pub
RUN echo "Host github.com\n\tIdentityFile /root/.ssh/id_rsa\n\tStrictHostKeyChecking no\n" >> /root/.ssh/config

# Comando para iniciar o Nginx e o PHP-FPM juntos
CMD service nginx start && php-fpm
