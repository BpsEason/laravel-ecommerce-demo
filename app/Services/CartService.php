<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class CartService
{
    /**
     * 取得當前用戶的購物車 (Session 或 DB)
     */
    public function getCart($user = null)
    {
        if ($user) {
            return Cart::firstOrCreate(['user_id' => $user->id]);
        } else {
            // 對於未登入用戶，使用 session_id 綁定購物車
            $sessionId = Session::getId();
            return Cart::firstOrCreate(['session_id' => $sessionId, 'user_id' => null]);
        }
    }

    /**
     * 加入商品到購物車
     */
    public function addItem(Cart $cart, Product $product, $quantity = 1)
    {
        // 悲觀鎖範例：在新增或更新購物車商品前鎖定相關商品庫存
        // 這樣可以防止多個請求同時修改同一商品的庫存，導致超賣
        return DB::transaction(function () use ($cart, $product, $quantity) {
            $product->lockForUpdate()->find($product->id); // 悲觀鎖定產品

            if ($product->stock < $quantity) {
                throw new \Exception("Product '{$product->name}' is out of stock or insufficient quantity available.");
            }

            $cartItem = $cart->items()->where('product_id', $product->id)->first();

            if ($cartItem) {
                $cartItem->quantity += $quantity;
                $cartItem->save();
            } else {
                $cartItem = $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]);
            }
            return $cartItem;
        });
    }

    /**
     * 更新購物車商品數量
     */
    public function updateItem(Cart $cart, Product $product, $quantity)
    {
        if ($quantity <= 0) {
            return $this->removeItem($cart, $product);
        }

        // 悲觀鎖範例
        return DB::transaction(function () use ($cart, $product, $quantity) {
            $product->lockForUpdate()->find($product->id); // 悲觀鎖定產品

            if ($product->stock < $quantity) {
                throw new \Exception("Product '{$product->name}' is out of stock or insufficient quantity available.");
            }

            $cartItem = $cart->items()->where('product_id', $product->id)->firstOrFail();
            $cartItem->quantity = $quantity;
            $cartItem->save();
            return $cartItem;
        });
    }

    /**
     * 從購物車移除商品
     */
    public function removeItem(Cart $cart, Product $product)
    {
        $cartItem = $cart->items()->where('product_id', $product->id)->firstOrFail();
        $cartItem->delete();
        return true;
    }

    /**
     * 取得購物車總價
     */
    public function getCartTotalPrice(Cart $cart)
    {
        return $cart->total_price;
    }

    /**
     * 檢查購物車內所有商品的庫存
     */
    public function checkCartStock(Cart $cart)
    {
        foreach ($cart->items as $item) {
            // 讀取操作走 Read Replica
            $product = Product::on('mysql_read')->find($item->product_id);
            if (!$product || $product->stock < $item->quantity) {
                return false;
            }
        }
        return true;
    }

    /**
     * 清空購物車
     */
    public function clearCart(Cart $cart)
    {
        $cart->items()->delete();
        return true;
    }
}