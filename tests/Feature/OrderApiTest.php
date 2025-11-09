<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event; // 用於事件測試
use Illuminate\Support\Str; // 用於生成 idempotency key
use Tests\TestCase;
use App\Events\OrderCreated; // 引入事件

class OrderApiTest extends TestCase
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

        // 為用戶創建一個購物車並添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 2])
             ->assertOk();
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product2->id, 'quantity' => 1])
             ->assertOk();
    }

    /** @test */
    public function a_user_can_create_an_order_from_their_cart()
    {
        Event::fake(); // 阻止事件真正觸發，只檢查是否被調度

        $idempotencyKey = Str::uuid()->toString();

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/orders', [
                             'shipping_address' => '123 Main St, City, Country',
                             'billing_address' => '456 Secondary St, City, Country',
                             'idempotency_key' => $idempotencyKey,
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.user_id', $this->user->id)
                 ->assertJsonPath('data.total_amount', 2 * 100 + 1 * 50) // 250
                 ->assertJsonPath('data.status', 'pending') // 初始狀態
                 ->assertJsonPath('data.items.0.product_id', $this->product1->id)
                 ->assertJsonPath('data.items.1.product_id', $this->product2->id);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'total_amount' => 250.00,
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->product1->id,
            'quantity' => 2,
            'price_at_purchase' => 100.00,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->product2->id,
            'quantity' => 1,
            'price_at_purchase' => 50.00,
        ]);

        // 驗證購物車已被清空
        $cart = Cart::where('user_id', $this->user->id)->first();
        $this->assertEmpty($cart->items);

        // 驗證 OrderCreated 事件已被發送
        Event::assertDispatched(OrderCreated::class, function ($event) use ($response) {
            return $event->order->id === $response->json('data.id');
        });

        // 因為庫存扣減是在 Listener 中異步處理，所以這裡不立即檢查 product stock
        // product stock 的檢查應在 OrderCreated Listener 的測試中進行
    }

    /** @test */
    public function order_creation_fails_if_cart_is_empty()
    {
        // 清空用戶購物車
        $this->actingAs($this->user, 'sanctum')
             ->deleteJson('/api/cart/clear')
             ->assertOk();

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/orders', [
                             'shipping_address' => '123 Main St, City, Country',
                             'billing_address' => '456 Secondary St, City, Country',
                             'idempotency_key' => Str::uuid()->toString(),
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['cart']);
    }

    /** @test */
    public function order_creation_fails_if_stock_is_insufficient()
    {
        // 模擬其中一個商品庫存不足
        $this->product1->update(['stock' => 1]); // 購物車裡有 2 個 product1

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/orders', [
                             'shipping_address' => '123 Main St, City, Country',
                             'billing_address' => '456 Secondary St, City, Country',
                             'idempotency_key' => Str::uuid()->toString(),
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['cart']);
    }

    /** @test */
    public function order_creation_is_idempotent()
    {
        Event::fake();

        $idempotencyKey = Str::uuid()->toString();

        // 第一次請求
        $response1 = $this->actingAs($this->user, 'sanctum')
                          ->postJson('/api/orders', [
                              'shipping_address' => 'Address 1',
                              'billing_address' => 'Address 1',
                              'idempotency_key' => $idempotencyKey,
                          ]);
        $response1->assertStatus(201);

        // 第二次使用相同的 idempotency key 請求
        $response2 = $this->actingAs($this->user, 'sanctum')
                          ->postJson('/api/orders', [
                              'shipping_address' => 'Address 2 (should be ignored)',
                              'billing_address' => 'Address 2 (should be ignored)',
                              'idempotency_key' => $idempotencyKey,
                          ]);

        // 第二次請求應該失敗，因為 idempotency_key 已經存在
        $response2->assertStatus(422)
                  ->assertJsonValidationErrors(['idempotency_key']);

        // 驗證只創建了一條訂單
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['idempotency_key' => $idempotencyKey]);

        Event::assertDispatchedTimes(OrderCreated::class, 1); // 事件只發送一次
    }

    /** @test */
    public function a_user_can_view_their_orders()
    {
        Event::fake(); // 阻止事件觸發

        // 創建第一筆訂單
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/orders', [
                 'shipping_address' => 'Address A',
                 'billing_address' => 'Address A',
                 'idempotency_key' => Str::uuid()->toString(),
             ])->assertStatus(201);

        // 清空購物車並添加新的商品以創建第二筆訂單
        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/cart/clear