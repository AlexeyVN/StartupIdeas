<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['name', 'kind', 'is_active'];

    public function stocks() { return $this->hasMany(Stock::class); }
}
