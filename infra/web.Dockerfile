FROM php:8.1-apache
MAINTAINER Sean Morris <sean@seanmorr.is>
SHELL ["bash", "-euxo", "pipefail", "-c"]

RUN apt update;\
	apt-get install -y --no-install-recommends\
		libyaml-dev\
		openssl\
		wget;

RUN wget http://pear.php.net/go-pear.phar;\
	wget https://github.com/pear/pearweb_phars/blob/master/go-pear.phar?raw=true -qO- > go-pear.phar;\
	php go-pear.phar;\
	pecl install yaml -y;\
	docker-php-ext-enable yaml;\
	apt-get remove -y wget;

RUN rm -rfv /var/www/html;\
	ln -s /app/public /var/www/html;\
	a2enmod rewrite rewrite ssl http2;\
	docker-php-ext-install pdo pdo_mysql pcntl;

COPY infra/apache-ssl.conf /etc/apache2/conf-enabled/ssl.conf

# RUN apt-get install -y sendmail
# RUN apt-get update && \
# 	apt-get install -y ssmtp && \
# 	apt-get clean && \
# 	echo "FromLineOverride=YES" >> /etc/ssmtp/ssmtp.conf && \
# 	echo 'sendmail_path = "/usr/sbin/ssmtp -t"' > /usr/local/etc/php/conf.d/mail.ini

RUN a2dismod alias -f

CMD ["apache2-foreground"]
