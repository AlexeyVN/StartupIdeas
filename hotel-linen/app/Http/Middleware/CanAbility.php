<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Проверка права на сервере: middleware('can.ability:stock.receive') */
class CanAbility
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        $user = $request->user();
        if (!$user || !$user->is_active) abort(403, 'Учётная запись заблокирована');
        if (!$user->canDo($ability)) abort(403, "Нет права: {$ability}");
        return $next($request);
    }
}
