<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 模擬支付網關服務
 * 假設有一個外部的 Mock Payment Gateway API
 */
class MockPaymentGatewayService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('app.mock_payment_gateway_url', 'http://mock-payment-gateway.test');
        $this->apiKey = config('app.mock_payment_gateway_api_key', 'mock_api_key');
    }

    /**
     * 處理支付請求
     * @return array 包含 transaction_id 和 status
     */
    public function processPayment(Order $order, array $paymentDetails)
    {
        try {
            $response = Http::post("{$this->baseUrl}/api/v1/pay", [
                'api_key' => $this->apiKey,
                'order_id' => $order->id,
                'amount' => $order->total_amount,
                'currency' => 'USD', // 假設固定 USD
                'card_details' => $paymentDetails, // 例如卡號、有效期、CVV
                'callback_url' => route('api.payment.mock.webhook'), // 回調 URL
            ]);

            $response->throw(); // 如果響應不是 2xx 則拋出異常

            $data = $response->json();

            // 根據 Mock Payment Gateway 的響應格式返回
            return [
                'transaction_id' => $data['transaction_id'] ?? null,
                'status' => $data['status'] ?? 'pending', // pending, success, failed
                'meta' => $data,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Mock Payment Gateway Payment Failed for Order ID: {$order->id}. Error: " . $e->getMessage());
            return [
                'transaction_id' => null,
                'status' => 'failed',
                'meta' => ['error' => $e->getMessage(), 'response' => $e->response->json()],
            ];
        }
    }

    /**
     * 處理 Mock Payment Gateway 的 Webhook 通知
     * 該方法通常由一個 Job 異步處理
     */
    public function handleWebhook(array $payload)
    {
        Log::info('Received Mock Payment Gateway Webhook', $payload);

        $orderId = $payload['order_id'] ?? null;
        $transactionId = $payload['transaction_id'] ?? null;
        $status = $payload['status'] ?? 'failed';

        if (!$orderId || !$transactionId) {
            Log::warning('Mock Payment Webhook: Missing order_id or transaction_id', $payload);
            return false;
        }

        $payment = Payment::where('transaction_id', $transactionId)->first();

        if (!$payment) {
            // 如果 Payment 記錄不存在，則可能需要在這裡創建
            Log::warning('Mock Payment Webhook: Payment not found for transaction ID: ' . $transactionId);
            // 嘗試根據 order_id 查找並創建 Payment 記錄
            $order = Order::find($orderId);
            if ($order) {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => $payload['amount'] ?? $order->total_amount,
                    'currency' => $payload['currency'] ?? 'USD',
                    'method' => 'mock',
                    'status' => 'pending', // 暫時設置為 pending，等待後續更新
                    'meta' => $payload,
                ]);
            } else {
                Log::error('Mock Payment Webhook: Order not found for ID: ' . $orderId);
                return false;
            }
        }

        // 更新 Payment 狀態
        $payment->status = $status === 'success' ? 'completed' : 'failed';
        $payment->meta = array_merge($payment->meta, $payload); // 合併新的 webhook 資料
        $payment->save();

        // 更新 Order 狀態
        if ($payment->status === 'completed') {
            $payment->order->update(['status' => 'completed']);
            Log::info("Mock Payment Gateway: Order {$payment->order_id} payment completed. Transaction ID: {$transactionId}");
        } elseif ($payment->status === 'failed') {
            $payment->order->update(['status' => 'failed']);
            Log::warning("Mock Payment Gateway: Order {$payment->order_id} payment failed. Transaction ID: {$transactionId}");
        }
        return true;
    }

    /**
     * 發起退款
     */
    public function refund(Payment $payment, float $amount = null)
    {
        if ($payment->method !== 'mock' || $payment->status !== 'completed') {
            throw new \Exception("Invalid payment for refund.");
        }

        try {
            $response = Http::post("{$this->baseUrl}/api/v1/refund", [
                'api_key' => $this->apiKey,
                'transaction_id' => $payment->transaction_id,
                'amount' => $amount,
            ]);

            $response->throw();

            $data = $response->json();

            if ($data['status'] === 'refunded') {
                $payment->update([
                    'status' => 'refunded',
                    'meta' => array_merge($payment->meta, ['refund_details' => $data]),
                ]);
                $payment->order->update(['status' => 'refunded']);
                Log::info("Mock Payment Gateway refund successful for Payment ID: {$payment->id}, Transaction ID: {$payment->transaction_id}");
                return true;
            } else {
                Log::error("Mock Payment Gateway refund failed for Payment ID: {$payment->id}. Response: " . json_encode($data));
                return false;
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Mock Payment Gateway refund failed for Payment ID: {$payment->id}. Error: " . $e->getMessage());
            throw $e;
        }
    }
}