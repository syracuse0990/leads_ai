#!/bin/bash
# VPS Deployment Script for leads_ai
# Run as root or with sudo on Ubuntu 22.04/24.04

set -e

APP_DIR="/var/www/leads_ai"
APP_USER="www-data"

echo "=== leads_ai VPS Deployment ==="

# 1. System packages
echo "[1/8] Installing system packages..."
apt update && apt install -y \
    nginx \
    postgresql postgresql-contrib \
    php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath \
    supervisor \
    tesseract-ocr \
    certbot python3-certbot-nginx \
    unzip git

# 2. Composer
echo "[2/8] Installing Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# 3. Node.js (for building frontend)
echo "[3/8] Installing Node.js..."
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt install -y nodejs
fi

# 4. PostgreSQL setup
echo "[4/8] Setting up PostgreSQL..."
sudo -u postgres psql -c "CREATE DATABASE leads_ai;" 2>/dev/null || true
sudo -u postgres psql -c "CREATE USER leads_ai_user WITH PASSWORD 'CHANGE_THIS_PASSWORD';" 2>/dev/null || true
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE leads_ai TO leads_ai_user;" 2>/dev/null || true

# 5. Application setup
echo "[5/8] Setting up application..."
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env  # Edit .env before running migrations!
# php artisan key:generate
# php artisan migrate --force

# 6. Permissions
echo "[6/8] Setting permissions..."
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# 7. Config files
echo "[7/8] Installing config files..."
cp "$APP_DIR/deployment/nginx.conf" /etc/nginx/sites-available/leads_ai
ln -sf /etc/nginx/sites-available/leads_ai /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

cp "$APP_DIR/deployment/supervisor.conf" /etc/supervisor/conf.d/leads_ai.conf
cp "$APP_DIR/deployment/php-fpm.conf" /etc/php/8.3/fpm/pool.d/leads_ai.conf
rm -f /etc/php/8.3/fpm/pool.d/www.conf  # Remove default pool

# 8. Start services
echo "[8/8] Starting services..."
systemctl restart php8.3-fpm
supervisorctl reread
supervisorctl update
supervisorctl start leads_ai-workers:*

echo ""
echo "=== Deployment complete ==="
echo "Next steps:"
echo "  1. Edit $APP_DIR/.env with your database and API credentials"
echo "  2. Run: php artisan key:generate"
echo "  3. Run: php artisan migrate --force"
echo "  4. Run: certbot --nginx -d your-domain.com"
echo "  5. Update server_name in /etc/nginx/sites-available/leads_ai"
echo ""
echo "Manage workers:"
echo "  supervisorctl status leads_ai-workers:*"
echo "  supervisorctl restart leads_ai-workers:*"
