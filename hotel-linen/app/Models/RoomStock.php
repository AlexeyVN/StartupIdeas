<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomStock extends Model
{
    protected $table = 'room_stocks';
    protected $fillable = ['room_id', 'item_id', 'qty'];
    public function room() { return $this->belongsTo(Room::class); }
    public function item() { return $this->belongsTo(Item::class); }
}
