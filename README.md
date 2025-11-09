# laravel-ecommerce-demo

這是一個基於 **Laravel 11** 的電商應用程式演示專案，旨在展示如何構建一個高效能、可擴展的微服務架構電商平台。專案利用 **Docker Compose** 進行容器化部署，並深入探討了 **資料庫主從分離**、**Redis 緩存/Session/隊列** 以及 **CDN 靜態資源加速** 等關鍵技術在 Laravel 中的實作。

---

## 🚀 功能特色

*   **用戶認證與授權：** 採用 Laravel 提供的安全機制。
*   **產品管理：** 瀏覽產品列表與詳情。
*   **購物車功能：** 提供基本的商品添加、刪除、數量更新等操作。
*   **訂單管理：** 用戶可以查看自己的訂單。
*   **資料庫主從分離：** 優化讀寫性能，支援高併發讀取。
*   **Redis 高效利用：** 緩存熱點數據、管理 Session、處理非同步隊列任務。
*   **靜態資源 CDN 加速：** 通過 AWS CloudFront 提升網站加載速度。
*   **容器化部署：** 透過 Docker Compose 實現環境一致性和快速部署。
*   **持續整合 [CI]：** 藉由 GitHub Actions 確保代碼品質。
*   **高併發處理策略：** 深入探討防止超賣/超買的實現方法。

## 🛠️ 技術棧

*   **後端框架:** Laravel 11 (PHP 8.3)
*   **資料庫:** MySQL 8.0 (支援主從分離，可與 AWS RDS 無縫對接)
*   **緩存/Session/隊列:** Redis 7 (可選集成 Laravel Horizon)
*   **Web 伺服器:** Nginx
*   **容器化:** Docker Compose
*   **前端:** HTML, CSS, JavaScript (基礎)
*   **CI/CD:** GitHub Actions
*   **CDN:** AWS CloudFront (用於靜態資源)

---

## 🌐 完整的生產級架構概覽

此架構旨在實現高可用、高擴展性和高效能，適用於處理高流量的電商場景。

```mermaid
graph TD
    A[Client - 瀏覽器/移動設備] -->|HTTP(S) 請求| B(AWS CloudFront - CDN);
    A -->|HTTP(S) 請求| C(ALB - Application Load Balancer);

    B -->|靜態資源| D(AWS S3 - 靜態資源儲存);

    C -->|負載平衡| E[Auto Scaling Group - EC2實例];
    E -->|Nginx + PHP-FPM| F[Laravel 應用程式邏輯];

    F -->|緩存/Session/隊列| G(AWS ElastiCache - Redis Cluster);
    F -->|讀寫分離邏輯| H[Laravel DB Read/Write Splitter];

    H -->|寫入操作| I(AWS RDS MySQL Master - 主資料庫);
    H -->|讀取操作| J(AWS RDS MySQL ReplicaS - 只讀副本);

    I --|非同步複製| J;

    subgraph 後台處理
        G --> K(Laravel Queue Workers);
        K -->|寫入/讀取操作| I;
        K -->|寫入/讀取操作| J;
    end
```
**說明：**

1.  **用戶端：** 發起 HTTP(S) 請求，靜態資源會直接由 CloudFront 響應。
2.  **AWS CloudFront [CDN]：** 加速全球靜態資源（JS, CSS, 圖片）的交付。
3.  **ALB [應用負載平衡器]：** 將動態請求分發到後端的多個應用伺服器實例。
4.  **Auto Scaling Group：** 根據流量自動增減 EC2 實例數量，每個實例運行 Nginx 和 PHP-FPM。
5.  **Laravel 應用程式：** 處理業務邏輯，並與 Redis 和資料庫進行互動。
6.  **AWS ElastiCache [Redis]：** 提供高可用的緩存、Session 儲存和隊列服務。
7.  **Laravel Read/Write Splitter：** 根據請求類型，自動將讀取操作導向只讀副本，寫入操作導向主資料庫。
8.  **AWS RDS MySQL Master：** 處理所有寫入操作。
9.  **AWS RDS MySQL ReplicaS：** 處理大部分讀取操作，通過異步複製與 Master 保持數據同步。
10. **Laravel Queue Workers：** 非同步處理 Redis 隊列中的任務，如訂單創建、郵件發送等。

---

## ⚡ 高流量交易請求流程圖 [含超賣/超買防範]

