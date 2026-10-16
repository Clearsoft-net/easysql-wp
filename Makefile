.PHONY: cli clean clean-tmp db-down db-up help install install-wp lint phpcs phpcbf start stop test test-integration

help:
	@grep -E '^[a-zA-Z_-]+:' Makefile | sort | sed 's/://' | while read t; do printf "  make %-20s %s\n" "$$t" "`grep -A1 '^'"$$t"':' Makefile | tail -1 | sed 's/# //'`"; done

clean:
	rm -rf vendor/
	rm -f composer.lock

cli:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env $(CONFIG_FLAG) run cli wp $(ARGS)

install:
	composer install

WP_URL ?= http://localhost:$(PORT)
WP_ADMIN_USER ?= admin
WP_ADMIN_PASSWORD ?= password
WP_ADMIN_EMAIL ?= admin@example.com

install-wp:
	@PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env $(CONFIG_FLAG) run cli wp core is-installed && echo "WordPress já instalado." || \
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env $(CONFIG_FLAG) run cli wp core install \
		--url=$(WP_URL) \
		--title="EasySQL Dev" \
		--admin_user=$(WP_ADMIN_USER) \
		--admin_password=$(WP_ADMIN_PASSWORD) \
		--admin_email=$(WP_ADMIN_EMAIL) \
		--skip-email

clean-tmp:
	rm -f $(WP_ENV_CONFIG)

db-down:
	podman rm -f easysql-wp-mysql-test
	@rm -rf /tmp/easysql-wp-mysql-sock 2>/dev/null || true

db-up:
	@mkdir -p /tmp/easysql-wp-mysql-sock 2>/dev/null || true
	@chmod 777 /tmp/easysql-wp-mysql-sock 2>/dev/null || true
	@podman start easysql-wp-mysql-test >/dev/null 2>&1 || podman run -d --rm --name easysql-wp-mysql-test -p 3307:3306 -v /tmp/easysql-wp-mysql-sock:/run/mysqld -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wordpress docker.io/library/mysql:8.4 >/dev/null
	@until podman exec easysql-wp-mysql-test mysqladmin ping -h 127.0.0.1 -u root >/dev/null 2>&1; do sleep 1; done
	@echo "MySQL de teste pronto em 127.0.0.1:3307 (socket: /tmp/easysql-wp-mysql-sock/mysqld.sock)"

lint: phpcs

phpcs:
	vendor/bin/phpcs --standard=WordPress src/ easysql-wp.php uninstall.php

phpcbf:
	vendor/bin/phpcbf --standard=WordPress src/ easysql-wp.php uninstall.php

PORT ?= 8888
WP_ENV_CONFIG ?= .wp-env.tmp.json
CONFIG_FLAG = $(if $(wildcard $(WP_ENV_CONFIG)),--config $(WP_ENV_CONFIG),)
EASYSQL_ENDPOINT ?= http://host.containers.internal:8000

start:
	@python3 -c "import json; c=json.load(open('.wp-env.json')); c['config']['EASYSQL_ENDPOINT']='$(EASYSQL_ENDPOINT)'; json.dump(c, open('$(WP_ENV_CONFIG)','w'))"
	PODMAN_SHORTNAME_ALIASING=0 WP_ENV_PORT=$(PORT) npx -y @wordpress/env start --config $(WP_ENV_CONFIG)
	@bash scripts/enable-rewrites.sh
	@bash scripts/enable-pdo-mysql.sh

stop:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env $(CONFIG_FLAG) stop
	@rm -f $(WP_ENV_CONFIG)

test:
	vendor/bin/phpunit

test-integration: db-up
	EASYSQL_TEST_DB_HOST=127.0.0.1:3307 EASYSQL_TEST_DB_SOCKET=/tmp/easysql-wp-mysql-sock/mysqld.sock vendor/bin/phpunit --testsuite integration
