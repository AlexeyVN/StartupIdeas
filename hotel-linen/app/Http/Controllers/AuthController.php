<?php
namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $creds = $request->validate(['email' => 'required|email', 'password' => 'required']);
        if (!Auth::attempt($creds)) {
            Audit::log('login.failed', null, ['object_label' => $creds['email'], 'reason' => 'неверный пароль']);
            return back()->withErrors(['email' => 'Неверные данные'])->withInput();
        }
        $u = Auth::user();
        if (!$u->is_active) { Auth::logout(); return back()->withErrors(['email' => 'Учётная запись заблокирована']); }
        $u->update(['last_login_at' => now()]);
        session()->regenerate();
        Audit::log('login', $u, ['object_label' => $u->name]); // журнал входов (4.1)
        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Audit::log('logout', $request->user(), ['object_label' => $request->user()?->name]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
