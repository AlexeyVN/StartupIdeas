<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateLine extends Model
{
    public $timestamps = false;
    protected $table = 'template_lines';
    protected $fillable = ['template_id', 'item_id', 'qty'];
    public function item() { return $this->belongsTo(Item::class); }
}
