#!/bin/sh
set -eu

export APP_ENV="${APP_ENV:-production}"
export APP_DEBUG="${APP_DEBUG:-false}"

: "${APP_KEY:?Set a unique Laravel APP_KEY in Render environment variables.}"
: "${APP_URL:?Set APP_URL to the public Render service URL.}"
: "${FRONTEND_URL:?Set FRONTEND_URL to the deployed Vercel origin.}"
: "${DB_CONNECTION:=mysql}"
: "${DB_HOST:?Set the Aiven MySQL host.}"
: "${DB_PORT:?Set the Aiven MySQL port.}"
: "${DB_DATABASE:?Set the Aiven MySQL database.}"
: "${DB_USERNAME:?Set the Aiven MySQL username.}"
: "${DB_PASSWORD:?Set the Aiven MySQL password.}"
: "${MYSQL_ATTR_SSL_CA:?Set the Aiven CA certificate path in Render.}"
: "${CLOUDINARY_URL:?Set CLOUDINARY_URL for media storage in Render.}"

if [ ! -r "$MYSQL_ATTR_SSL_CA" ]; then
    echo "Aiven CA certificate is not readable at MYSQL_ATTR_SSL_CA." >&2
    exit 1
fi

php artisan config:clear
php artisan migrate --force --no-interaction

exec apache2-foreground
