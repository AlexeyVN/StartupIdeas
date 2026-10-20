<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role_id', 'is_active'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];

    public function role() { return $this->belongsTo(Role::class); }

    /** Проверка права на сервере: ability вида "stock.receive" */
    public function canDo(string $ability): bool
    {
        if (!$this->role) return false;
        if ($this->role->name === 'admin') return true; // админ имеет все права
        return Permission::query()->where('role_name', $this->role->name)->where('ability', $ability)->exists();
    }

    public function isMaid(): bool { return $this->role?->name === 'maid'; }
}
