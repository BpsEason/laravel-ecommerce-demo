<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MockPaymentGatewayService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    protected $stripeService;
    protected $mockPaymentService;

    public function __construct(StripeService $stripeService, MockPaymentGatewayService $mockPaymentService)
    {
        $this->stripeService = $stripeService;
        $this->mockPaymentService = $mockPaymentService;
    }

    /**
     * 發起 Stripe 支付
     * POST /api/payment/stripe/checkout
     */
    public function createStripeCheckoutSession(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'success_url' => 'required|url',
            'cancel_url' => 'required|url',
        ]);

        $order = $request->user()->orders()->where('status', 'pending')->findOrFail($request->order_id);

        try {
            $checkoutSession = $this->stripeService->createCheckoutSession(
                $order,
                $request->success_url,
                $request->cancel_url
            );

            return response()->json([
                'checkout_url' => $checkoutSession->url,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create Stripe checkout session for order {$order->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create Stripe checkout session.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 處理 Stripe Webhook
     * POST /api/webhook/stripe
     * 不受 Sanctum 認證保護
     */
    public function handleStripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('stripe-signature');

        // 將 Webhook 處理推送到佇列中，異步處理，避免 Webhook 超時
        ProcessPaymentWebhook::dispatch('stripe', json_decode($payload, true), $signature);

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * 使用 Mock Payment Gateway 進行支付
     * POST /api/payment/mock/pay
     */
    public function mockPayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'card_details' => 'required|array', // 示例：卡號、有效期、CVV
            'card_details.number' => 'required|string',
            'card_details.expiry_month' => 'required|integer|min:1|max:12',
            'card_details.expiry_year' => 'required|integer',
            'card_details.cvv' => 'required|string',
        ]);

        $order = $request->user()->orders()->where('status', 'pending')->findOrFail($request->order_id);

        try {
            $result = $this->mockPaymentService->processPayment($order, $request->card_details);

            if ($result['status'] === 'failed') {
                throw new \Exception($result['meta']['error'] ?? 'Mock payment failed.');
            }

            // 創建支付記錄
            $payment = Payment::create([
                'order_id' => $order->id,
                'transaction_id' => $result['transaction_id'],
                'amount' => $order->total_amount,
                'currency' => 'USD', // 假設
                'method' => 'mock',
                'status' => $result['status'] === 'success' ? 'completed' : 'pending', // 如果是異步回調，則先 pending
                'meta' => $result['meta'],
            ]);

            // 如果是同步支付成功，則更新訂單狀態
            if ($payment->status === 'completed') {
                $order->update(['status' => 'completed']);
            }

            return new PaymentResource($payment->load('order'));

        } catch (\Exception $e) {
            Log::error("Failed to process mock payment for order {$order->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to process mock payment.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 處理 Mock Payment Gateway Webhook
     * POST /api/webhook/mock
     * 不受 Sanctum 認證保護
     */
    public function handleMockWebhook(Request $request)
    {
        // 將 Webhook 處理推送到佇列中
        ProcessPaymentWebhook::dispatch('mock', $request->all());

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * 發起退款
     * POST /api/payment/{payment}/refund
     */
    public function refund($paymentId, Request $request)
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $payment = $request->user()->payments()->where('id', $paymentId)->firstOrFail();

        try {
            if ($payment->method === 'stripe') {
                $this->stripeService->refund($payment, $request->amount);
            } elseif ($payment->method === 'mock') {
                $this->mockPaymentService->refund($payment, $request->amount);
            } else {
                throw new \Exception("Unsupported payment method for refund: " . $payment->method);
            }

            return response()->json(['message' => 'Refund initiated successfully.', 'payment' => new PaymentResource($payment->load('order'))]);
        } catch (\Exception $e) {
            Log::error("Refund failed for payment {$payment->id}: " . $e->getMessage());
            return response()->json(['message' => 'Refund failed.', 'error' => $e->getMessage()], 500);
        }
    }
}