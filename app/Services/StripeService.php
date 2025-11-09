<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * 創建 Stripe Checkout Session
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl)
    {
        $lineItems = $order->items->map(function ($item) {
            return [
                'price_data' => [
                    'currency' => 'usd', // 或其他貨幣
                    'product_data' => [
                        'name' => $item->product->name,
                    ],
                    'unit_amount' => (int) ($item->price_at_purchase * 100), // Stripe 金額以最小單位計算
                ],
                'quantity' => $item->quantity,
            ];
        })->toArray();

        // Add shipping if applicable
        // $lineItems[] = [
        //     'price_data' => [
        //         'currency' => 'usd',
        //         'product_data' => ['name' => 'Shipping Cost'],
        //         'unit_amount' => 500, // $5.00
        //     ],
        //     'quantity' => 1,
        // ];

        $checkoutSession = Session::create([
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $order->id, // 將訂單 ID 傳遞給 Stripe
            'metadata' => [
                'order_id' => $order->id,
            ],
        ]);

        return $checkoutSession;
    }

    /**
     * 處理 Stripe Webhook 事件
     */
    public function handleWebhook(string $payload, string $signature)
    {
        $event = null;

        try {
            $event = Webhook::constructEvent(
                $payload, $signature, config('services.stripe.webhook_secret')
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe Webhook Error: Invalid payload', ['error' => $e->getMessage()]);
            return false;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe Webhook Error: Invalid signature', ['error' => $e->getMessage()]);
            return false;
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $orderId = $session->metadata->order_id;
                $paymentIntentId = $session->payment_intent;

                $order = Order::find($orderId);
                if ($order && $order->status === 'pending') {
                    // 創建支付記錄
                    Payment::create([
                        'order_id' => $order->id,
                        'transaction_id' => $paymentIntentId,
                        'amount' => $session->amount_total / 100, // 轉回實際金額
                        'currency' => strtoupper($session->currency),
                        'method' => 'stripe',
                        'status' => 'completed',
                        'meta' => $session->toArray(),
                    ]);

                    $order->update(['status' => 'completed']);
                    Log::info("Order {$order->id} payment completed via Stripe. Transaction ID: {$paymentIntentId}");
                }
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                Log::warning("Stripe Payment Intent Failed: {$paymentIntent->id}", ['error' => $paymentIntent->last_payment_error->message ?? 'N/A']);
                // 處理支付失敗邏輯，例如更新訂單狀態為 failed
                $orderId = $paymentIntent->metadata->order_id ?? null; // 如果在 Payment Intent 中設置了 metadata
                if ($orderId) {
                    $order = Order::find($orderId);
                    if ($order && $order->status === 'pending') {
                        $order->update(['status' => 'failed']);
                        Log::info("Order {$order->id} payment failed via Stripe.");
                    }
                }
                break;
            // 其他事件類型，例如 refund.succeeded, charge.refunded 等
            case 'charge.refunded':
                $charge = $event->data->object;
                $payment = Payment::where('transaction_id', $charge->payment_intent)
                                ->where('status', 'completed')
                                ->first();

                if ($payment) {
                    $payment->update(['status' => 'refunded']);
                    $payment->order->update(['status' => 'refunded']);
                    Log::info("Payment for Order ID: {$payment->order_id} refunded via Stripe. Charge ID: {$charge->id}");
                }
                break;
            default:
                Log::info('Received unknown Stripe event type ' . $event->type);
        }

        return true;
    }

    /**
     * 發起退款
     */
    public function refund(Payment $payment, float $amount = null)
    {
        if ($payment->method !== 'stripe' || $payment->status !== 'completed') {
            throw new \Exception("Invalid payment for refund.");
        }

        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment->transaction_id,
                'amount' => $amount ? (int) ($amount * 100) : null, // 單位是分，如果為空則全額退款
            ]);

            // 更新支付狀態
            $payment->update([
                'status' => 'refunded',
                'meta' => array_merge($payment->meta, ['refund_id' => $refund->id]),
            ]);
            $payment->order->update(['status' => 'refunded']); // 更新訂單狀態

            Log::info("Stripe refund successful for Payment ID: {$payment->id}, Refund ID: {$refund->id}");
            return $refund;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error("Stripe refund failed for Payment ID: {$payment->id}. Error: " . $e->getMessage());
            throw $e;
        }
    }
}