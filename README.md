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

好的，這就為您提供純文字版本的架構圖和流程圖，可以直接放到 README.md 中。

---

## 完整的生產級架構概覽 (含 ALB/ELB)

```
+---------------------+
|      Client         |
|  (瀏覽器/移動設備)    |
+----------+----------+
           | HTTP(S) 請求
           | (靜態資源由 CloudFront 處理)
           V
+---------------------+
|         ALB         |
| (Application Load   |
|     Balancer)       |
+----------+----------+
           | HTTP(S) 請求
           | (負載平衡、自動擴展)
           V
+---------------------+
|  Auto Scaling Group |
| (多個 EC2 實例 /   |
|   Nginx + PHP-FPM   |
|    [Laravel App])   |
+----------+----------+
           |
+----------+----------+       +---------------------+
| Laravel 應用程式邏輯 |<----->|   AWS ElastiCache   |
| (處理請求, Push/Pop |       |    (Redis Cluster)  |
|      Queue)         |       | (Cache, Session, Queue)|
+----------+----------+       +---------------------+
           |
           V
+---------------------+
|  Laravel Read/Write |
|     Splitter        |
| (config/database.php)|
+----------+----------+
   | (寫入 Write)     | (讀取 Read)
   V                V
+---------------------+  +-------------------------+
|  AWS RDS MySQL Master |<-- Async Replication --->|  AWS RDS MySQL Replica(s) |
|      (主資料庫)       |                          |    (只讀副本資料庫)     |
+---------------------+  +-------------------------+

```

### 架構圖說明：

1.  **用戶端 (Client):** 透過瀏覽器或移動設備發起請求。
2.  **AWS CloudFront (靜態資源 CDN):** 接收並響應靜態資源 (JS, CSS, 圖片) 請求，實現全球加速和緩存。
3.  **ALB (Application Load Balancer):** AWS 應用負載平衡器，作為所有動態請求的入口。它負責將流量分發到後端的多個應用伺服器實例，並與自動擴展組集成。
4.  **Auto Scaling Group (自動擴展組):** 根據流量負載自動增減 EC2 實例數量，以應對高流量峰值。每個實例運行 Nginx (Web 伺服器) 和 PHP-FPM (執行 Laravel 應用邏輯)。
5.  **AWS ElastiCache (Redis Cluster):** AWS 託管的 Redis 服務，實現高可用和數據分片。用於緩存、Session 儲存和隊列管理。
6.  **AWS RDS MySQL Master Database:** AWS 託管的 MySQL 主資料庫實例，處理所有寫入操作 (INSERT, UPDATE, DELETE)。
7.  **AWS RDS MySQL Read Replica Database(s):** AWS 託管的一個或多個 MySQL 只讀副本實例，處理大部分讀取操作 (SELECT)。
8.  **Async Replication (異步複製):** 主資料庫將所有變更異步複製到所有 Read Replica，確保數據最終一致性。

---

## 雙 11 高流量交易的請求流程圖 (含 ALB/ELB & Redis 隊列)

