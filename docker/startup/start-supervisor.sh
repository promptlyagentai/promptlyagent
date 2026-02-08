#!/bin/bash

# Custom entrypoint for Nginx + PHP-FPM container
# This bypasses the default Laravel Sail startup to directly use supervisor

# Ensure log directories exist
mkdir -p /var/log/supervisor /var/log/nginx /var/log/php8.4-fpm

# Dynamically adjust sail user UID/GID to match host user for proper file permissions
# This matches Laravel Sail's approach: delete ubuntu user at build time, adjust sail UID at runtime

# Adjust sail user UID to match host user if WWWUSER is provided
if [ ! -z "$WWWUSER" ]; then
    usermod -u $WWWUSER sail
fi

# Adjust sail group GID to match host group if WWWGROUP is provided
if [ ! -z "$WWWGROUP" ]; then
    groupmod -g $WWWGROUP sail
fi

# Use sail user and group (now with correct UID/GID)
WWWUSERNAME="sail"
WWWGROUPNAME="sail"

# Set default pod identification values if not provided (for local development)
POD_NAME=${POD_NAME:-"local-dev"}
POD_NAMESPACE=${POD_NAMESPACE:-"local"}
POD_IP=${POD_IP:-"127.0.0.1"}

# Set default Reverb host for nginx proxy (reverb for Sail, promptlyagent-reverb for k3s)
NGINX_REVERB_HOST=${NGINX_REVERB_HOST:-"reverb"}

# Substitute environment variables in configuration files
sed "s/\${WWWUSER}/${WWWUSERNAME}/g; s/\${WWWGROUP}/${WWWGROUPNAME}/g" /etc/nginx/nginx.conf > /tmp/nginx.conf && mv /tmp/nginx.conf /etc/nginx/nginx.conf
sed "s/\${WWWUSER}/${WWWUSERNAME}/g; s/\${WWWGROUP}/${WWWGROUPNAME}/g" /etc/php/8.4/fpm/pool.d/www.conf > /tmp/www.conf && mv /tmp/www.conf /etc/php/8.4/fpm/pool.d/www.conf

# Substitute pod identification and reverb host variables in nginx site config
sed "s/\$pod_name/${POD_NAME}/g; s/\$pod_namespace/${POD_NAMESPACE}/g; s/\$pod_ip/${POD_IP}/g; s/\$reverb_host/${NGINX_REVERB_HOST}/g" /etc/nginx/sites-available/default > /tmp/default && mv /tmp/default /etc/nginx/sites-available/default

echo "DEBUG: Nginx config first line:"
head -1 /etc/nginx/nginx.conf

echo "DEBUG: PHP-FPM pool config first few lines:"
head -3 /etc/php/8.4/fpm/pool.d/www.conf

# Fix storage, bootstrap/cache, and vendor permissions after UID/GID changes
# These directories need writable access by the sail user for application operation
chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/vendor
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/vendor

# Clear any stale config cache from build time and rebuild for production
# Config/route caches are filesystem-based (bootstrap/cache/), not Redis
# This ensures optimization caches match the deployed code after rebuild/redeploy
php artisan config:clear 2>/dev/null || true
php artisan config:cache 2>/dev/null || true

# Run optimization caches in background to avoid blocking web server startup
# These may hang if they try to initialize external connections (e.g., MCP providers)
# Using timeout to prevent indefinite hangs
(
    timeout 30 php artisan route:cache 2>/dev/null || echo "Route cache timed out or failed (non-critical)"
    timeout 30 php artisan view:cache 2>/dev/null || echo "View cache timed out or failed (non-critical)"
) &

# Optional production optimizations (uncomment if needed):
# php artisan event:cache 2>/dev/null || true

# Start supervisor to manage Nginx and PHP-FPM
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf