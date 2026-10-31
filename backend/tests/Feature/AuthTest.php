<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->create(['password' => 'Password123!', ...$attributes])->assignRole($role->value);
}

it('logs in and returns a bearer token that expires in 8 hours', function () {
    $user = userWithRole(Role::Agent);

    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.roles', ['agent'])
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'name', 'email', 'roles', 'permissions']]]);

    expect(PersonalAccessToken::sole()->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(479)->toBeLessThanOrEqual(480)
        ->and($response->json('data.expires_at'))->toEndWith('Z');
});

it('rejects invalid credentials with the same message', function (string $email, string $password) {
    userWithRole(Role::Agent, ['email' => 'activo@psicol.test']);
    userWithRole(Role::Agent, ['email' => 'inactivo@psicol.test', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', compact('email', 'password'))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email.0', 'Credenciales inválidas.');
})->with([
    'wrong password' => ['activo@psicol.test', 'otra-clave'],
    'unknown email' => ['nadie@psicol.test', 'Password123!'],
    'inactive user' => ['inactivo@psicol.test', 'Password123!'],
]);

it('validates the login payload in spanish', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.')
        ->assertJsonPath('errors.password.0', 'El campo contraseña es obligatorio.');
});

it('revokes the token on logout', function () {
    $user = userWithRole(Role::Requester);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('answers 401 with the error envelope when there is no token', function () {
    $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($response->json())->toHaveKeys(['message', 'code', 'request_id'])
        ->and($response->json('code'))->toBe('UNAUTHENTICATED')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('returns the current user with roles and permissions', function () {
    Sanctum::actingAs(userWithRole(Role::Requester));

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['requester'])
        ->assertJsonPath('data.permissions', ['dashboard.view', 'incidents.create', 'incidents.transition', 'incidents.update']);
});

it('throttles login to 5 attempts per minute', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@psicol.test', 'password' => 'mala-clave'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'x@psicol.test', 'password' => 'mala-clave'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});

it('echoes a valid X-Request-Id and replaces an unsafe one', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'abc-12345'])->assertHeader('X-Request-Id', 'abc-12345');

    $id = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad\nvalue"])->headers->get('X-Request-Id');
    expect($id)->not->toContain("\n")->and(strlen($id))->toBe(36);
});

it('renders unknown api routes as NOT_FOUND', function () {
    $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});
