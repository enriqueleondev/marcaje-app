<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\MarkState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceMark extends Model
{
    protected $fillable = ['roll_call_id', 'student_id', 'entry_id', 'status', 'marked_by', 'marked_at'];

    protected function casts(): array
    {
        return ['status' => AttendanceStatus::class, 'marked_at' => 'datetime'];
    }

    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function state(): MarkState
    {
        return MarkState::fromDecision($this->status, $this->entry_id !== null);
    }
}
