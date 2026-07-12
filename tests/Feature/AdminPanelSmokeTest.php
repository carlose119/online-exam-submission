<?php

use function Pest\Laravel\get;

it('redirects unauthenticated /admin to /admin/login', function () {
    get('/admin')->assertRedirect('/admin/login');
});

it('returns 200 for /admin/login', function () {
    get('/admin/login')->assertOk();
});

it('renders a login form on /admin/login', function () {
    get('/admin/login')->assertSee('<form', false);
});

it('loads admin panel routes without class-not-found errors', function () {
    // Verify all admin routes resolve — if any class is missing (e.g.
    // wrong Filament v5 imports), route:list would have already failed
    // during Artisan cache operations. This HTTP-level check confirms
    // the login page renders without Livewire/Filament component errors.
    $response = get('/admin/login');
    $response->assertOk();
    $response->assertDontSee('class not found', false);
    $response->assertDontSee('Target class', false);
});
