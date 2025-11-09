<?php

namespace App\Http\Controllers;

use App\Http\Resources\CartResource;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    protected $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * 取得購物車內容
     * GET /api/cart
     */
    public function index(Request $request)
    {
        $cart = $this->cartService->getCart($request->user());
        $cart->load('items.product'); // 預加載商品信息
        return new CartResource($cart);
    }

    /**
     * 加入商品到購物車
     * POST /api/cart/add
     */
    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = $this->cartService->getCart($request->user());

        try {
            $this->cartService->addItem($cart, $product, $request->quantity);
            $cart->load('items.product');
            return new CartResource($cart);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'product_id' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * 更新購物車商品數量
     * PUT /api/cart/update
     */
    public function update(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:0', // 0 表示移除
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = $this->cartService->getCart($request->user());

        try {
            $this->cartService->updateItem($cart, $product, $request->quantity);
            $cart->load('items.product');
            return new CartResource($cart);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'product_id' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * 從購物車移除商品
     * DELETE /api/cart/remove
     */
    public function remove(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = $this->cartService->getCart($request->user());

        try {
            $this->cartService->removeItem($cart, $product);
            $cart->load('items.product');
            return new CartResource($cart);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'product_id' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * 清空購物車
     * DELETE /api/cart/clear
     */
    public function clear(Request $request)
    {
        $cart = $this->cartService->getCart($request->user());
        $this->cartService->clearCart($cart);
        $cart->load('items.product');
        return new CartResource($cart);
    }

    /**
     * 檢查購物車庫存
     * GET /api/cart/check-stock
     */
    public function checkStock(Request $request)
    {
        $cart = $this->cartService->getCart($request->user());
        if (!$this->cartService->checkCartStock($cart)) {
            return response()->json(['message' => 'Some items in your cart are out of stock or have insufficient quantity.'], 400);
        }
        return response()->json(['message' => 'All items in your cart are in stock.']);
    }
}