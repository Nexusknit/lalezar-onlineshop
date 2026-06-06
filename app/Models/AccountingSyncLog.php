<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountingSyncLog extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'retry_of_id',
        'triggered_by',
        'provider',
        'operation',
        'syncable_type',
        'syncable_id',
        'status',
        'attempts',
        'payload',
        'response',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'payload' => 'array',
        'response' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }
}
