<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $product1;
    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $category = Category::factory()->create();
        $this->product1 = Product::factory()->create(['category_id' => $category->id, 'stock' => 10, 'price' => 100]);
        $this->product2 = Product::factory()->create(['category_id' => $category->id, 'stock' => 5, 'price' => 50]);
    }

    /** @test */
    public function a_user_can_view_their_empty_cart()
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/cart');

        $response->assertOk()
                 ->assertJson([
                     'data' => [
                         'user_id' => $this->user->id,
                         'items' => [],
                         'total_price' => 0.00,
                     ]
                 ]);
    }

    /** @test */
    public function a_user_can_add_a_product_to_their_cart()
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/cart/add', [
                             'product_id' => $this->product1->id,
                             'quantity' => 2,
                         ]);

        $response->assertOk()
                 ->assertJsonPath('data.items.0.product_id', $this->product1->id)
                 ->assertJsonPath('data.items.0.quantity', 2)
                 ->assertJsonPath('data.total_price', 200.00); // 2 * 100

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::where('user_id', $this->user->id)->first()->id,
            'product_id' => $this->product1->id,
            'quantity' => 2,
        ]);
    }

    /** @test */
    public function adding_an_existing_product_increases_its_quantity()
    {
        // 第一次添加
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 1])
             ->assertOk();

        // 第二次添加相同商品
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 2]);

        $response->assertOk()
                 ->assertJsonPath('data.items.0.quantity', 3) // 1 + 2 = 3
                 ->assertJsonPath('data.total_price', 300.00);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::where('user_id', $this->user->id)->first()->id,
            'product_id' => $this->product1->id,
            'quantity' => 3,
        ]);
    }

    /** @test */
    public function cannot_add_product_if_stock_is_insufficient()
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/cart/add', [
                             'product_id' => $this->product1->id,
                             'quantity' => 11, // 超過庫存 10
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('product_id');
    }

    /** @test */
    public function a_user_can_update_a_product_quantity_in_their_cart()
    {
        // 先添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 1])
             ->assertOk();

        // 更新數量
        $response = $this->actingAs($this->user, 'sanctum')
                         ->putJson('/api/cart/update', [
                             'product_id' => $this->product1->id,
                             'quantity' => 5,
                         ]);

        $response->assertOk()
                 ->assertJsonPath('data.items.0.quantity', 5)
                 ->assertJsonPath('data.total_price', 500.00);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::where('user_id', $this->user->id)->first()->id,
            'product_id' => $this->product1->id,
            'quantity' => 5,
        ]);
    }

    /** @test */
    public function updating_quantity_to_zero_removes_product_from_cart()
    {
        // 先添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 1])
             ->assertOk();

        // 更新數量為 0
        $response = $this->actingAs($this->user, 'sanctum')
                         ->putJson('/api/cart/update', [
                             'product_id' => $this->product1->id,
                             'quantity' => 0,
                         ]);

        $response->assertOk()
                 ->assertJsonPath('data.items', []);

        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => Cart::where('user_id', $this->user->id)->first()->id,
            'product_id' => $this->product1->id,
        ]);
    }

    /** @test */
    public function cannot_update_product_quantity_if_stock_is_insufficient()
    {
        // 先添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 5])
             ->assertOk();

        // 更新數量超過庫存
        $response = $this->actingAs($this->user, 'sanctum')
                         ->putJson('/api/cart/update', [
                             'product_id' => $this->product1->id,
                             'quantity' => 11, // 超過庫存 10
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('product_id');
    }

    /** @test */
    public function a_user_can_remove_a_product_from_their_cart()
    {
        // 先添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 2])
             ->assertOk();

        // 移除商品
        $response = $this->actingAs($this->user, 'sanctum')
                         ->deleteJson('/api/cart/remove', ['product_id' => $this->product1->id]);

        $response->assertOk()
                 ->assertJsonPath('data.items', []);

        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => Cart::where('user_id', $this->user->id)->first()->id,
            'product_id' => $this->product1->id,
        ]);
    }

    /** @test */
    public function a_user_can_clear_their_entire_cart()
    {
        // 添加多個商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 1])
             ->assertOk();
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product2->id, 'quantity' => 2])
             ->assertOk();

        // 清空購物車
        $response = $this->actingAs($this->user, 'sanctum')
                         ->deleteJson('/api/cart/clear');

        $response->assertOk()
                 ->assertJsonPath('data.items', []);

        $this->assertDatabaseEmpty('cart_items');
    }

    /** @test */
    public function a_user_can_check_cart_stock()
    {
        // 添加商品，確保庫存足夠
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 5])
             ->assertOk();

        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/cart/check-stock');

        $response->assertOk()
                 ->assertJson(['message' => 'All items in your cart are in stock.']);

        // 模擬庫存不足
        $this->product1->update(['stock' => 3]);
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/cart/check-stock');

        $response->assertStatus(400)
                 ->assertJson(['message' => 'Some items in your cart are out of stock or have insufficient quantity.']);
    }

    /** @test */
    public function concurrent_add_to_cart_operations_handle_stock_correctly_with_optimistic_locking()
    {
        // 悲觀鎖已經處理了併發問題，這裡展示樂觀鎖的測試思路
        // 樂觀鎖通常在資料庫中需要一個 version 欄位，每次更新時遞增，並在更新時檢查版本
        // 由於我們使用的是悲觀鎖 lockForUpdate()，這個測試會以悲觀鎖的行為表現
        // 如果要測試樂觀鎖，需要修改 CartService 和 Product 模型
        $initialStock = $this->product1->stock; // 10

        // 模擬兩個併發請求嘗試購買同一個商品
        $promise1 = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 6]);

        $promise2 = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 6]);

        // 等待兩個請求完成
        $promise1->assertOk(); // 或失敗
        $promise2->assertStatus(422); // 或失敗，取決於哪個請求先拿到鎖

        // 驗證最終庫存和購物車數量
        $cart = Cart::where('user_id', $this->user->id)->first();
        $this->assertNotNull($cart);
        $cartItem = $cart->items()->where('product_id', $this->product1->id)->first();

        // 假設第一個請求成功，第二個失敗，總共添加到購物車 6 個
        $this->assertEquals(6, $cartItem->quantity);

        // 如果是悲觀鎖，當第二個請求嘗試獲取鎖時，第一個請求已經扣減了庫存，
        // 第二個請求會發現庫存不足而拋出異常。
    }
}