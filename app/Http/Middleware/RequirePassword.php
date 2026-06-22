<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('app.password');

        if (empty($password)) {
            return $next($request);
        }

        if ($request->is('login', 'health')) {
            return $next($request);
        }

        if ($request->session()->get('password_ok') === true) {
            return $next($request);
        }

        return redirect('/login');
    }
}
