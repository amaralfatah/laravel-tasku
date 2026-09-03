<?php

use Illuminate\Support\Facades\Route;

it('honours the forwarded scheme from the platform proxy', function () {
    Route::get('/__scheme', fn () => request()->getScheme());

    $this->get('/__scheme', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertSee('https');
});

it('keeps the plain scheme when nothing is forwarded', function () {
    Route::get('/__scheme', fn () => request()->getScheme());

    $this->get('/__scheme')
        ->assertOk()
        ->assertSee('http');
});
