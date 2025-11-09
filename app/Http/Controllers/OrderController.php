<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    protected $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * 建立新訂單
     * POST /api/orders
     */
    public function store(Request $request)
    {
        $request->validate([
            'shipping_address' => 'required|string|max:255',
            'billing_address' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:255|unique:orders,idempotency_key', // Idempotency Key
        ]);

        $user = $request->user();
        $cart = $this->cartService->getCart($user);
        $cart->load('items.product');

        // 檢查購物車是否為空
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is empty.'],
            ]);
        }

        // 檢查購物車內所有商品的庫存
        if (!$this->cartService->checkCartStock($cart)) {
            throw ValidationException::withMessages([
                'cart' => ['Some items in your cart are out of stock or have insufficient quantity.'],
            ]);
        }

        $order = null;

        try {
            DB::transaction(function () use ($request, $user, $cart, &$order) {
                // 創建訂單
                $order = Order::create([
                    'user_id' => $user->id,
                    'total_amount' => $cart->total_price,
                    'status' => 'pending', // 初始狀態為待處理
                    'idempotency_key' => $request->idempotency_key,
                    'shipping_address' => $request->shipping_address,
                    'billing_address' => $request->billing_address,
                ]);

                // 創建訂單項目並扣減庫存 (這裡只做一次性扣減，實際的扣減邏輯在 Listener 中處理)
                foreach ($cart->items as $cartItem) {
                    $order->items()->create([
                        'product_id' => $cartItem->product_id,
                        'quantity' => $cartItem->quantity,
                        'price_at_purchase' => $cartItem->product->price,
                    ]);
                }

                // 清空購物車
                $this->cartService->clearCart($cart);

                // 發送訂單創建事件到佇列
                OrderCreated::dispatch($order);
            });

            return new OrderResource($order->load('items.product'));

        } catch (\Exception $e) {
            // 記錄錯誤並返回適當的響應
            Log::error("Failed to create order for user {$user->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create order.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 取得用戶所有訂單
     * GET /api/orders
     */
    public function index(Request $request)
    {
        $orders = $request->user()->orders()->with('items.product')->orderByDesc('created_at')->paginate(10);
        return OrderResource::collection($orders);
    }

    /**
     * 取得訂單詳情
     * GET /api/orders/{id}
     */
    public function show($id, Request $request)
    {
        $order = $request->user()->orders()->with('items.product', 'payments')->findOrFail($id);
        return new OrderResource($order);
    }
}