```
1. 用戶發起請求 (瀏覽器/移動應用)
   |
   +--- (靜態資源) ---> AWS CloudFront (直接響應)
   |
   +--- (動態/API 請求) ---> ALB (Application Load Balancer)
                               |
                               | (ALB 根據負載分發請求)
                               V
2. ALB 分發請求到 Auto Scaling Group
   |
   +--- (ALB 集成 Auto Scaling，根據流量動態增減 Nginx + PHP-FPM 實例)
   |
   +---> Nginx 實例 (處理 Web 請求)
          |
          +--- (Nginx 將 PHP 請求轉發 FastCGI) ---> PHP-FPM 進程 (執行 Laravel 應用)
                                                       |
                                                       V
3. Laravel 應用程式邏輯處理 (PHP-FPM)
   |
   +--- (緩存/Session 讀寫) ---> AWS ElastiCache (Redis Cluster)
   |        (優先從 Redis 讀取，命中則直接響應)
   |
   +--- (高流量交易操作 - e.g., 提交訂單、秒殺)
   |      |
   |      +--- (快速推入非同步任務) ---> Redis 隊列 (ElastiCache)
   |      |                                (作為緩衝區，吸收流量洪峰)
   |      +--- (即時響應用戶)
   |
   V
4. 後台 Worker 服務處理隊列任務 (獨立的 PHP-FPM 實例，運行 `php artisan queue:work`)
   |
   +--- (Worker 從 Redis 隊列中取出任務) ---> Redis 隊列 (ElastiCache)
   |
   +--- (Worker 非同步執行交易業務邏輯，含重試機制)
   |      |
   |      +--- (執行數據寫入操作) ---> AWS RDS MySQL Master Database
   |      |
   |      +--- (執行數據讀取操作) ---> AWS RDS MySQL Read Replica Database(s)
   |
   V
5. 資料庫互動 (AWS RDS MySQL Master & Read Replicas)
   |
   +--- (寫入操作集中於 Master DB)
   |
   +--- (讀取操作分散於 Read Replica DBs，Laravel `sticky` 連接保障一致性)
   |
   +--- (Master DB 通過異步複製同步數據到 Read Replicas)
   |
   V
[交易完成，數據最終一致]
```

好的，這就為您提供純文字版本的架構圖和流程圖，可以直接放到 README.md 中。

---

## 完整的生產級架構概覽 (含 ALB/ELB)

```
+---------------------+
|      Client         |
|  (瀏覽器/移動設備)    |
+----------+----------+
           | HTTP(S) 請求
           | (靜態資源由 CloudFront 處理)
           V
+---------------------+
|         ALB         |
| (Application Load   |
|     Balancer)       |
+----------+----------+
           | HTTP(S) 請求
           | (負載平衡、自動擴展)
           V
+---------------------+
|  Auto Scaling Group |
| (多個 EC2 實例 /   |
|   Nginx + PHP-FPM   |
|    [Laravel App])   |
+----------+----------+
           |
+----------+----------+       +---------------------+
| Laravel 應用程式邏輯 |<----->|   AWS ElastiCache   |
| (處理請求, Push/Pop |       |    (Redis Cluster)  |
|      Queue)         |       | (Cache, Session, Queue)|
+----------+----------+       +---------------------+
           |
           V
+---------------------+
|  Laravel Read/Write |
|     Splitter        |
| (config/database.php)|
+----------+----------+
   | (寫入 Write)     | (讀取 Read)
   V                V
+---------------------+  +-------------------------+
|  AWS RDS MySQL Master |<-- Async Replication --->|  AWS RDS MySQL Replica(s) |
|      (主資料庫)       |                          |    (只讀副本資料庫)     |
+---------------------+  +-------------------------+

```

### 架構圖說明：

1.  **用戶端 (Client):** 透過瀏覽器或移動設備發起請求。
2.  **AWS CloudFront (靜態資源 CDN):** 接收並響應靜態資源 (JS, CSS, 圖片) 請求，實現全球加速和緩存。
3.  **ALB (Application Load Balancer):** AWS 應用負載平衡器，作為所有動態請求的入口。它負責將流量分發到後端的多個應用伺服器實例，並與自動擴展組集成。
4.  **Auto Scaling Group (自動擴展組):** 根據流量負載自動增減 EC2 實例數量，以應對高流量峰值。每個實例運行 Nginx (Web 伺服器) 和 PHP-FPM (執行 Laravel 應用邏輯)。
5.  **AWS ElastiCache (Redis Cluster):** AWS 託管的 Redis 服務，實現高可用和數據分片。用於緩存、Session 儲存和隊列管理。
6.  **AWS RDS MySQL Master Database:** AWS 託管的 MySQL 主資料庫實例，處理所有寫入操作 (INSERT, UPDATE, DELETE)。
7.  **AWS RDS MySQL Read Replica Database(s):** AWS 託管的一個或多個 MySQL 只讀副本實例，處理大部分讀取操作 (SELECT)。
8.  **Async Replication (異步複製):** 主資料庫將所有變更異步複製到所有 Read Replica，確保數據最終一致性。

