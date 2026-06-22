<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PasswordLoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        if (! hash_equals((string) config('app.password'), (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('password_ok', true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
