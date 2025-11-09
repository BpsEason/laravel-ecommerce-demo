<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Product;

class DecreaseProductStock implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3; // 重試策略：最多嘗試 3 次

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        Log::info("Attempting to decrease stock for Order ID: {$order->id}");

        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                // 再次鎖定產品以確保庫存操作的原子性
                $product = Product::lockForUpdate()->find($item->product_id);

                if (!$product || $product->stock < $item->quantity) {
                    // 如果庫存不足，則拋出異常，觸發重試或標記訂單失敗
                    Log::error("Insufficient stock for product ID: {$item->product_id} during order {$order->id}. Required: {$item->quantity}, Available: {$product->stock}");
                    throw new \Exception("Insufficient stock for product ID: {$item->product_id}.");
                }

                $product->decrement('stock', $item->quantity);
            }
        });

        Log::info("Stock successfully decreased for Order ID: {$order->id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(OrderCreated $event, $exception): void
    {
        // 處理失敗的庫存扣減，例如：發送通知、記錄日誌、取消訂單 (如果尚未付款)
        Log::critical("Failed to decrease stock for Order ID: {$event->order->id}. Error: {$exception->getMessage()}");
        // 這裡可以觸發另一個事件，例如 OrderStockDecreaseFailed，通知系統管理員或嘗試取消訂單
        // $event->order->update(['status' => 'failed']); // 標記訂單失敗
    }
}