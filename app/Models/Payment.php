<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'transaction_id', // 第三方支付平台的交易 ID
        'amount',
        'currency',
        'method', // stripe, mock
        'status', // pending, completed, failed, refunded
        'meta', // 儲存支付平台回傳的額外資訊
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}