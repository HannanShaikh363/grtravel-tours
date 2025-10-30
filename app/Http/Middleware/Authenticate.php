<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Str;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {


        $currentUrl = request()->url(); // Gets the full URL


        // Extract the path
        $path = parse_url($currentUrl, PHP_URL_PATH);

        // Check if the path contains a specific string
        if (Str::contains($path, 'auth')) {


            return $request->expectsJson() ? null : route('login_page');
        }
        return $request->expectsJson() ? null : route('agent_login_page');


    }
}