---

## 雙 11 高流量交易的請求流程圖 (含 ALB/ELB & Redis 隊列)

```
1. 用戶發起請求 (瀏覽器/移動應用)
   |
   +--- (靜態資源) ---> AWS CloudFront (直接響應)
   |
   +--- (動態/API 請求) ---> ALB (Application Load Balancer)
                               |
                               | (ALB 根據負載分發請求)
                               V
2. ALB 分發請求到 Auto Scaling Group
   |
   +--- (ALB 集成 Auto Scaling，根據流量動態增減 Nginx + PHP-FPM 實例)
   |
   +---> Nginx 實例 (處理 Web 請求)
          |
          +--- (Nginx 將 PHP 請求轉發 FastCGI) ---> PHP-FPM 進程 (執行 Laravel 應用)
                                                       |
                                                       V
3. Laravel 應用程式邏輯處理 (PHP-FPM)
   |
   +--- (緩存/Session 讀寫) ---> AWS ElastiCache (Redis Cluster)
   |        (優先從 Redis 讀取，命中則直接響應)
   |
   +--- (高流量交易操作 - e.g., 提交訂單、秒殺)
   |      |
   |      +--- (快速推入非同步任務) ---> Redis 隊列 (ElastiCache)
   |      |                                (作為緩衝區，吸收流量洪峰)
   |      +--- (即時響應用戶)
   |
   V
4. 後台 Worker 服務處理隊列任務 (獨立的 PHP-FPM 實例，運行 `php artisan queue:work`)
   |
   +--- (Worker 從 Redis 隊列中取出任務) ---> Redis 隊列 (ElastiCache)
   |
   +--- (Worker 非同步執行交易業務邏輯，含重試機制)
   |      |
   |      +--- (執行數據寫入操作) ---> AWS RDS MySQL Master Database
   |      |
   |      +--- (執行數據讀取操作) ---> AWS RDS MySQL Read Replica Database(s)
   |
   V
5. 資料庫互動 (AWS RDS MySQL Master & Read Replicas)
   |
   +--- (寫入操作集中於 Master DB)
   |
   +--- (讀取操作分散於 Read Replica DBs，Laravel `sticky` 連接保障一致性)
   |
   +--- (Master DB 通過異步複製同步數據到 Read Replicas)
   |
   V
[交易完成，數據最終一致]
```

架構圖說明：
用戶端 (Client): 透過瀏覽器或移動設備發起請求。
AWS CloudFront (靜態資源 CDN): 接收並響應靜態資源 (JS, CSS, 圖片) 請求，實現全球加速和緩存。
ALB (Application Load Balancer): AWS 應用負載平衡器，作為所有動態請求的入口。它負責將流量分發到後端的多個應用伺服器實例，並與自動擴展組集成。
Auto Scaling Group (自動擴展組): 根據流量負載自動增減 EC2 實例數量，以應對高流量峰值。每個實例運行 Nginx (Web 伺服器) 和 PHP-FPM (執行 Laravel 應用邏輯)。
AWS ElastiCache (Redis Cluster): AWS 託管的 Redis 服務，實現高可用和數據分片。用於緩存、Session 儲存和隊列管理。
AWS RDS MySQL Master Database: AWS 託管的 MySQL 主資料庫實例，處理所有寫入操作 (INSERT, UPDATE, DELETE)。
AWS RDS MySQL Read Replica Database(s): AWS 託管的一個或多個 MySQL 只讀副本實例，處理大部分讀取操作 (SELECT)。
Async Replication (異步複製): 主資料庫將所有變更異步複製到所有 Read Replica，確保數據最終一致性。

## 快速開始

### 環境要求

