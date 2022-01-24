#!make

keys:
	openssl req -x509 -out data/local/ssl/localhost.crt -keyout data/local/ssl/localhost.key \
		-newkey rsa:2048 -nodes -sha256 \
		-subj '/CN=localhost' -extensions EXT -config <( \
			printf "[dn]\nCN=localhost\n[req]\ndistinguished_name = dn\n[EXT]\nsubjectAltName=DNS:localhost\nkeyUsage=digitalSignature\nextendedKeyUsage=serverAuth" \
		)
	sudo chmod ug+r data/local/ssl/*
