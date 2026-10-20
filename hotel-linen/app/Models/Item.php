<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'category_id','name','unit','norm_per_room','par_level',
        'min_qty','max_qty','life_months','price','is_active',
    ];
    protected $casts = ['is_active' => 'boolean'];

    public function category() { return $this->belongsTo(Category::class); }
    public function stocks()   { return $this->hasMany(Stock::class); }
    public function roomStocks() { return $this->hasMany(RoomStock::class); }

    public function totalQty(): float
    {
        return (float)$this->stocks()->sum('qty') + (float)$this->roomStocks()->sum('qty');
    }
}