*   Git
*   Docker Desktop (包含 Docker Engine 和 Docker Compose)
*   PHP 8.3 (僅用於在主機上執行 `composer` 或 `artisan` 命令，非必要，容器內已包含)

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

好的，這就為您詳細闡述如何利用 **MySQL 主從複製 (Master-Replica Replication)**、**Redis** 和 **Docker** 協同解決高流量場景下的性能瓶頸和資料庫同步問題。我會提供一個更深入的解釋，並強調它們在 Laravel 應用程式中的整合方式。

---

## 解決高流量與資料庫同步問題的策略：MySQL 主從複製 + Redis + Docker

在高流量應用場景下，單一資料庫實例往往難以承受巨大的讀寫壓力，並可能成為系統的性能瓶頸。同時，如何有效管理資料在不同服務間的同步也是一個挑戰。結合 **MySQL 主從複製**、**Redis** 和 **Docker**，我們可以構建一個彈性、高效且易於部署的解決方案。

### 1. MySQL 主從複製 (Master-Replica Replication)

#### 核心概念：讀寫分離 (Read/Write Splitting)

主從複製的核心思想是將資料庫操作分為兩類：寫入 (Write) 和讀取 (Read)。

*   **主資料庫 (Master):** 負責處理所有的寫入操作 (INSERT, UPDATE, DELETE) 和部分讀取操作（尤其是在強一致性要求高的情況下，或在啟用 Sticky Connections 時）。主資料庫會將其所有的變更記錄下來，並同步給一個或多個從資料庫。
*   **從資料庫 (Replica/Slave):** 負責處理大量的讀取操作 (SELECT)。從資料庫會訂閱主資料庫的變更日誌，並非同步地將這些變更應用到自身，從而保持與主資料庫的資料一致性。

#### 如何解決高流量問題？

1.  **分擔讀取壓力：** 大多數 Web 應用程式的讀取操作遠多於寫入操作（典型的讀寫比可能高達 80/20 甚至 90/10）。將這些讀取請求分散到多個從資料庫上，可以顯著降低主資料庫的負載，提高其處理寫入操作的能力。
2.  **提高可用性：** 當主資料庫出現故障時，可以將一個從資料庫提升為主資料庫，並將其他從資料庫切換到新的主資料庫，從而縮短停機時間。
3.  **異地備援：** 將從資料庫部署在不同的地理位置或可用區，可以提供災難恢復能力。

#### 資料庫同步問題：延遲與一致性

主從複製默認是非同步的，這意味著主資料庫的寫入操作不會立即在從資料庫上可見，會存在一個**同步延遲 (Replication Lag)**。

*   **影響:** 如果應用程式在寫入數據後立即從從資料庫讀取，可能會讀取到舊的數據。
*   **解決方案 (Laravel 的 `sticky` 配置):**
    在 Laravel 的 `config/database.php` 中，將 `sticky` 設置為 `true`。其工作原理如下：
    1.  當一個 HTTP 請求（或一個應用程式的生命週期）進入時，Laravel 會檢查請求中是否已經執行過寫入操作。
    2.  如果一個請求中**發生了任何寫入操作** (INSERT, UPDATE, DELETE)，Laravel 會自動將該請求**後續的所有資料庫操作（無論讀寫）都路由到主資料庫 (Write Connection)**。
    3.  如果一個請求中**沒有任何寫入操作**，所有的讀取操作都會被路由到從資料庫 (Read Connection)。

    **目的:** `sticky` 連接確保了在同一個請求的生命週期內，應用程式總能讀取到最新的數據，避免了因主從同步延遲導致的資料不一致問題。這是一種「最終一致性」模型下的「請求級強一致性」策略。

#### Docker 中的實現

在 Docker Compose 環境中，我們可以通過配置不同的資料庫服務來模擬主從複製：

