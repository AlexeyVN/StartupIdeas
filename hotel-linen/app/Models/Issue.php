<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Заявка по номеру: горничная заявляет — менеджер закрывает с комментарием */
class Issue extends Model
{
    protected $fillable = ['room_id','kind','title','description','status','reported_by','closed_by','close_comment','closed_at'];
    public function room()     { return $this->belongsTo(Room::class); }
    public function reporter() { return $this->belongsTo(User::class, 'reported_by'); }
    public function closer()   { return $this->belongsTo(User::class, 'closed_by'); }
}
