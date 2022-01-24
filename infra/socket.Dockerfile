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
	ln -s /app/public /var/www/html;

RUN ln -s /app/vendor/seanmorris/ids/source/Idilic/idilic /usr/local/bin/idilic

RUN docker-php-ext-install pdo pdo_mysql pcntl

CMD ["idilic", "server"]
