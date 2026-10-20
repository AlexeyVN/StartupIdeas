<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLine extends Model
{
    public $timestamps = false;
    protected $table = 'document_lines';
    protected $fillable = ['document_id','item_id','qty','price','reason_id','comment'];
    public function document() { return $this->belongsTo(Document::class); }
    public function item()     { return $this->belongsTo(Item::class); }
    public function reason()   { return $this->belongsTo(Reason::class); }
}
