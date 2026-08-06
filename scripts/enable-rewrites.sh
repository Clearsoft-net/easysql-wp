#!/usr/bin/env bash
# Enables Apache pretty-permalink rewrites in the wp-env WordPress container.
#
# The official `wordpress:php*` image ships with `AllowOverride None`, which
# causes every rewrite rule in `.htaccess` to be ignored. As a result the
# REST API (`/wp-json/`) returns 404 and the EasySQL admin shows
# "Could not load connector." — even though the backend is healthy.
#
# This is idempotent and safe to run after every `wp-env start`. The container
# is rebuilt on each start, so this MUST run after the containers come up.
set -euo pipefail

# Resolve the running WordPress container.
CONTAINER="$(podman ps --format '{{.Names}}' | grep -E '^wp-env.*_wordpress_1$' | head -n1)"
if [ -z "$CONTAINER" ]; then
	echo "ERROR: WordPress wp-env container not found." >&2
	exit 1
fi

# 1) Let .htaccess override per-directory settings inside the web root.
podman exec "$CONTAINER" bash -lc 'mkdir -p /etc/apache2/conf-available && printf "<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n" > /etc/apache2/conf-available/easysql-rewrite.conf && a2enconf easysql-rewrite >/dev/null 2>&1 || true' >/dev/null

# 2) Make sure a WordPress .htaccess exists (the wp-env volume starts empty).
podman exec "$CONTAINER" bash -lc 'if [ ! -f /var/www/html/.htaccess ]; then cat > /var/www/html/.htaccess <<EOF
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
fi' >/dev/null

# 3) Reload Apache so the new conf takes effect.
podman exec "$CONTAINER" bash -lc 'service apache2 reload >/dev/null 2>&1 || true' >/dev/null

echo "WordPress rewrite rules enabled in $CONTAINER."
