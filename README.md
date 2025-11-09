# laravel-ecommerce-demo

這是一個基於 Laravel 11 的電商應用程式演示專案，採用 Docker Compose 進行容器化部署。此專案深入探討了如何構建一個高性能、可擴展且具備高可用性的電商後端系統，特別著重於解決高流量場景下常見的挑戰。

**核心演示點包括：**

1.  **資料庫主從分離（讀寫分離）的實作與應用：** 詳細展示如何配置 Laravel 框架以自動路由讀寫請求至不同的資料庫實例，有效分擔主資料庫的壓力，提升整體資料庫吞吐量。
2.  **利用 Redis 優化應用程式性能：** 涵蓋 Redis 在緩存熱點數據、管理用戶 Session 以實現應用程式水平擴展、以及作為消息隊列處理耗時背景任務方面的應用。
3.  **靜態資源 CDN 加速：** 通過集成 CDN 服務，優化靜態資源的交付，顯著提升網站加載速度和用戶體驗。
4.  **解決高流量電商問題的策略：** 結合上述技術棧，探討在高併發環境中，如防止商品超賣、流量削峰、保障數據最終一致性等關鍵業務問題的解決方案。

透過本專案，開發者可以了解如何將這些先進的架構模式與 Laravel 生態系統緊密結合，構建出強大而穩健的電商解決方案。

---

## 目錄

