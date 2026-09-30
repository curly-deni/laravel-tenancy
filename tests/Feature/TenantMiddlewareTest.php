<?php

use Illuminate\Support\Facades\Route;

it('returns unauthorized when tenant resolution has no authenticated user', function (): void {
    Route::get('/tenant-middleware-auth-check', fn () => 'ok')
        ->middleware('tenancy.resolve');

    $this->get('/tenant-middleware-auth-check')->assertUnauthorized();
});
