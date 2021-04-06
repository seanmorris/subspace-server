FROM php:7.4-apache
MAINTAINER Sean Morris <sean@seanmorr.is>

RUN rm -rfv /var/www/html && ln -s /app/public /var/www/html
RUN ln -s /app/vendor/seanmorris/ids/source/Idilic/idilic /usr/local/bin/idilic
RUN docker-php-ext-install pdo pdo_mysql

CMD ["idilic", "server"]
