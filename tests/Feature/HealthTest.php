<?php

test('health endpoint returns ok', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
