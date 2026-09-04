.PHONY: cli clean help install install-wp lint phpcs phpcbf start stop clean-tmp test

help:
	@grep -E '^[a-zA-Z_-]+:' Makefile | sort | sed 's/://' | while read t; do printf "  make %-20s %s\n" "$$t" "`grep -A1 '^'"$$t"':' Makefile | tail -1 | sed 's/# //'`"; done

clean:
	rm -rf vendor/
	rm -f composer.lock

cli:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env run cli wp $(ARGS)

install:
	composer install

WP_URL ?= http://localhost:$(PORT)
WP_ADMIN_USER ?= admin
WP_ADMIN_PASSWORD ?= password
WP_ADMIN_EMAIL ?= admin@example.com

install-wp:
	@PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env run cli wp core is-installed && echo "WordPress já instalado." || \
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env run cli wp core install \
		--url=$(WP_URL) \
		--title="EasySQL Dev" \
		--admin_user=$(WP_ADMIN_USER) \
		--admin_password=$(WP_ADMIN_PASSWORD) \
		--admin_email=$(WP_ADMIN_EMAIL) \
		--skip-email

clean-tmp:
	rm -f .wp-env.tmp.json

lint: phpcs

phpcs:
	vendor/bin/phpcs --standard=WordPress src/ easysql-wp.php uninstall.php

phpcbf:
	vendor/bin/phpcbf --standard=WordPress src/ easysql-wp.php uninstall.php

PORT ?= 8888
EASYSQL_ENDPOINT ?= http://host.containers.internal:8000/v1

start:
	@python3 -c "import json; c=json.load(open('.wp-env.json')); c['config']['EASYSQL_ENDPOINT']='$(EASYSQL_ENDPOINT)'; json.dump(c, open('.wp-env.tmp.json','w'))"
	PODMAN_SHORTNAME_ALIASING=0 WP_ENV_PORT=$(PORT) npx -y @wordpress/env start --config .wp-env.tmp.json
	@bash scripts/enable-rewrites.sh

stop:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env stop
	@rm -f .wp-env.tmp.json

test:
	vendor/bin/phpunit
