<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only журнал: кто, когда, что, было, стало, почему. Удаление запрещено. */
class ActionLog extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'user_id','role_name','device','subject_type','subject_id','event',
        'object_label','old_values','new_values','reason','reason_code','ip','created_at',
    ];
    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    // защита от удаления/изменения (append-only)
    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Журнал неизменяем (append-only)'));
        static::deleting(fn () => throw new \RuntimeException('Удаление из журнала запрещено'));
    }

    public function user() { return $this->belongsTo(User::class); }
}
