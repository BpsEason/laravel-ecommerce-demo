#!/bin/sh

# 確保 storage 和 bootstrap/cache 目錄存在且權限正確
# 這對於使用 volume 掛載的開發環境尤為重要
echo "Setting permissions for storage and bootstrap/cache..."
chown -R laravel:laravel /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache

# 執行 Composer install (僅在開發環境需要，生產環境應在 build 階段完成)
# 這裡使用 if 判斷，避免在生產環境不必要地執行
if [ "$APP_ENV" = "local" ]; then
    echo "Running composer install (dev mode)..."
    composer install --ignore-platform-reqs --no-scripts
    echo "Running php artisan optimize:clear..."
    php artisan optimize:clear
fi

# 執行傳遞給 entrypoint 的原始 CMD 命令 (php-fpm)
exec docker-php-entrypoint "$@"