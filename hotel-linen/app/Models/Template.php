<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model // шаблон комплектации номера
{
    protected $fillable = ['name', 'note'];
    public function lines() { return $this->hasMany(TemplateLine::class); }
}
