<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistResult extends Model
{
    public $timestamps = false;
    protected $fillable = ['document_id','group','text','passed','comment'];
    protected $casts = ['passed' => 'boolean'];
}