*   **`db-master` 服務：** 運行 MySQL 實例，配置為 Master。
*   **`db-replica-1`, `db-replica-2` 服務：** 運行 MySQL 實例，配置為 Replica，並指定從 `db-master` 同步。
*   **Laravel `.env` 配置：**
    ```dotenv
    DB_WRITE_HOST=db-master
    DB_READ_HOST_1=db-replica-1
    DB_READ_HOST_2=db-replica-2
    ```

這樣 Laravel 應用程式在連接資料庫時，會根據讀寫操作和 `sticky` 配置，自動選擇連接到 `db-master` 或 `db-replica-X`。

### 2. Redis：緩存、Session 和隊列

Redis 是一個高性能的 Key-Value 儲存，作為一個記憶體資料庫，它在處理高併發讀寫方面表現卓越，是解決高流量問題的利器。

#### 核心作用：分流與加速

1.  **緩存 (Cache):**
    *   **解決高流量問題:** 將頻繁訪問的資料 (例如產品列表、配置信息、用戶資料等) 儲存在 Redis 中。當請求到達時，應用程式首先查詢 Redis。如果資料存在 (緩存命中)，則直接從 Redis 返回，無需查詢資料庫，極大地降低了資料庫的負載和查詢延遲。
    *   **資料同步問題:** 緩存資料存在**過期時間 (TTL)**。當原始資料更新時，需要**失效緩存 (Cache Invalidation)**。Laravel 提供了簡單的緩存操作 (`Cache::put()`, `Cache::forget()`, `Cache::remember()`)。對於複雜情況，可以實作事件監聽器，當模型更新時自動清理相關緩存。

2.  **Session 儲存:**
    *   **解決高流量問題:** 將用戶 Session 儲存在 Redis 中，而不是文件系統或資料庫。這使得應用程式可以輕鬆地實現**水平擴展 (Horizontal Scaling)**，即多個應用程式實例可以共享同一個 Redis Session 儲存，任何用戶的請求都可以被任何一個應用程式實例處理。
    *   **資料同步問題:** Redis 本身就是高可用的，且通常比資料庫更不容易成為瓶頸。Session 資料的讀寫在 Redis 中幾乎是即時的，同步問題不大。

3.  **隊列 (Queue):**
    *   **解決高流量問題:** 將耗時的操作 (例如發送郵件、處理圖片上傳、生成報表、第三方 API 調用等) 放入隊列中，由後台的隊列 Worker 非同步處理。這樣 Web 請求可以快速響應用戶，避免因等待耗時操作完成而造成的超時或用戶體驗下降。
    *   **資料同步問題:** 隊列本身就是一種非同步機制。任務的處理結果會非同步地反映到資料庫或其他系統中。Laravel 提供了隊列監聽器 (`php artisan queue:work`) 和強大的任務調度功能。使用 Laravel Horizon 可以更好地監控和管理隊列。

#### Docker 中的實現

在 `docker-compose.yml` 中，只需簡單地添加一個 `redis` 服務：

```yaml
redis:
    image: redis:alpine
    container_name: laravel_redis
    restart: unless-stopped
    command: redis-server --appendonly yes # 啟用 AOF 持久化
    volumes:
        - redisdata:/data # 持久化 Redis 資料
    ports:
        - "6379:6379" # 映射到主機，方便本地開發連接
    networks:
        - app-network
```
Laravel 應用程式通過 `REDIS_HOST=redis` 環境變數連接到這個 Redis 服務。

### 3. Docker：容器化與部署

Docker 是實現上述解決方案的基石，它提供了標準化、可隔離的運行環境，極大地簡化了部署和管理。

#### 核心優勢：

1.  **環境一致性：** 確保開發、測試、生產環境的運行時一致，避免「在我機器上可以跑」的問題。
2.  **資源隔離：** 每個服務 (PHP-FPM, Nginx, MySQL, Redis) 都運行在獨立的容器中，互相不干擾。
3.  **易於擴展：** 
    *   **應用程式 (PHP-FPM):** 在高流量時，可以輕鬆地擴展 `app` 服務的容器數量 (`docker compose up --scale app=X`)，每個容器都能夠利用主從複製和 Redis。
    *   **資料庫 (MySQL Replica):** 可以根據讀取負載的增加，動態地增加從資料庫的數量。
    *   **隊列 Worker:** 擴展 `worker` 服務的容器數量以加快任務處理。