*   [功能特色](#功能特色)
*   [技術棧](#技術棧)
*   [完整的生產級架構概覽](#完整的生產級架構概覽)
*   [高流量交易的請求流程圖](#高流量交易的請求流程圖)
*   [**核心問題解決方案：高流量電商挑戰**](#核心問題解決方案高流量電商挑戰)
    *   [如何利用讀寫分離解決高流量問題](#如何利用讀寫分離解決高流量問題)
    *   [防止超賣/超買的關鍵策略](#防止超賣/超買的關鍵策略)
    *   [綜合解決方案：MySQL 主從複製 + Redis + Docker](#綜合解決方案mysql-主從複製--redis--docker)
*   [快速開始](#快速開始)
    *   [環境要求](#環境要求)
    *   [專案設置](#專案設置)
    *   [本地服務啟動](#本地服務啟動)
    *   [Postman Collection](#postman-collection)
*   [主從分離 (Read/Write Splitting) 配置](#主從分離-read/write-splitting-配置)
    *   [AWS RDS 與 Read Replica 考量](#aws-rds-與-read-replica-考量)
    *   [Laravel 配置 (`config/database.php`)](#laravel-配置-configdatabasephp)
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

*   用戶認證與授權 (基於 Laravel Breeze 或 Sanctum)
*   產品列表與詳情頁
*   購物車功能
*   訂單管理
*   **資料庫主從分離（讀寫分離）實作：** 有效分散資料庫讀取壓力，提高系統吞吐量。
*   **利用 Redis 進行緩存、Session 和隊列管理：** 全面優化讀寫性能，實現高併發處理與異步任務管理。
*   靜態資源 CDN 加速：提升前端加載速度和用戶體驗。
*   容器化部署 (Docker Compose)：簡化開發、測試與生產環境的一致性與部署流程。
*   持續整合 (GitHub Actions CI)：保障代碼質量與專案穩定性。

## 技術棧

*   **後端框架:** Laravel 11 (PHP 8.3)
*   **資料庫:** MySQL 8.0 (支援主從分離，可與 AWS RDS 無縫對接)
*   **緩存/Session/隊列:** Redis 7 (可選集成 Laravel Horizon)
*   **Web 伺服器:** Nginx
*   **容器化:** Docker Compose
*   **前端:** HTML, CSS, JavaScript (待定，可集成 Vue.js/React.js 或 Livewire)
*   **CI/CD:** GitHub Actions

## 完整的生產級架構概覽

```
+---------------------+           +--------------------------+
|      Client         |           |   AWS CloudFront (CDN)   |
|  (瀏覽器/移動設備)    +-----------> (靜態資源: JS, CSS, 圖片) |
+----------+----------+           +--------------------------+
           |
           | HTTP(S) 請求 (動態資源)
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
           | (Laravel 應用邏輯)
           V
+---------------------+       +---------------------+
|  Laravel 應用程式   |<----->|   AWS ElastiCache   |
| (處理請求, Push/Pop |       |    (Redis Cluster)  |
|      Queue)         |       | (Cache, Session, Queue)|
+----------+----------+       +---------------------+
           |
           | (資料庫操作)
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
**架構圖說明：**
1.  **用戶端 (Client):** 透過瀏覽器或移動設備發起請求。
2.  **AWS CloudFront (靜態資源 CDN):** 接收並響應靜態資源 (JS, CSS, 圖片) 請求，實現全球加速和緩存。
3.  **ALB (Application Load Balancer):** AWS 應用負載平衡器，作為所有動態請求的入口。它負責將流量分發到後端的多個應用伺服器實例，並與自動擴展組集成。
4.  **Auto Scaling Group (自動擴展組):** 根據流量負載自動增減 EC2 實例數量，以應對高流量峰值。每個實例運行 Nginx (Web 伺服器) 和 PHP-FPM (執行 Laravel 應用邏輯)。
5.  **AWS ElastiCache (Redis Cluster):** AWS 託管的 Redis 服務，實現高可用和數據分片。用於緩存、Session 儲存和隊列管理。
6.  **AWS RDS MySQL Master Database:** AWS 託管的 MySQL 主資料庫實例，處理所有寫入操作 (INSERT, UPDATE, DELETE)。
7.  **AWS RDS MySQL Read Replica Database(s):** AWS 託管的一個或多個 MySQL 只讀副本實例，處理大部分讀取操作 (SELECT)。
8.  **Async Replication (異步複製):** 主資料庫將所有變更異步複製到所有 Read Replica，確保數據最終一致性。

## 高流量交易的請求流程圖 (含 ALB/ELB & Redis 隊列)

```
1. 用戶發起請求 (瀏覽器/移動應用)
   |
   +--- (靜態資源) ---> AWS CloudFront (直接響應)
   |
   +--- (動態/API 請求) ---> ALB (Application Load Balancer)
                               |


## 目錄

*   [功能特色](#功能特色)
*   [技術棧](#技術棧)
*   [完整的生產級架構概覽](#完整的生產級架構概覽)
*   [高流量交易的請求流程圖](#高流量交易的請求流程圖)
*   [高流量場景下的防超賣策略](#高流量場景下的防超賣策略)
*   [解決高流量與資料庫同步問題的策略](#解決高流量與資料庫同步問題的策略)
*   [快速開始](#快速開始)
    *   [環境要求](#環境要求)
    *   [專案設置](#專案設置)
    *   [本地服務啟動](#本地服務啟動)
    *   [Postman Collection](#postman-collection)
*   [主從分離 (Read/Write Splitting) 配置](#主從分離-read/write-splitting-配置)
    *   [AWS RDS 與 Read Replica 考量](#aws-rds-與-read-replica-考量)
    *   [Laravel 配置 (`config/database.php`)](#laravel-配置-configdatabasephp)
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

*   用戶認證與授權 (基於 Laravel Breeze 或 Sanctum)
*   產品列表與詳情頁
*   購物車功能
*   訂單管理
*   資料庫主從分離（讀寫分離）實作
*   利用 Redis 進行緩存、Session 和隊列管理
*   靜態資源 CDN 加速
*   容器化部署 (Docker Compose)
*   持續整合 (GitHub Actions CI)

## 技術棧

*   **後端框架:** Laravel 11 (PHP 8.3)
*   **資料庫:** MySQL 8.0 (支援主從分離，可與 AWS RDS 無縫對接)
*   **緩存/Session/隊列:** Redis 7 (可選集成 Laravel Horizon)
*   **Web 伺服器:** Nginx
*   **容器化:** Docker Compose
*   **前端:** HTML, CSS, JavaScript (待定，可集成 Vue.js/React.js 或 Livewire)
*   **CI/CD:** GitHub Actions

## 完整的生產級架構概覽

```
+---------------------+           +--------------------------+
|      Client         |           |   AWS CloudFront (CDN)   |
|  (瀏覽器/移動設備)    +-----------> (靜態資源: JS, CSS, 圖片) |
+----------+----------+           +--------------------------+
           |
           | HTTP(S) 請求 (動態資源)
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
           | (Laravel 應用邏輯)
           V
+---------------------+       +---------------------+
|  Laravel 應用程式   |<----->|   AWS ElastiCache   |
| (處理請求, Push/Pop |       |    (Redis Cluster)  |
|      Queue)         |       | (Cache, Session, Queue)|
+----------+----------+       +---------------------+
           |
           | (資料庫操作)
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
**架構圖說明：**
1.  **用戶端 (Client):** 透過瀏覽器或移動設備發起請求。
2.  **AWS CloudFront (靜態資源 CDN):** 接收並響應靜態資源 (JS, CSS, 圖片) 請求，實現全球加速和緩存。
3.  **ALB (Application Load Balancer):** AWS 應用負載平衡器，作為所有動態請求的入口。它負責將流量分發到後端的多個應用伺服器實例，並與自動擴展組集成。
4.  **Auto Scaling Group (自動擴展組):** 根據流量負載自動增減 EC2 實例數量，以應對高流量峰值。每個實例運行 Nginx (Web 伺服器) 和 PHP-FPM (執行 Laravel 應用邏輯)。
5.  **AWS ElastiCache (Redis Cluster):** AWS 託管的 Redis 服務，實現高可用和數據分片。用於緩存、Session 儲存和隊列管理。
6.  **AWS RDS MySQL Master Database:** AWS 託管的 MySQL 主資料庫實例，處理所有寫入操作 (INSERT, UPDATE, DELETE)。
7.  **AWS RDS MySQL Read Replica Database(s):** AWS 託管的一個或多個 MySQL 只讀副本實例，處理大部分讀取操作 (SELECT)。
8.  **Async Replication (異步複製):** 主資料庫將所有變更異步複製到所有 Read Replica，確保數據最終一致性。

## 高流量交易的請求流程圖 (含 ALB/ELB & Redis 隊列)

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

## 高流量場景下的防超賣策略

防止超賣/超買是高流量交易系統中的關鍵問題，尤其在分佈式和高併發環境下。這通常涉及對庫存或限額的精確管理，核心原則是確保庫存操作的原子性和隔離性。

### 策略一：悲觀鎖 (Pessimistic Locking)

*   **概念:** 「先取得鎖，再執行操作」，假設數據會被其他事務修改，在讀取時直接加排他鎖 (`FOR UPDATE`)。
*   **優點:** 資料一致性最高，絕對不會超賣。
*   **缺點:** 高併發下性能瓶頸大、易阻塞、有死鎖風險。
*   **適用場景:** 庫存競爭激烈，數據一致性要求極高，併發衝突概率大。

```php
DB::transaction(function () use ($productId, $quantity) {
    $product = Product::where('id', $productId)->lockForUpdate()->first();
    if (!$product || $product->stock < $quantity) {
        throw new Exception("庫存不足");
    }
    $product->stock -= $quantity;
    $product->save();
    // 創建訂單等
}, 5);
```

### 策略二：樂觀鎖 (Optimistic Locking)

*   **概念:** 「先執行操作，再檢查是否衝突」，假設衝突概率低，通過版本號 (`version_id` 或 `updated_at`) 判斷數據是否被修改過。
*   **優點:** 不阻塞事務，吞吐量比悲觀鎖高，避免死鎖。
*   **缺點:** 衝突時需要應用層重試，增加複雜度。
*   **適用場景:** 庫存競爭不如悲觀鎖激烈，吞吐量要求高，允許少量衝突。

```php
DB::transaction(function () use ($productId, $quantity) {
    $product = Product::where('id', $productId)->first();
    if (!$product || $product->stock < $quantity) {
        throw new Exception("庫存不足");
    }
    $originalStock = $product->stock;
    $originalUpdatedAt = $product->updated_at;

    $affectedRows = Product::where('id', $productId)
                            ->where('stock', $originalStock)
                            ->where('updated_at', $originalUpdatedAt)
                            ->update(['stock' => $originalStock - $quantity, 'updated_at' => now()]);
    if ($affectedRows === 0) {
        throw new OptimisticLockingException("商品庫存已被修改，請重試");
    }
    // 創建訂單等
});
```

### 策略三：基於 Redis 的分佈式鎖

*   **概念:** 利用 Redis 的單線程和原子操作 (`SETNX`, `INCR`) 實現分佈式鎖，確保只有一個進程在同一時間操作共享資源。
*   **優點:** 高併發下性能優異，跨服務/進程，可防止死鎖（設置過期時間）。
*   **缺點:** 增加系統複雜度，分佈式鎖的可靠性依賴 Redis 高可用性。
*   **適用場景:** 微服務架構，或需要跨多個應用程式實例協同操作共享資源，追求高吞吐量。

```php
use Illuminate\Support\Facades\Redis;
$lockKey = 'product_stock_lock:' . $productId;
Redis::funnel($lockKey)->limit(1)->then(function () use ($productId, $quantity) {
    // 成功獲取鎖，執行庫存操作 (內部可再使用 DB 事務)
    // ...
}, function () {
    throw new Exception("商品搶購火熱，請稍後重試");
});
```

### 策略四：基於 Redis 的預扣庫存/限流 + 隊列異步處理 (推薦用於秒殺)

*   **概念:** 在 Redis 中原子性預扣庫存，快速響應用戶，然後將實際扣減和訂單創建任務推入隊列，由後台 Worker 非同步處理。
*   **優點:** 極高併發吞吐量，流量削峰，保護資料庫，最終一致性，靈活的重試機制。
*   **缺點:** 複雜度高，實時性挑戰（MySQL 數據有延遲），需完善錯誤回滾機制。
*   **適用場景:** 秒殺、高流量搶購，對「實時」庫存精度要求可在一定時間內容忍最終一致性。

```php
use Illuminate\Support\Facades\Redis;
use App\Jobs\ProcessOrderJob;
$stockKey = 'product:stock:' . $productId;
$newStock = Redis::decrby($stockKey, $quantity);

if ($newStock < 0) {
    Redis::incrby($stockKey, $quantity); // 加回去
    throw new Exception("商品已售罄或庫存不足");
}
dispatch(new ProcessOrderJob($userId, $productId, $quantity));
return response()->json(['message' => '訂單已提交，正在處理中'], 202);
```

**總結與選擇：**
*   **低併發/高一致性:** 悲觀鎖。
*   **中併發/高吞吐量:** 樂觀鎖。
*   **分佈式共享資源保護:** Redis 分佈式鎖。
*   **極端高併發秒殺:** **Redis 預扣庫存 + 隊列異步處理 (推薦)**。此組合常輔以樂觀鎖作為 MySQL 最終扣減的雙重保險。

## 解決高流量與資料庫同步問題的策略：MySQL 主從複製 + Redis + Docker

在高流量應用場景下，單一資料庫實例往往難以承受巨大讀寫壓力，並可能成為系統性能瓶頸。同時，如何有效管理資料在不同服務間的同步也是挑戰。結合 **MySQL 主從複製**、**Redis** 和 **Docker**，我們可以構建一個彈性、高效且易於部署的解決方案。

### 1. MySQL 主從複製 (Master-Replica Replication)

#### 核心概念：讀寫分離 (Read/Write Splitting)
*   **主資料庫 (Master):** 處理所有寫入操作 (INSERT, UPDATE, DELETE)，並將變更記錄同步給從資料庫。
*   **從資料庫 (Replica/Slave):** 處理大量讀取操作 (SELECT)，非同步地應用主庫變更以保持資料一致性。

#### 如何解決高流量問題？
1.  **分擔讀取壓力:** 大多數 Web 應用讀取遠多於寫入，將讀取分散到多個從庫可顯著降低主庫負載。
2.  **提高可用性:** 主庫故障時，可將從庫提升為主庫，縮短停機時間。
3.  **異地備援:** 從庫部署在不同可用區，提供災難恢復能力。

#### 資料庫同步問題：延遲與一致性
*   **影響:** 主從複製默認非同步，存在同步延遲。應用寫入後立即讀取從庫可能讀到舊數據。
*   **解決方案 (Laravel 的 `sticky` 配置):**
    在 `config/database.php` 中設置 `'sticky' => true`。當請求中發生寫入操作後，該請求後續所有資料庫操作（讀寫）都會強制路由到主資料庫，直至請求結束。這確保了在單一請求生命週期內能讀取到最新數據，避免因同步延遲導致的一致性問題。

#### Docker 中的實現
在 Docker Compose 中，可配置多個 MySQL 服務 (`db-master`, `db-replica-1` 等) 來模擬主從，並在 Laravel 的 `.env` 中指定對應的 `DB_WRITE_HOST` 和 `DB_READ_HOST_X`。

### 2. Redis：緩存、Session 和隊列

Redis 作為高性能記憶體資料庫，處理高併發讀寫方面表現卓越，是解決高流量問題的利器。

#### 核心作用：分流與加速
1.  **緩存 (Cache):**
    *   **解決高流量:** 將頻繁訪問的數據儲存到 Redis，減少資料庫查詢，降低負載，提高響應速度。
    *   **資料同步:** 緩存數據有過期時間 (TTL)。原始數據更新時，需執行緩存失效 (Cache Invalidation)。
2.  **Session 儲存:**
    *   **解決高流量:** 將用戶 Session 儲存到 Redis，方便多個應用程式實例共享 Session，實現水平擴展。
    *   **資料同步:** Redis 本身高可用，Session 讀寫幾乎即時，同步問題小。
3.  **隊列 (Queue):**
    *   **解決高流量:** 將耗時操作放入隊列，由後台 Worker 非同步處理，避免阻塞 Web 請求，提升用戶體驗。
    *   **資料同步:** 隊列本身就是非同步機制，任務處理結果會非同步反映到資料庫。

#### Docker 中的實現
在 `docker-compose.yml` 中添加 `redis` 服務，Laravel 應用程式通過 `REDIS_HOST=redis` 環境變數連接。

### 3. Docker：容器化與部署

Docker 是實現上述解決方案的基石，提供標準化、可隔離的運行環境，簡化部署和管理。

#### 核心優勢：
1.  **環境一致性:** 確保開發、測試、生產環境運行時一致。
2.  **資源隔離:** 各服務運行在獨立容器，互不干擾。
3.  **易於擴展:** 可輕鬆擴展 `app`、`db-replica`、`worker` 服務的容器數量以應對高流量。
4.  **快速部署:** `docker compose up` 一鍵部署整個應用堆疊。
5.  **服務發現與網絡:** Docker Compose 自動創建內部網絡，服務間可通過服務名通信。

#### Docker Compose 中的實現
配置 `app` (PHP-FPM)、`nginx`、`db` (MySQL)、`redis` 和 `worker` 服務。

**總結：**
結合 MySQL 主從複製、Redis 和 Docker，可以有效分流資料庫讀寫壓力、提高響應速度、實現應用程式水平擴展、解決同步延遲問題，並簡化部署與管理，顯著提升系統性能、可用性和可擴展性。

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
    打開 `.env` 文件，更新 `APP_KEY` 和資料庫憑證。
    如果本地沒有 PHP 環境，可在服務啟動後執行 `docker compose run --rm artisan key:generate`。

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
2.  設置環境變數 `base_url` 為 `http://localhost`。
3.  開始測試用戶認證、產品查詢、購物車操作等。

## 主從分離 (Read/Write Splitting) 配置

資料庫主從分離是一種常見的性能優化策略，它將讀取操作分配到一個或多個只讀副本 (Read Replicas)，而寫入操作則發送到主資料庫 (Master)。這有助於提高資料庫的吞吐量和可用性。

### AWS RDS 與 Read Replica 考量

在 AWS RDS 環境中，啟用 Read Replica 非常方便。一個常見的部署模式是：
*   **Master 資料庫:** AWS RDS MySQL Instance
*   **Read Replica 資料庫:** 一個或多個基於 Master 的 Read Replica Instance。這些 Replica 可以位於不同的可用區 (Availability Zone) 以提高容錯性。
在此場景下，你需要為 Master 和每個 Read Replica 獲取其各自的端點 (Endpoint)。

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
    // ... 其他配置

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
# 主資料庫 (Write Connection)
DB_WRITE_HOST=db # Docker Compose 服務名稱，或 AWS RDS Master Endpoint
DB_WRITE_PORT=3306
DB_WRITE_DATABASE=laravel
DB_WRITE_USERNAME=laravel_user
DB_WRITE_PASSWORD=password

# 只讀副本 (Read Connection)
# 在本地 Docker Compose 環境下，可指向同一個 DB 服務模擬。
# 部署 AWS RDS 時，替換為 Read Replica Endpoint。
DB_READ_HOST_1=db # Docker Compose 服務名稱，或 AWS RDS Read Replica Endpoint 1
DB_READ_PORT=3306
DB_READ_DATABASE=laravel
DB_READ_USERNAME=laravel_user
DB_READ_PASSWORD=password

# 如果有多個 Read Replica，可添加更多 DB_READ_HOST_X
# DB_READ_HOST_2=your-replica-endpoint-2.rds.amazonaws.com
```
**如何運作:**
*   **讀取操作 (SELECT):** Laravel 會自動從 `read` 配置中隨機選擇一個資料庫連接執行。
*   **寫入操作 (INSERT, UPDATE, DELETE):** Laravel 會自動使用 `write` 配置中的資料庫連接。

### Sticky Connections

在 `config/database.php` 中設置 `'sticky' => true`，這在主從分離環境下非常重要。

**作用:** 當當前請求中執行了任何寫入操作後，該請求後續的所有資料庫操作（無論讀寫）都會被強制路由到主資料庫 (write connection)，直到請求結束。這確保了在單一請求的生命週期內，應用程式總能讀取到最新的資料，避免主從同步延遲可能導致的資料不一致問題。

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
1.  **S3 Bucket:** 創建一個 AWS S3 Bucket 存放靜態資源。
2.  **上傳靜態資源:** 將 `public` 目錄下的所有靜態文件同步到 S3 Bucket。
3.  **CloudFront Distribution:**
    *   創建新的 CloudFront Distribution。
    *   **Origin Domain Name:** 指向 S3 Bucket 的靜態網站託管端點。
    *   **Viewer Protocol Policy:** 建議設置為 `Redirect HTTP to HTTPS`。
    *   **Cache Behavior:** 配置對 `.css`, `.js`, `.png`, `.jpg` 等文件的緩存時間。
    *   **Custom CNAMEs (Optional):** 如需自定義域名，可在此添加並配置 DNS 解析。
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
*   **(可選) `composer`:** 臨時容器，用於執行 Composer 命令。
*   **(可選) `artisan`:** 臨時容器，用於執行 Laravel Artisan 命令。

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
*   **運行 PHPUnit 測試:** `docker compose run --rm app php artisan test` (或 `docker compose run --rm app vendor/bin/phpunit`)
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
希望這個優化後的 `readme` 文件能更清晰地傳達您的專案內容和技術細節！
