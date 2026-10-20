<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** История по каждой штучной единице (выдача/возврат/перемещение), как в Snipe-IT */
class AssetMovement extends Model
{
    public $timestamps = false;
    protected $table = 'asset_movements';
    protected $fillable = ['asset_id','document_id','user_id','event','from_label','to_label','reason_code','reason','created_at'];
    public function asset() { return $this->belongsTo(Asset::class); }
    public function user()  { return $this->belongsTo(User::class); }
}
