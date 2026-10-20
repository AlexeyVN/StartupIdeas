<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Остаточная позиция: item × локация (склад/прачечная/списано). Бельё — количественный учёт. */
class Stock extends Model
{
    protected $fillable = ['item_id', 'location_id', 'qty', 'version'];
    public function item() { return $this->belongsTo(Item::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
