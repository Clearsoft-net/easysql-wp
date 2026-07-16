.PHONY: cli clean help install lint phpcs phpcbf start stop test

help:
	@grep -E '^[a-zA-Z_-]+:' Makefile | sort | sed 's/://' | while read t; do printf "  make %-20s %s\n" "$$t" "`grep -A1 '^'"$$t"':' Makefile | tail -1 | sed 's/# //'`"; done

clean:
	rm -rf vendor/
	rm -f composer.lock

cli:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env run cli wp $(ARGS)

install:
	composer install

lint: phpcs

phpcs:
	vendor/bin/phpcs --standard=WordPress src/ easysql-wp.php uninstall.php

phpcbf:
	vendor/bin/phpcbf --standard=WordPress src/ easysql-wp.php uninstall.php

start:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env start

stop:
	PODMAN_SHORTNAME_ALIASING=0 npx -y @wordpress/env stop

test:
	@echo "No tests defined yet."
