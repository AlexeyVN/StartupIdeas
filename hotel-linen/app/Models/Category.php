<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'tracking', 'washable'];
    public function items() { return $this->hasMany(Item::class); }
}
