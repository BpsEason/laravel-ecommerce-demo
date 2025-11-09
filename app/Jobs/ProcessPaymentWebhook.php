<?php

namespace App\Jobs;

use App\Services\MockPaymentGatewayService;
use App\Services\StripeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $gateway;
    protected $payload;
    protected $signature; // For Stripe

    /**
     * Create a new job instance.
     */
    public function __construct(string $gateway, array $payload, string $signature = null)
    {
        $this->gateway = $gateway;
        $this->payload = $payload;
        $this->signature = $signature;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        switch ($this->gateway) {
            case 'stripe':
                $stripeService = app(StripeService::class);
                if (!$stripeService->handleWebhook(json_encode($this->payload), $this->signature)) {
                    Log::error("Stripe Webhook processing failed for payload: " . json_encode($this->payload));
                    // 可以選擇重新拋出異常讓 Job 重試
                    // throw new \Exception("Stripe webhook handling failed.");
                }
                break;
            case 'mock':
                $mockService = app(MockPaymentGatewayService::class);
                if (!$mockService->handleWebhook($this->payload)) {
                    Log::error("Mock Payment Gateway Webhook processing failed for payload: " . json_encode($this->payload));
                    // throw new \Exception("Mock payment webhook handling failed.");
                }
                break;
            default:
                Log::warning("Unknown payment gateway for webhook: " . $this->gateway);
                break;
        }
    }
}