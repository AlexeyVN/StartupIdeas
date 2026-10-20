<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = ['date', 'period', 'maid_id'];
    public function maid()  { return $this->belongsTo(User::class, 'maid_id'); }
    public function rooms() { return $this->hasMany(ShiftRoom::class); }
}
