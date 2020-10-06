build:
	cd infra/ \
	&& docker-compose build

start:
	cd infra/ \
	&& docker-compose up -d

start-fg:
	cd infra/ \
	&& docker-compose up

stop:
	cd infra/ \
	&& docker-compose down

restart:
	cd infra/ \
	&& docker-compose down \
	&& docker-compose up -d

push:
	cd infra/ \
	&& docker-compose push

pull:
	cd infra/ \
	&& docker-compose pull
