<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Справочник причин списания и расхождений — отвечает на вопрос «почему» */
class Reason extends Model
{
    protected $fillable = ['code', 'title', 'kind', 'is_active'];
    public function scopeDiscrepancy($q) { return $q->whereIn('kind', ['discrepancy', 'both']); }
    public function scopeWriteOff($q)    { return $q->whereIn('kind', ['write_off', 'both']); }
}