此流程圖詳細展示了在高流量場景下，一個交易請求如何被處理，並特別強調了防止超賣/超買的關鍵策略。
```mermaid
graph TD
    A[1. 用戶發起請求 (瀏覽器/移動應用)] --> B{動態/API 請求};
    B -- 靜態資源 --> C(AWS CloudFront 直接響應);
    B -- 動態請求 --> D(ALB - Application Load Balancer);

    D --> E[2. ALB 分發請求到 Auto Scaling Group];
    E --> F[Nginx 實例];
    F --> G[PHP-FPM 進程 - 執行 Laravel App];

    G --> H{3. Laravel 應用程式邏輯處理};
    H -- 緩存/Session 讀寫 --> I(AWS ElastiCache - Redis Cluster);

    H -- 高流量交易操作 - e.g., 提交訂單、秒殺 --> J[**Redis 預扣庫存 - 原子性**];
    J -- 庫存不足 --> K(回響: 商品已售罄，或重試);
    J -- 庫存足夠 --> L[**將訂單處理推入 Redis 隊列**];
    L --> M(快速響應用戶: 訂單已提交，正在處理);

    M --> N[4. 後台 Worker 服務處理隊列任務];
    N --> O(Worker 從 Redis 隊列中取出任務);
    O --> P[Worker 非同步執行交易業務邏輯 - 含重試機制];

    P --> Q{執行數據庫操作};
    Q -- 數據寫入 --> R(AWS RDS MySQL Master Database);
    Q -- 數據讀取 --> S(AWS RDS MySQL Read Replica DatabaseS);

    R -- 異步複製 --> S;

    S --> T[交易完成，數據最終一致];

    style J fill:#f9f,stroke:#333,stroke-width:2px;
    style L fill:#f9f,stroke:#333,stroke-width:2px;
```
**防止超賣/超買策略補充：**

在高流量交易系統中，防止超賣或超買是至關重要的。我們通常會採用以下綜合策略：

1.  **Redis 預扣庫存 [原子性操作]：**
    *   在用戶下單時，首先在 Redis 中進行原子性的庫存扣減 (`DECRBY` 命令)。Redis 的單線程特性確保了這個操作的原子性。
    *   如果 Redis 庫存不足，則立即拒絕請求，實現流量削峰。
    *   這能以極快的速度響應大量請求，保護後端資料庫。
2.  **隊列異步處理：**
    *   Redis 預扣成功後，將實際的訂單創建任務推入 Laravel 隊列。
    *   Web 服務器可以立即響應用戶「訂單已提交，正在處理中」，提升用戶體驗。
    *   後台的 Queue Worker 非同步地從隊列中取出任務並進行處理。
3.  **MySQL 庫存最終扣減 [雙重保險]：**
    *   在 Worker 處理任務時，執行實際的 MySQL 庫存扣減和訂單創建。
    *   此時可以再次使用 **樂觀鎖 [Optimistic Locking]** 或 **悲觀鎖 [Pessimistic Locking]** 作為雙重檢查，確保在最終寫入資料庫時沒有超賣情況發生。
    *   若 Worker 處理失敗，應有回滾機制，如將 Redis 預扣的庫存加回，或將任務重試。

---

## 🚀 快速開始

### 環境要求

*   Git
*   Docker Desktop (包含 Docker Engine 和 Docker Compose)
*   PHP 8.3 (可選，僅用於在主機上執行 `composer` 或 `artisan` 命令)

### 專案設置

1.  **克隆專案:**
    ```bash
    git clone https://github.com/BpsEason/laravel-ecommerce-demo.git
    cd laravel-ecommerce-demo
    ```
2.  **創建 `.env` 文件:**
    ```bash
    cp .env.example .env
    ```
    打開 `.env` 文件，更新 `APP_KEY` 和資料庫憑證。
    你可以通過 Docker 服務生成 `APP_KEY`：
    ```bash
    docker compose run --rm artisan key:generate
    ```

### 本地服務啟動

1.  **構建並啟動 Docker 服務:**
    ```bash
    docker compose up -d --build
    ```
    這將啟動 `app` (PHP-FPM), `nginx`, `db` (MySQL), `redis` 和 `worker` (Laravel Queue Worker) 服務。

2.  **執行資料庫遷移和填充 [Seeding]:**
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

---

## 🔄 主從分離 [Read/Write Splitting] 配置

資料庫主從分離是提升資料庫吞吐量和可用性的關鍵策略。

### AWS RDS 與 Read Replica 考量

在 AWS RDS 環境中，可以輕鬆設定 Master 資料庫和一個或多個 Read Replica。你需要為每個實例獲取各自的端點 (Endpoint)。

### Laravel 配置 [`config/database.php`]

Laravel 內建支援資料庫讀寫分離，我們將在 `config/database.php` 中配置 `mysql` 連接。

```php
// config/database.php

'mysql' => [
    'driver' => 'mysql',
    // ... 其他標準配置 ...

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

**`.env` 配置示例 [本地 Docker Compose 環境]:**

```dotenv
# ... 其他配置

# 主資料庫 (Write Connection)
DB_WRITE_HOST=db
DB_WRITE_PORT=3306
DB_WRITE_DATABASE=laravel
DB_WRITE_USERNAME=laravel_user
DB_WRITE_PASSWORD=password

# 只讀副本 (Read Connection) - 在本地 Docker Compose 下模擬指向同一個 DB 服務
DB_READ_HOST_1=db
DB_READ_PORT=3306
DB_READ_DATABASE=laravel
DB_READ_USERNAME=laravel_user
DB_READ_PASSWORD=password

