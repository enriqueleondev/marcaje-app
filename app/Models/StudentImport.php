<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de altas masivas por CSV. */
class StudentImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
