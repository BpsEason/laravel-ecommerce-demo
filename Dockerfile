# --- 階段 1: 構建依賴和編譯資產 (build stage) ---
FROM php:8.3-fpm-alpine AS builder

# 設定工作目錄
WORKDIR /app

# 安裝系統依賴 (git, curl, zip, nodejs/npm)
# nodejs 和 npm 僅在構建階段使用，用於處理前端資產 (如 Laravel Mix/Vite)
RUN apk add --no-cache \
    git \
    curl \
    zip \
    nodejs \
    npm \
    # PHP 擴展的構建依賴 (在安裝 GD 擴展後會被移除)
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    # 如果使用 PostgreSQL，請取消註釋 libpq-dev
    # libpq-dev \
    ;

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# 安裝 PHP 擴展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql bcmath \
    # 如果使用 PostgreSQL，請取消註釋 pdo_pgsql
    # && docker-php-ext-install -j$(nproc) pdo_pgsql \
    && pecl install -o -f redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    # 安裝完畢後刪除開發依賴，減少映像大小
    && apk del --no-cache libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    ;

# 複製應用程式代碼到構建階段 (這裡的 /app 是臨時的，最終會複製到 /var/www/html)
COPY . /app

# 在構建階段安裝 Composer 依賴和編譯前端資產
# 注意：這裡應該使用生產環境的安裝方式
# 如果有前端資產，取消註釋以下行
# RUN npm install && npm run build
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && php artisan optimize:clear \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    ;

# --- 階段 2: 最終的生產映像 (production stage) ---
FROM php:8.3-fpm-alpine

# 設定工作目錄
WORKDIR /var/www/html

# 複製 Composer (從 builder 階段)
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# 複製 PHP 擴展 (從 builder 階段)
# 這裡需要手動複製擴展的 .so 文件，因為 docker-php-ext-install 是針對當前映像的。
# 更簡潔的做法是將所有 RUN docker-php-ext-install 指令放在最終映像的 Dockerfile 中，
# 但為了多階段構建，需要確保最終映像包含所需的擴展。
# 實際上，由於 php:8.3-fpm-alpine 已經包含了大部分運行時依賴，
# 重新執行 docker-php-ext-install 可能更簡單，或者直接從 builder 複製整個 /usr/local/lib/php/extensions/no-debug-non-zts-20230901 目錄。
# 為了保持輕量，我們假設 builder 階段的擴展是直接在這個基礎映像上安裝的。
# 最乾淨的做法是將所有 docker-php-ext-install 和 pecl install 放在最終映像中，並在安裝後清理。
# 由於 PHP 基礎映像已經提供了這些工具，我們直接在生產映像中安裝它們。

# 在最終映像中安裝所需的 PHP 擴展
# 注意：這些命令需要確保所有運行時依賴都已安裝
RUN apk add --no-cache \
    # GD 擴展的運行時依賴
    libpng \
    libjpeg-turbo \
    freetype \
    libwebp \
    # 如果使用 PostgreSQL
    # libpq \
    ;

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql bcmath \
    # 如果使用 PostgreSQL，請取消註釋 pdo_pgsql
    # && docker-php-ext-install -j$(nproc) pdo_pgsql \
    && pecl install -o -f redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    ;

# 將自定義的 PHP-FPM 配置檔案複製到容器中
COPY docker/nginx/www.conf /usr/local/etc/php-fpm.d/www.conf

# 配置使用者和群組
ARG PUID=1000
ARG PGID=1000

# 檢查用戶和群組是否存在，避免重複創建導致錯誤
RUN addgroup -g ${PGID} laravel || true \
    && adduser -u ${PUID} -G laravel -s /bin/sh -D laravel || true

# 從 builder 階段複製應用程式代碼
# 這裡只複製生產所需的程式碼，不包含 dev 依賴、node_modules 等
COPY --from=builder --chown=laravel:laravel /app /var/www/html

# 設定檔案權限 (針對生產環境，如果代碼是 COPY 進去的)
# 注意：在生產環境中，`storage` 和 `bootstrap/cache` 必須可寫
RUN chown -R laravel:laravel \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    ;

# 將應用程式用戶設定為容器的默認用戶
USER laravel

# 暴露 PHP-FPM 預設端口 9000
EXPOSE 9000

# 預設啟動 PHP-FPM 服務
# 如果這個 Dockerfile 用於 `worker` 或 `horizon` 服務，其 `command` 會覆蓋這裡的 CMD。
CMD ["php-fpm"]