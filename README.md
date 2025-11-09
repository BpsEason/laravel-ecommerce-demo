好的，這是一個為您的 `laravel-ecommerce-demo` 專案準備的 `README.md` 文件，重點強調了主從分離的實現，並包含了您提到的所有關鍵點。

---

# laravel-ecommerce-demo

![Laravel Ecommerce Demo](https://via.placeholder.com/1200x400.png?text=Laravel+Ecommerce+Demo)

這是一個基於 Laravel 11 的電商應用程式演示專案，採用 Docker Compose 進行容器化部署。它展示了如何實作資料庫主從分離（讀寫分離）以優化應用程式的擴展性和性能，同時利用 Redis 進行緩存、Session 和隊列管理，並通過 CDN 加速靜態資源的交付。

## 目錄

*   [功能特色](#功能特色)
*   [技術棧](#技術棧)
*   [架構概覽](#架構概覽)
*   [快速開始](#快速開始)
    *   [環境要求](#環境要求)
    *   [專案設置](#專案設置)
    *   [本地服務啟動](#本地服務啟動)
    *   [Postman Collection](#postman-collection)
*   [主從分離 (Read/Write Splitting) 配置](#主從分離-read/write-splitting-配置)
    *   [AWS RDS 與 Read Replica 考量](#aws-rds-與-read-replica-考量)
    *   [Laravel 配置 (`config/database.php`)](#laravel-配置-config/database.php)
    *   [Sticky Connections](#sticky-connections)
*   [Redis 配置](#redis-配置)
*   [靜態資源 CDN (AWS CloudFront)](#靜態資源-cdn-aws-cloudfront)
*   [Docker Compose 服務](#docker-compose-服務)
*   [GitHub Actions CI](#github-actions-ci)
*   [本地測試指令](#本地測試指令)
*   [未來改進](#未來改進)
*   [貢獻](#貢獻)
*   [許可證](#許可證)

## 功能特色

*   用戶認證與授權 (Laravel Breeze 或 Sanctum)
*   產品列表與詳情頁
*   購物車功能
*   訂單管理
*   資料庫主從分離（讀寫分離）實作
*   使用 Redis 進行緩存、Session 和隊列管理
*   靜態資源 CDN 加速
*   容器化部署 (Docker Compose)
*   持續整合 (GitHub Actions CI)

## 技術棧

*   **後端框架:** Laravel 11 (PHP 8.3)
*   **資料庫:** MySQL 8.0 (支援主從分離，可與 AWS RDS 無縫對接)
*   **緩存/Session/隊列:** Redis 7 (可選集成 Laravel Horizon)
*   **Web 伺服器:** Nginx
*   **容器化:** Docker Compose
*   **前端:** HTML, CSS, JavaScript (可能結合 Vue.js/React.js 或 Livewire，待定)
*   **CI/CD:** GitHub Actions

## 架構概覽

```
+---------------------+
|      Client         |
+----------+----------+
           | HTTP(S)
+----------V----------+
|      CloudFront     |
| (Static Assets CDN) |
+----------+----------+
           | HTTP(S)
+----------V----------+
|       Nginx         |
|  (Reverse Proxy,    |
|   Static Files)     |
+----------+----------+
           | FastCGI
+----------V----------+       +-------------------+
|      PHP-FPM        |<----->|       Redis       |
| (Laravel Application)|       | (Cache, Session,  |
+----------+----------+       |      Queue)       |
           | DB Queries       +-------------------+
+----------V----------+
|  Laravel Read/Write |
|     Splitter        |
+----------+----------+
   | (Write)    | (Read)
   V            V
+--------+   +----------+
|  MySQL |   |  MySQL   |
| (Master)|   | (Replica)|
+--------+   +----------+
```

## 快速開始

### 環境要求

*   Git
*   Docker Desktop (包含 Docker Engine 和 Docker Compose)
*   PHP 8.3 (僅用於在主機上執行 `composer` 或 `artisan` 命令，非必要，容器內已包含)

### 專案設置

1.  **克隆專案:**
    ```bash
    git clone https://github.com/your-username/laravel-ecommerce-demo.git
    cd laravel-ecommerce-demo
    ```

2.  **創建 `.env` 文件:**
    ```bash
    cp .env.example .env
    ```
    打開 `.env` 文件，更新 `APP_KEY` 和資料庫憑證。你可以使用 `php artisan key:generate` 命令在本地生成 APP_KEY (如果你本地有 PHP)。

    ```bash
    # 如果你本地沒有 PHP，可以先啟動 app 服務再執行
    # docker compose run --rm artisan key:generate
    ```

### 本地服務啟動

1.  **構建並啟動 Docker 服務:**
    ```bash
    docker compose up -d --build
    ```
    這會啟動 `app` (PHP-FPM), `nginx`, `db` (MySQL), `redis` 和 `worker` (Laravel Queue Worker) 服務。

2.  **執行資料庫遷移和填充 (Seeding):**
    ```bash
    docker compose run --rm artisan migrate --seed
    ```

3.  **訪問應用程式:**
    打開你的瀏覽器，訪問 `http://localhost`。

### Postman Collection

專案包含一個 Postman Collection (`postman/laravel-ecommerce-demo.json`)，用於測試 API 端點。

1.  導入此 JSON 文件到 Postman。
2.  設置一個環境變數，例如 `base_url` 為 `http://localhost`。
3.  你可以開始測試用戶認證、產品查詢、購物車操作等。

## 主從分離 (Read/Write Splitting) 配置

資料庫主從分離是一種常見的性能優化策略，它將讀取操作分配到一個或多個只讀副本 (Read Replicas)，而寫入操作則發送到主資料庫 (Master)。這有助於提高資料庫的吞吐量和可用性。

### AWS RDS 與 Read Replica 考量

在 AWS RDS 環境中，啟用 Read Replica 非常方便。一個常見的部署模式是：
*   **Master 資料庫:** AWS RDS MySQL Instance
*   **Read Replica 資料庫:** 一個或多個基於 Master 的 Read Replica Instance。這些 Replica 可以位於不同的可用區 (Availability Zone) 以提高容錯性。

在這種場景下，你需要為 Master 和每個 Read Replica 獲取其各自的端點 (Endpoint)。

### Laravel 配置 (`config/database.php`)

Laravel 內建支援資料庫主從分離。我們將在 `config/database.php` 中配置 `mysql` 連接，使其指向不同的讀寫資料庫。

```php
// config/database.php

'mysql' => [
    'driver' => 'mysql',
    'url' => env('DATABASE_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],

    // 主從分離配置
    'read' => [
        'host' => [
            env('DB_READ_HOST_1', '127.0.0.1'), // 第一個 Read Replica
            // env('DB_READ_HOST_2', '127.0.0.1'), // 可選：第二個 Read Replica
        ],
        'port' => env('DB_READ_PORT', '3306'),
        'database' => env('DB_READ_DATABASE', env('DB_DATABASE', 'laravel')),
        'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
    ],
    'write' => [
        'host' => env('DB_WRITE_HOST', env('DB_HOST', '127.0.0.1')), // 主資料庫
        'port' => env('DB_WRITE_PORT', '3306'),
        'database' => env('DB_WRITE_DATABASE', env('DB_DATABASE', 'laravel')),
        'username' => env('DB_WRITE_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('DB_WRITE_PASSWORD', env('DB_PASSWORD', '')),
    ],
    'sticky' => true, // 啟用 sticky connection
],
```

**`.env` 配置示例:**

```dotenv
# ... 其他配置

# 主資料庫 (Write Connection)
DB_WRITE_HOST=db # Docker Compose 服務名稱，或 AWS RDS Master Endpoint
DB_WRITE_PORT=3306
DB_WRITE_DATABASE=laravel
DB_WRITE_USERNAME=laravel_user
DB_WRITE_PASSWORD=password

# 只讀副本 (Read Connection)
# 在本地 Docker Compose 環境下，可以指向同一個 DB 服務進行模擬
# 或者當你在 AWS RDS 時，替換為你的 Read Replica Endpoint
DB_READ_HOST_1=db # Docker Compose 服務名稱，或 AWS RDS Read Replica Endpoint 1
DB_READ_PORT=3306
DB_READ_DATABASE=laravel
DB_READ_USERNAME=laravel_user
DB_READ_PASSWORD=password

# 如果有多個 Read Replica，可以添加更多 DB_READ_HOST_X
# DB_READ_HOST_2=your-replica-endpoint-2.rds.amazonaws.com
```

**如何運作:**

*   **讀取操作 (SELECT):** Laravel 會自動從 `read` 配置中隨機選擇一個資料庫連接執行。
*   **寫入操作 (INSERT, UPDATE, DELETE):** Laravel 會自動使用 `write` 配置中的資料庫連接。

### Sticky Connections

在 `config/database.php` 中設置 `'sticky' => true`，這是一個非常重要的功能，尤其是在主從分離的環境下。

**什麼是 Sticky Connections?**

當你在一個請求中執行了寫入操作（例如創建一個用戶），這個寫入操作會發生在主資料庫上。如果緊接著又執行了一個讀取操作（例如獲取剛創建的用戶的資料），由於資料同步需要時間，如果這個讀取操作被路由到只讀副本，很可能會讀不到最新的資料。

**Sticky Connections 的作用:**

當 `sticky` 設置為 `true` 時，Laravel 會在當前請求中，如果檢測到有任何寫入操作發生，則當前請求後續的所有資料庫操作（無論是讀還是寫）都會被強制路由到主資料庫 (write connection)，直到該請求結束。這確保了在單一請求的生命週期內，應用程式總能讀取到最新的資料，避免了主從同步延遲可能導致的資料不一致問題。

## Redis 配置

Redis 被廣泛用於 Laravel 應用程式的性能優化。

在 `.env` 中配置 Redis：

```dotenv
REDIS_HOST=redis # Docker Compose 服務名稱
REDIS_PASSWORD=null # 如果 Redis 沒有密碼，設為 null 或空字串
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

*   **緩存 (Cache):** 減少對資料庫的頻繁查詢，提高響應速度。
*   **Session:** 將 Session 儲存在 Redis 中，方便多個應用程式實例共享 Session (水平擴展)。
*   **隊列 (Queue):** 用於處理耗時的背景任務 (如發送郵件、處理圖片等)，避免阻塞 Web 請求。`worker` 服務會消費這些隊列任務。

## 靜態資源 CDN (AWS CloudFront)

為提高網站加載速度和用戶體驗，建議將靜態資源 (CSS, JavaScript, 圖片) 部署到 CDN。

**AWS CloudFront 配置說明:**

1.  **S3 Bucket:** 創建一個 AWS S3 Bucket，用於存放你的靜態資源 (例如 `your-app-static-assets-bucket`)。
2.  **上傳靜態資源:** 在部署流程中，將你的 `public` 目錄下的所有靜態文件同步到這個 S3 Bucket。
3.  **CloudFront Distribution:**
    *   創建一個新的 CloudFront Distribution。
    *   **Origin Domain Name:** 指向你的 S3 Bucket 的靜態網站託管端點 (例如 `your-app-static-assets-bucket.s3-website-ap-southeast-1.amazonaws.com`)。
    *   **Viewer Protocol Policy:** 建議設置為 `Redirect HTTP to HTTPS`。
    *   **Cache Behavior:** 配置不同的緩存行為，例如對 `.css`, `.js`, `.png`, `.jpg` 等文件設置較長的緩存時間。
    *   **Custom CNAMEs (Optional):** 如果你希望使用自定義域名 (例如 `static.yourdomain.com`)，可以在這裡添加，並配置 DNS 解析。
4.  **Laravel `config/app.php`:**
    在 `config/app.php` 中設定 `asset_url`：

    ```php
    // config/app.php
    'asset_url' => env('ASSET_URL', null),
    ```
    在 `.env` 中指定 CloudFront Domain：

    ```dotenv
    ASSET_URL=https://your-cloudfront-distribution-id.cloudfront.net
    # 或者如果你使用了自定義域名
    # ASSET_URL=https://static.yourdomain.com
    ```
    之後，在 Blade 模板中使用 `asset()` 輔助函數時，Laravel 會自動生成 CDN URL。

    ```blade
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    <img src="{{ asset('images/logo.png') }}" alt="Logo">
    ```

## Docker Compose 服務

本專案使用 Docker Compose 管理以下服務：

*   **`app` (PHP-FPM):** Laravel 應用程式的 PHP-FPM 進程。
*   **`nginx`:** 作為反向代理，將 Web 請求轉發給 PHP-FPM，並處理靜態資源。
*   **`db` (MySQL):** 資料庫服務，在本地環境中作為主資料庫和讀寫分離的模擬目標。
*   **`redis`:** 緩存、Session 和隊列服務。
*   **`worker`:** Laravel Queue Worker，用於處理背景任務。
*   **(可選) `composer`:** 臨時容器，用於執行 Composer 命令 (例如 `composer install`)。
*   **(可選) `artisan`:** 臨時容器，用於執行 Laravel Artisan 命令 (例如 `php artisan migrate`)。

## GitHub Actions CI

專案配置了 GitHub Actions 進行持續整合。每次 `push` 到 `main` 分支或創建 Pull Request 時，都會自動觸發以下流程：

*   **Linting/Static Analysis:** 檢查代碼風格和潛在問題 (例如 PHPStan, Laravel Pint)。
*   **單元測試/整合測試:** 運行 PHPUnit 測試。
*   **Docker Build:** 驗證 `Dockerfile` 和 `docker-compose.yml` 可以成功構建映像。

這有助於確保代碼品質和專案的穩定性。

## 本地測試指令

*   **啟動服務:** `docker compose up -d`
*   **停止服務:** `docker compose down`
*   **重啟服務:** `docker compose restart`
*   **查看日誌:** `docker compose logs -f`
*   **執行 Artisan 命令:** `docker compose run --rm artisan [command]` (例如 `docker compose run --rm artisan cache:clear`)
*   **執行 Composer 命令:** `docker compose run --rm composer [command]` (例如 `docker compose run --rm composer update`)
*   **運行 PHPUnit 測試:** `docker compose run --rm app php artisan test` (或者 `docker compose run --rm app vendor/bin/phpunit`)
*   **查看 PHP-FPM 服務日誌:** `docker compose logs -f app`
*   **查看 Nginx 服務日誌:** `docker compose logs -f nginx`
*   **進入 `app` 容器:** `docker compose exec app bash`

## 未來改進

*   集成 Laravel Breeze 或 Sanctum 實現完整的用戶認證流程。
*   實現完整的產品 CRUD 操作和分類系統。
*   添加單元測試和功能測試。
*   集成 Vue.js / React.js 或 Livewire 實現響應式前端。
*   實現支付網關集成。
*   部署到 AWS ECS/EKS 或其他雲平台。
*   使用 Laravel Horizon 監控隊列。

## 貢獻

歡迎提交 Pull Requests 或報告問題。請確保你的代碼遵循 PSR-12 標準，並包含相關測試。

## 許可證

這個專案遵循 MIT 許可證。

---
