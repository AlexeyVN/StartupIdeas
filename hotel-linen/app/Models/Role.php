<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'title', 'is_mobile_only'];

    public function users() { return $this->hasMany(User::class); }

    /** Права роли (ability-строки), как permissions в Snipe-IT */
    public function abilities(): array
    {
        return \DB::table('permissions')->where('role_name', $this->name)->pluck('ability')->all();
    }
}
