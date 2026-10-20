<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    protected $table = 'checklist_templates';
    protected $fillable = ['name', 'kind', 'points', 'is_active'];
    protected $casts = ['points' => 'array', 'is_active' => 'boolean'];
}
