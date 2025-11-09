<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
| 在電商專案中，這裡確保應用程式實例被正確創建。
| 它的基礎路徑設定正確，對於後續加載配置、路由等至關重要。
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
| 對於電商專案，這些核心介面（HTTP 請求處理、命令行處理、異常處理）
| 是確保系統穩定運行的基石。它們的綁定方式與通用 Laravel 應用程式相同，
| 因為這是框架級別的職責。
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Custom E-commerce Bootstrap / Early Service Provider Registrations
|--------------------------------------------------------------------------
|
| (Optional) In a highly specialized e-commerce project, you might consider
| registering very specific, critical service providers or performing
| bootstrap logic here that *must* happen before all other standard
| providers. This is typically rare and discouraged for maintainability.
|
| However, for specific performance or security needs, if you had a custom
| service that needs to hook into the very early stages of the application
| lifecycle, you might place its registration here or enable specific
| configuration flags based on the environment.
|
| 例如：
| 如果有一個專門用於初始化 Redis 連接池、監控指標收集器
| 或者在所有其他服務之前強制載入某些針對主從資料庫連接的額外配置，
| 理論上可以在這裡進行，但通常更推薦在 AppServiceProvider 或其他
| 服務提供者的 register() 方法中處理，以保持 app.php 的簡潔。
|
| 舉例：如果您的電商專案有一個非常底層的、與資料庫連接管理高度相關的
| 自定義服務提供者，且需要在所有預設資料庫服務提供者之前載入：
| $app->register(App\Providers\CustomDbConnectionServiceProvider::class);
|
| 但通常情況下，標準的 Service Provider 註冊機制 (config/app.php)
| 已經足夠且更易於管理。
|
*/
// if (env('APP_ENV') === 'production') {
//     // 這裡可以放置一些僅生產環境才需要的早期優化配置或服務註冊
//     // 例如，如果有一個自定義的數據庫連接池優化器，需要在此時被應用。
//     // $app->register(App\Providers\DatabasePoolOptimizerServiceProvider::class);
// }


/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the fully ready
| application from the actual running of the application and sending
| the responses.
|
| 應用程式實例最終會被返回。對於電商應用來說，這個已經準備好的應用程式
| 將會處理來自用戶的所有購物、下單、瀏覽等 HTTP 請求，
| 或執行後台的 Artisan 命令（如隊列工作者）。
|
*/

return $app;