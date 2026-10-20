<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Штучная единица с инвентарным номером/QR — аналог Asset из Snipe-IT */
class Asset extends Model
{
    protected $fillable = ['asset_tag','item_id','room_id','location_id','status','purchase_date','note'];
    public function item() { return $this->belongsTo(Item::class); }
    public function room() { return $this->belongsTo(Room::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function movements() { return $this->hasMany(AssetMovement::class); }

    public function whereLabel(): string
    {
        if ($this->room_id) return 'Номер ' . $this->room?->number;
        return $this->location?->name ?? '—';
    }
}
