<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// =========================================================================
// 公開路由 (無需認證即可訪問)
// =========================================================================

// --- 認證相關路由 ---
/**
 * 用戶註冊
 * @method POST
 * @uri /api/register
 */
Route::post('/register', [AuthController::class, 'register']);

/**
 * 用戶登入並獲取 API Token
 * @method POST
 * @uri /api/login
 */
Route::post('/login', [AuthController::class, 'login']);

// --- 商品相關公開讀取路由 ---
/**
 * 取得所有商品列表 (支援搜尋、分類、分頁)
 * @method GET
 * @uri /api/products
 */
Route::get('/products', [ProductController::class, 'index']);

/**
 * 取得單一商品詳情
 * @method GET
 * @uri /api/products/{id}
 */
Route::get('/products/{id}', [ProductController::class, 'show']);

/**
 * 取得所有商品分類列表
 * @method GET
 * @uri /api/categories
 */
Route::get('/categories', [ProductController::class, 'categories']);

// --- 支付網關 Webhook 路由 ---
// 這些路由通常由第三方支付服務器調用，無需用戶認證
/**
 * 處理 Stripe 支付網關的 Webhook 通知
 * @method POST
 * @uri /api/webhook/stripe
 * @name api.payment.stripe.webhook
 */
Route::post('/webhook/stripe', [PaymentController::class, 'handleStripeWebhook'])->name('api.payment.stripe.webhook');

/**
 * 處理 Mock 支付網關的 Webhook 通知
 * @method POST
 * @uri /api/webhook/mock
 * @name api.payment.mock.webhook
 */
Route::post('/webhook/mock', [PaymentController::class, 'handleMockWebhook'])->name('api.payment.mock.webhook');


// =========================================================================
// 受保護路由 (需通過 Laravel Sanctum 認證)
// =========================================================================
Route::middleware('auth:sanctum')->group(function () {

    // --- 認證與會員管理路由 ---
    /**
     * 用戶登出 (撤銷當前 API Token)
     * @method POST
     * @uri /api/logout
     */
    Route::post('/logout', [AuthController::class, 'logout']);

    /**
     * 取得當前認證用戶的會員資料
     * @method GET
     * @uri /api/user
     */
    Route::get('/user', [AuthController::class, 'user']);

    /**
     * 更新當前認證用戶的會員資料
     * @method PUT
     * @uri /api/user
     */
    Route::put('/user', [AuthController::class, 'update']);


    // --- 商品管理路由 (假設只有管理員可以執行，但這裡僅作認證要求示範) ---
    /**
     * 新增商品 (需要認證)
     * @method POST
     * @uri /api/products
     */
    Route::post('/products', [ProductController::class, 'store']);

    // TODO: 其他商品管理路由，例如：
    // Route::put('/products/{id}', [ProductController::class, 'update']);    // 更新商品
    // Route::delete('/products/{id}', [ProductController::class, 'destroy']); // 刪除商品


    // --- 購物車管理路由 ---
    /**
     * 取得當前用戶的購物車內容
     * @method GET
     * @uri /api/cart
     */
    Route::get('/cart', [CartController::class, 'index']);

    /**
     * 加入商品到購物車
     * @method POST
     * @uri /api/cart/add
     */
    Route::post('/cart/add', [CartController::class, 'add']);

    /**
     * 更新購物車中商品的數量
     * @method PUT
     * @uri /api/cart/update
     */
    Route::put('/cart/update', [CartController::class, 'update']);

    /**
     * 從購物車中移除商品
     * @method DELETE
     * @uri /api/cart/remove
     */
    Route::delete('/cart/remove', [CartController::class, 'remove']);

    /**
     * 清空購物車
     * @method DELETE
     * @uri /api/cart/clear
     */
    Route::delete('/cart/clear', [CartController::class, 'clear']);

    /**
     * 檢查購物車內所有商品的庫存狀態
     * @method GET
     * @uri /api/cart/check-stock
     */
    Route::get('/cart/check-stock', [CartController::class, 'checkStock']);


    // --- 訂單管理路由 ---
    /**
     * 建立新訂單 (從購物車中結帳)
     * @method POST
     * @uri /api/orders
     */
    Route::post('/orders', [OrderController::class, 'store']);

    /**
     * 取得當前認證用戶的所有訂單列表
     * @method GET
     * @uri /api/orders
     */
    Route::get('/orders', [OrderController::class, 'index']);

    /**
     * 取得單一訂單的詳情
     * @method GET
     * @uri /api/orders/{id}
     */
    Route::get('/orders/{id}', [OrderController::class, 'show']);


    // --- 金流支付與退款路由 ---
    /**
     * 創建 Stripe Checkout Session (引導用戶跳轉到 Stripe 進行支付)
     * @method POST
     * @uri /api/payment/stripe/checkout
     */
    Route::post('/payment/stripe/checkout', [PaymentController::class, 'createStripeCheckoutSession']);

    /**
     * 使用 Mock Payment Gateway 進行支付 (用於開發/測試環境)
     * @method POST
     * @uri /api/payment/mock/pay
     */
    Route::post('/payment/mock/pay', [PaymentController::class, 'mockPayment']);

    /**
     * 對指定支付記錄發起退款
     * @method POST
     * @uri /api/payment/{payment}/refund
     */
    Route::post('/payment/{payment}/refund', [PaymentController::class, 'refund']);

});