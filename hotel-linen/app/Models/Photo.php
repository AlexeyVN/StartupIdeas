<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    protected $fillable = ['document_id','path','caption','uploaded_by'];
    public function document() { return $this->belongsTo(Document::class); }
}
