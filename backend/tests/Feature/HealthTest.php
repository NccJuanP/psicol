<?php

it('reports the database as reachable', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'db' => 'ok']);
});