4.  **快速部署：** 通過 `docker compose up` 命令，可以一鍵部署整個應用程式堆疊。
5.  **服務發現與網絡：** Docker Compose 會自動為所有服務創建一個內部網絡，服務之間可以使用服務名互相通信 (例如 `app` 可以通過 `db` 訪問 MySQL，通過 `redis` 訪問 Redis)。

#### Docker Compose 中的實現

*   **`app` 服務：** 運行 Laravel 應用程式，連接 Nginx、MySQL (通過主從配置) 和 Redis。
*   **`nginx` 服務：** 作為反向代理，將 HTTP 請求轉發到 `app` 服務的 PHP-FPM。
*   **`db` 服務 (或 `db-master`, `db-replica-X`):** 提供 MySQL 資料庫。
*   **`redis` 服務：** 提供 Redis 緩存、Session 和隊列。
*   **`worker` 服務：** 獨立運行 Laravel 隊列監聽器，消費 Redis 隊列中的任務。

```yaml
# docker-compose.yml 示例（簡化版，省略部分配置）
version: '3.8'

services:
    app:
        build: .
        environment:
            # ... Laravel 環境變數
            DB_WRITE_HOST: db # 或 db-master
            DB_READ_HOST_1: db # 或 db-replica-1
            REDIS_HOST: redis
        depends_on:
            - db
            - redis
        networks:
            - app-network

    nginx:
        image: nginx:alpine
        ports:
            - "80:80"
        volumes:
            - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
            - .:/var/www/html # 讓 Nginx 訪問 public 目錄
        depends_on:
            - app
        networks:
            - app-network

    db: # 這裡簡化為單一 DB 服務進行本地模擬。真實主從需要多個 DB 服務
        image: mysql:8.0
        environment:
            MYSQL_DATABASE: laravel
            MYSQL_USER: laravel_user
            MYSQL_PASSWORD: password
        volumes:
            - dbdata:/var/lib/mysql
        networks:
            - app-network

    redis:
        image: redis:alpine
        command: redis-server --appendonly yes
        volumes:
            - redisdata:/data
        networks:
            - app-network

    worker: # 隊列 worker
        build: . # 與 app 服務使用相同的 Dockerfile
        command: php artisan queue:work --verbose --tries=3 --timeout=90
        environment:
            # ... 引用 app 服務的環境變數，或單獨配置
            DB_WRITE_HOST: db # worker 也可能執行寫入操作
            DB_READ_HOST_1: db
            REDIS_HOST: redis
        depends_on:
            - db
            - redis
        networks:
            - app-network

volumes:
    dbdata:
    redisdata:

networks:
    app-network:
        driver: bridge
```

### 總結

通過將 **MySQL 主從複製**、**Redis** 和 **Docker** 這三種技術結合起來，我們可以：

1.  **分流資料庫讀寫壓力：** MySQL 主從複製處理大量讀取請求，減輕主庫負擔。
2.  **提高資料庫響應速度：** Redis 作為緩存層，將熱點數據直接從記憶體返回。
3.  **實現應用程式的水平擴展：** Redis Session 和隊列機制允許無狀態的應用程式實例自由擴展。
4.  **解決資料庫同步延遲問題：** Laravel 的 `sticky` 連接機制確保了請求級別的資料一致性。
5.  **簡化部署與管理：** Docker 提供一致的運行環境和便捷的擴展能力。

這個組合在高流量電商、社交媒體或任何讀寫壓力大的 Web 應用程式中都非常有效，能夠顯著提升系統的性能、可用性和可擴展性。

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
