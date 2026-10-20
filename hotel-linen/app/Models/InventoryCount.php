<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Строка акта инвентаризации: снимок учёта vs факт; расхождение требует причины */
class InventoryCount extends Model
{
    public $timestamps = false;
    protected $table = 'inventory_counts';
    protected $fillable = ['document_id','item_id','room_id','location_id','book_qty','fact_qty','diff_qty','reason_id','applied'];
    public function document() { return $this->belongsTo(Document::class); }
    public function item()     { return $this->belongsTo(Item::class); }
    public function reason()   { return $this->belongsTo(Reason::class); }
}
