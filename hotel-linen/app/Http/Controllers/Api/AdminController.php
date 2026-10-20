<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Администратор: пользователи, права, сброс пароля, блокировка (п. 4.1) */
class AdminController extends Controller
{
    public function users() { return User::with('role')->paginate(50); }

    public function storeUser(Request $r)
    {
        $v = $r->validate([
            'name' => 'required|string', 'email' => 'required|email|unique:users',
            'password' => 'required|min:8', 'role_id' => 'required|exists:roles,id',
        ]);
        $u = User::create($v);
        Audit::log('user.create', $u, ['object_label' => $u->name, 'new' => ['role' => $u->role?->name]]);
        return response()->json(['ok' => true, 'id' => $u->id], 201);
    }

    public function updateUser(Request $r, User $user)
    {
        $v = $r->validate([
            'name' => 'sometimes|string', 'role_id' => 'sometimes|exists:roles,id',
            'is_active' => 'sometimes|boolean',
            'password' => 'sometimes|min:8',           // смена пароля
        ]);
        $old = $user->only(['name','role_id','is_active']);
        if (isset($v['password'])) $v['password'] = Hash::make($v['password']);
        $user->update($v);
        Audit::log('user.update', $user, [
            'object_label' => $user->name, 'old' => $old,
            'new' => $user->only(['name','role_id','is_active']),
            'reason' => isset($v['password']) ? 'смена пароля' : null,
        ]);
        return response()->json(['ok' => true]);
    }

    /** Сброс пароля администратором */
    public function resetPassword(Request $r, User $user)
    {
        $v = $r->validate(['password' => 'required|min:8']);
        $user->update(['password' => Hash::make($v['password'])]);
        Audit::log('user.password_reset', $user, ['object_label' => $user->name, 'reason' => 'сброс администратором']);
        return response()->json(['ok' => true]);
    }

    /** Блокировка/разблокировка */
    public function toggleActive(Request $r, User $user)
    {
        $v = $r->validate(['reason' => 'required|string']);
        $user->update(['is_active' => !$user->is_active]);
        Audit::log('user.toggle_active', $user, [
            'object_label' => $user->name,
            'old' => ['is_active' => !$user->is_active], 'new' => ['is_active' => $user->is_active],
            'reason' => $v['reason'],
        ]);
        return response()->json(['ok' => true, 'is_active' => $user->is_active]);
    }

    /** Права по действиям для роли */
    public function permissions($roleName)
    {
        return Permission::where('role_name', $roleName)->pluck('ability');
    }

    public function setPermissions(Request $r, $roleName)
    {
        $v = $r->validate(['abilities' => 'required|array']);
        Permission::where('role_name', $roleName)->delete();
        foreach ($v['abilities'] as $a) Permission::create(['role_name' => $roleName, 'ability' => $a]);
        Audit::log('permissions.update', null, ['subject_type' => 'Role', 'object_label' => $roleName,
                                                 'new' => ['abilities' => $v['abilities']], 'reason' => 'изменение прав']);
        return response()->json(['ok' => true]);
    }

    /** Журнал (только чтение) + контроль целостности */
    public function logs(Request $r)
    {
        $q = Audit::queryLogs($r);
        return $q->paginate(100);
    }
}
