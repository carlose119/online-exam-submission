<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvitationReturnUrl
{
    public function resolve(mixed $url, string $appHost): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false
            || (isset($parts['host']) && ! hash_equals(strtolower($appHost), strtolower($parts['host'])))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpExceptionInterface) {
            return null;
        }

        if ($route->getName() !== 'class.join.show') {
            return null;
        }

        return route('class.join.show', $route->parameter('invitation_code'), absolute: false);
    }
}
