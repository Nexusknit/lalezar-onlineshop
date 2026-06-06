<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingInvoiceMapping extends Model
{
    protected $fillable = [
        'invoice_id',
        'provider',
        'external_id',
        'idempotency_key',
        'status',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
