FROM php:7.4-apache
MAINTAINER Sean Morris <sean@seanmorr.is>

RUN rm -rfv /var/www/html \
	&& ln -s /app/public /var/www/html \
	&& docker-php-ext-install pdo pdo_mysql
# RUN rm -rfv /var/www/html \
# 	&& ln -s /app/public /var/www/html \
# 	&& docker-php-ext-install pdo pdo_mysql bcmath

RUN a2enmod rewrite

# RUN apt update
# RUN apt install libxml2-dev libyaml-dev -y
# RUN docker-php-ext-install xml
# RUN docker-php-ext-install xmlrpc

# RUN apt-get install -y sendmail

# RUN apt-get install wget
# RUN wget http://pear.php.net/go-pear.phar
# RUN wget https://github.com/pear/pearweb_phars/blob/master/go-pear.phar?raw=true -qO- > go-pear.phar
# RUN php go-pear.phar

# RUN pecl install yaml -y
# RUN docker-php-ext-enable yaml

# RUN apt-get update && \
# 	apt-get install -y ssmtp && \
# 	apt-get clean && \
# 	echo "FromLineOverride=YES" >> /etc/ssmtp/ssmtp.conf && \
# 	echo 'sendmail_path = "/usr/sbin/ssmtp -t"' > /usr/local/etc/php/conf.d/mail.ini

RUN a2dismod alias -f

CMD ["apache2-foreground"]
