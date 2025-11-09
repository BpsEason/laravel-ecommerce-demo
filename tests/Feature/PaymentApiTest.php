<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;
use Mockery; // 引入 Mockery

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Order $order;
    protected Product $product1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $category = Category::factory()->create();
        $this->product1 = Product::factory()->create(['category_id' => $category->id, 'stock' => 10, 'price' => 100]);

        // 為用戶創建一個購物車並添加商品
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/cart/add', ['product_id' => $this->product1->id, 'quantity' => 2])
             ->assertOk();

        // 創建一個待支付的訂單
        $orderResponse = $this->actingAs($this->user, 'sanctum')
                              ->postJson('/api/orders', [
                                  'shipping_address' => '123 Payment St',
                                  'billing_address' => '123 Payment St',
                                  'idempotency_key' => Str::uuid()->toString(),
                              ]);
        $orderResponse->assertStatus(201);
        $this->order = Order::find($orderResponse->json('data.id'));

        // 清空 Mockery 的 mocks
        Mockery::close();
    }

    /** @test */
    public function a_user_can_create_a_stripe_checkout_session()
    {
        // Mock StripeService，避免真實呼叫 Stripe API
        $stripeServiceMock = Mockery::mock(\App\Services\StripeService::class);
        $stripeServiceMock->shouldReceive('createCheckoutSession')
                          ->once()
                          ->andReturn((object)['url' => 'https://checkout.stripe.com/mock-session-id']);

        $this->app->instance(\App\Services\StripeService::class, $stripeServiceMock);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/payment/stripe/checkout', [
                             'order_id' => $this->order->id,
                             'success_url' => 'http://localhost:8000/success',
                             'cancel_url' => 'http://localhost:8000/cancel',
                         ]);

        $response->assertOk()
                 ->assertJsonStructure(['checkout_url']);
    }

    /** @test */
    public function stripe_webhook_can_be_received_and_processed()
    {
        Event::fake(); // 阻止實際 Job 調度，只驗證 Job 是否被派遣

        // 模擬 Stripe Webhook payload (checkout.session.completed 事件)
        $payload = [
            'id' => 'evt_test_webhook',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_session_id',
                    'object' => 'checkout.session',
                    'payment_intent' => 'pi_test_payment_intent',
                    'amount_total' => $this->order->total_amount * 100, // Stripe 金額是分
                    'currency' => 'usd',
                    'metadata' => [
                        'order_id' => $this->order->id,
                    ],
                ],
            ],
        ];

        // Mock Stripe Webhook 驗證和處理
        $stripeServiceMock = Mockery::mock(\App\Services\StripeService::class);
        $stripeServiceMock->shouldReceive('handleWebhook')
                          ->once()
                          ->andReturn(true); // 模擬成功處理

        $this->app->instance(\App\Services\StripeService::class, $stripeServiceMock);

        $response = $this->postJson('/api/webhook/stripe', $payload, [
            'Stripe-Signature' => 't=123456789,v1=abcdef...' // 模擬簽名
        ]);

        $response->assertOk()
                 ->assertJson(['status' => 'success']);

        // 驗證 ProcessPaymentWebhook Job 是否被派遣
        Event::assertDispatched(\App\Jobs\ProcessPaymentWebhook::class, function ($job) use ($payload) {
            return $job->gateway === 'stripe' && $job->payload['id'] === $payload['id'];
        });

        // 由於我們在測試中 mock 了 StripeService，且 Job 也是異步的，
        // 所以這裡不會直接檢查資料庫中的 Payment 和 Order 狀態變化。
        // 相關的狀態變更應在 Job 的單元測試或集成測試中進行。
    }

    /** @test */
    public function a_user_can_pay_with_mock_payment_gateway()
    {
        // Mock MockPaymentGatewayService
        $mockPaymentServiceMock = Mockery::mock(\App\Services\MockPaymentGatewayService::class);
        $mockPaymentServiceMock->shouldReceive('processPayment')
                               ->once()
                               ->andReturn([
                                   'transaction_id' => 'mock_txn_123',
                                   'status' => 'success',
                                   'meta' => ['mock_response' => 'payment_successful'],
                               ]);

        $this->app->instance(\App\Services\MockPaymentGatewayService::class, $mockPaymentServiceMock);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/payment/mock/pay', [
                             'order_id' => $this->order->id,
                             'card_details' => [
                                 'number' => '1111222233334444',
                                 'expiry_month' => 12,
                                 'expiry_year' => 2025,
                                 'cvv' => '123',
                             ],
                         ]);

        $response->assertOk()
                 ->assertJsonPath('data.order_id', $this->order->id)
                 ->assertJsonPath('data.transaction_id', 'mock_txn_123')
                 ->assertJsonPath('data.status', 'completed')
                 ->assertJsonPath('data.method', 'mock');

        // 驗證 Payment 記錄已創建
        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'transaction_id' => 'mock_txn_123',
            'status' => 'completed',
            'method' => 'mock',
            'amount' => $this->order->total_amount,
        ]);

        // 驗證 Order 狀態已更新
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function mock_payment_gateway_webhook_can_be_received_and_processed()
    {
        Event::fake(); // 阻止實際 Job 調度

        // 模擬 Mock Payment Gateway Webhook payload (支付成功事件)
        $payload = [
            'event' => 'payment_success',
            'order_id' => $this->order->id,
            'transaction_id' => 'mock_webhook_txn_456',
            'amount' => $this->order->total_amount,
            'currency' => 'USD',
            'status' => 'success',
            'timestamp' => now()->toDateTimeString(),
        ];

        // 為了讓 handleWebhook 成功更新，我們需要一個 Payment 記錄存在
        // 或 mock handleWebhook 中的 Payment::where(...)->first() 行為
        Payment::create([
            'order_id' => $this->order->id,
            'transaction_id' => 'mock_webhook_txn_456',
            'amount' => $this->order->total_amount,
            'currency' => 'USD',
            'method' => 'mock',
            'status' => 'pending',
            'meta' => [],
        ]);

        $mockPaymentServiceMock = Mockery::mock(\App\Services\MockPaymentGatewayService::class);
        $mockPaymentServiceMock->shouldReceive('handleWebhook')
                               ->once()
                               ->andReturn(true);

        $this->app->instance(\App\Services\MockPaymentGatewayService::class, $mockPaymentServiceMock);

        $response = $this->postJson('/api/webhook/mock', $payload);

        $response->assertOk()
                 ->assertJson(['status' => 'success']);

        // 驗證 ProcessPaymentWebhook Job 是否被派遣
        Event::assertDispatched(\App\Jobs\ProcessPaymentWebhook::class, function ($job) use ($payload) {
            return $job->gateway === 'mock' && $job->payload['transaction_id'] === $payload['transaction_id'];
        });
    }


    /** @test */
    public function a_user_can_refund_a_payment()
    {
        // 創建一個已完成的支付
        $payment = Payment::create([
            'order_id' => $this->order->id,
            'transaction_id' => 'test_txn_refund_123',
            'amount' => $this->order->total_amount,
            'currency' => 'USD',
            'method' => 'stripe',
            'status' => 'completed',
            'meta' => [],
        ]);
        $this->order->update(['status' => 'completed']); // 更新訂單狀態為已完成

        // Mock StripeService 的 refund 方法
        $stripeServiceMock = Mockery::mock(\App\Services\StripeService::class);
        $stripeServiceMock->shouldReceive('refund')
                          ->once()
                          ->andReturn(true); // 模擬退款成功

        $this->app->instance(\App\Services\StripeService::class, $stripeServiceMock);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson("/api/payment/{$payment->id}/refund", [
                             'amount' => 50.00, // 部分退款
                         ]);

        $response->assertOk()
                 ->assertJson(['message' => 'Refund initiated successfully.']);

        // 由於 refund 方法已被 mock，這裡無法直接驗證資料庫狀態。
        // 在實際應用中，Stripe Webhook 會通知退款結果，進而更新資料庫。
        // 或者可以在 mock 中添加資料庫斷言。
    }

    /** @test */
    public function refund_fails_for_unsupported_payment_method()
    {
        $payment = Payment::create([
            'order_id' => $this->order->id,
            'transaction_id' => 'unsupported_txn',
            'amount' => $this->order->total_amount,
            'currency' => 'USD',
            'method' => 'unsupported_method', // 模擬不支持的支付方式
            'status' => 'completed',
            'meta' => [],
        ]);
        $this->order->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson("/api/payment/{$payment->id}/refund");

        $response->assertStatus(500)
                 ->assertJson(['message' => 'Refund failed.']);
    }
}