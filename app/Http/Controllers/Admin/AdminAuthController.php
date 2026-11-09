<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function show()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(AdminLoginRequest $request)
    {
        $login = $request->login;
        $password = $request->password;

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        $user = User::where($field, $login)
            ->when($field === 'name', fn ($q) => $q->whereHas('level', fn ($l) => $l->where(fn ($g) => $g
                ->where('is_system', true)
                ->orWhereIn('id', Permission::select('level_id')))))
            ->first();

        if (! $user) {
            static $dummyHash = null;
            $dummyHash ??= Hash::make('dummy-password');
            Hash::check($password, $dummyHash);

            return back()->withErrors(['login' => 'Invalid credentials.'])->withInput();
        }

        try {
            $passwordValid = Hash::check($password, $user->password);
        } catch (\RuntimeException $e) {
            $passwordValid = false;
        }

        if (! $passwordValid && ! $user->upgradeLegacyPassword($password)) {
            return back()->withErrors(['login' => 'Invalid credentials.'])->withInput();
        }

        $user->load('level');

        if (! $user->canAccessAdmin()) {
            return back()->withErrors(['login' => 'You are not authorized to access the admin area.'])->withInput();
        }

        if ($user->isBanned()) {
            return back()->withErrors(['login' => 'You are not authorized to access the admin area.'])->withInput();
        }

        Auth::guard('admin')->login($user);
        $request->session()->put('admin_password_hash', $user->password);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
