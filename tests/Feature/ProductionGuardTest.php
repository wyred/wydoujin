<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

test('production forces APP_DEBUG off and warns when the gate is open', function (): void {
    $this->app['env'] = 'production';
    config(['app.debug' => true, 'app.password' => '']);
    Log::spy();

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeFalse();
    Log::shouldHaveReceived('warning')->twice(); // debug + open-gate
});

test('production with a password set warns only about debug', function (): void {
    $this->app['env'] = 'production';
    config(['app.debug' => true, 'app.password' => 'hunter2']);
    Log::spy();

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

test('non-production leaves debug untouched', function (): void {
    $this->app['env'] = 'local';
    config(['app.debug' => true, 'app.password' => '']);
    Log::spy();

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});