# 實際部署到 AWS RDS 時，替換為各自的 Endpoint
# DB_WRITE_HOST=your-rds-master-endpoint.rds.amazonaws.com
# DB_READ_HOST_1=your-rds-replica-1-endpoint.rds.amazonaws.com
```

### Sticky Connections

設置 `'sticky' => true` 是非常重要的功能。它確保了在單一請求中，如果發生了任何寫入操作，則該請求後續的所有資料庫操作（無論讀寫）都會被強制路由到主資料庫。這有效避免了因主從同步延遲而導致的資料不一致問題。

---

## ⚡ Redis 配置

Redis 是 Laravel 應用程式性能優化的核心組件，用於緩存、Session 和隊列管理。

在 `.env` 中配置 Redis：

```dotenv
REDIS_HOST=redis # Docker Compose 服務名稱
REDIS_PASSWORD=null # 如果 Redis 沒有密碼，設為 null 或空字串
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

*   **緩存 [Cache]:** 減少對資料庫的頻繁查詢，加速響應。
*   **Session:** 將 Session 儲存在 Redis 中，便於多實例共享和水平擴展。
*   **隊列 [Queue]:** 處理耗時的背景任務，避免阻塞 Web 請求。`worker` 服務會持續消費這些任務。

---

## 🖼️ 靜態資源 CDN [AWS CloudFront]

為提升網站加載速度和用戶體驗，建議將靜態資源 (CSS, JavaScript, 圖片) 部署到 CDN。

**AWS CloudFront 配置簡述:**

1.  **S3 Bucket:** 創建一個 S3 Bucket 存放靜態資源。
2.  **上傳資源:** 將 `public` 目錄下的靜態文件同步到 S3。
3.  **CloudFront Distribution:** 創建一個 CloudFront 分發，將其源 (Origin) 指向你的 S3 Bucket。配置緩存行為和協議策略 (建議 `Redirect HTTP to HTTPS`)。
4.  **Laravel [`config/app.php`]：**
    設定 `asset_url` 為你的 CloudFront 域名。

    ```php
    // config/app.php
    'asset_url' => env('ASSET_URL', null),
    ```
    在 `.env` 中指定：
    ```dotenv
    ASSET_URL=https://your-cloudfront-distribution-id.cloudfront.net
    ```
    之後，在 Blade 模板中使用 `asset()` 輔助函數時，Laravel 會自動生成 CDN URL。
    ```blade
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    <img src="{{ asset('images/logo.png') }}" alt="Logo">
    ```

---

## 🐳 Docker Compose 服務

本專案使用 Docker Compose 管理以下核心服務：

*   **`app` [PHP-FPM]:** Laravel 應用程式的 PHP-FPM 進程。
*   **`nginx`:** 作為反向代理，轉發 Web 請求並處理靜態資源。
*   **`db` [MySQL]:** 資料庫服務，在本地環境中模擬主資料庫和讀寫分離。
*   **`redis`:** 緩存、Session 和隊列服務。
*   **`worker`:** Laravel Queue Worker，專門處理背景任務。

---

## 🚀 GitHub Actions CI

專案配置了 GitHub Actions 進行持續整合，確保代碼品質和專案穩定性：

*   **Linting/Static Analysis:** 檢查代碼風格和潛在問題。
*   **單元測試/整合測試:** 運行 PHPUnit 測試。
*   **Docker Build:** 驗證 `Dockerfile` 和 `docker-compose.yml` 可以成功構建映像。

---

## 💡 本地測試指令

*   **啟動服務:** `docker compose up -d`
*   **停止服務:** `docker compose down`
*   **重啟服務:** `docker compose restart`
*   **查看日誌:** `docker compose logs -f`
*   **執行 Artisan 命令:** `docker compose run --rm artisan [command]` (e.g., `docker compose run --rm artisan cache:clear`)
*   **執行 Composer 命令:** `docker compose run --rm composer [command]` (e.g., `docker compose run --rm composer update`)
*   **運行 PHPUnit 測試:** `docker compose run --rm app php artisan test`
*   **進入 `app` 容器:** `docker compose exec app bash`

---

## ✨ 未來改進

*   集成 Laravel Breeze 或 Sanctum 實現完整的用戶認證流程。
*   實現完整的產品 CRUD 操作和分類系統。
*   添加更多單元測試和功能測試。
*   集成 Vue.js / React.js 或 Livewire 實現響應式前端。
*   實現支付網關集成。
*   部署到 AWS ECS/EKS 或其他雲平台。
*   使用 Laravel Horizon 監控隊列。
*   更詳細的 API 文件。

---

## 🤝 貢獻

歡迎提交 Pull Requests 或報告問題。請確保你的代碼遵循 PSR-12 標準，並包含相關測試。

## 📄 許可證

這個專案遵循 MIT 許可證。

---
