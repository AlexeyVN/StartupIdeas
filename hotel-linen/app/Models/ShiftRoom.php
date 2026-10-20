<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftRoom extends Model
{
    public $timestamps = false;
    protected $fillable = ['shift_id', 'room_id', 'task_kind'];
    public function shift() { return $this->belongsTo(Shift::class); }
    public function room()  { return $this->belongsTo(Room::class); }
}
