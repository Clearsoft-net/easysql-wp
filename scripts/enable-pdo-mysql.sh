#!/usr/bin/env bash
# Installs the pdo_mysql extension in the wp-env containers (web + cli).
#
# The official `wordpress:php*` and `wordpress:cli-php*` images ship with mysqli
# only (PDO is present but the MySQL driver is not compiled), while the EasySQL
# connector package talks to the site database through PDO. This is idempotent
# and safe to run after every `wp-env start` — the containers are rebuilt on
# each start, so it MUST run after the containers come up.
set -euo pipefail

# Resolve the running WordPress and CLI containers.
CONTAINERS="$(podman ps --format '{{.Names}}' | grep -E '^wp-env.*_(wordpress|cli)_1$' || true)"
if [ -z "$CONTAINERS" ]; then
	echo "ERROR: WordPress wp-env containers not found." >&2
	exit 1
fi

for CONTAINER in $CONTAINERS; do
	if podman exec "$CONTAINER" php -m | grep -q '^pdo_mysql$'; then
		echo "pdo_mysql already available in $CONTAINER."
		continue
	fi

	echo "Compiling pdo_mysql inside $CONTAINER (one-time per container)..."
	podman exec -u 0 "$CONTAINER" sh -c 'docker-php-source extract && docker-php-ext-install -j"$(nproc)" pdo_mysql >/dev/null'

	# Reload Apache so mod_php picks up the new extension (web container only).
	podman exec "$CONTAINER" sh -c 'command -v apache2 >/dev/null 2>&1 && { service apache2 reload >/dev/null 2>&1 || true; } || true'

	echo "pdo_mysql installed in $CONTAINER."
done
