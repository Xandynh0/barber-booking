<?php

/**
 * Test helper for AdminAppointmentConcurrencyTest — never used by the
 * application. Runs in its own PHP process (so it has its own MySQL
 * connection), boots the real application and sends one request to
 * POST /api/v1/admin/appointments through the HTTP kernel: same route,
 * `auth:sanctum` middleware, FormRequest, controller and AppointmentBooker
 * transaction as production. The administrator is set on the sanctum guard
 * of this process (there is no browser session to carry a cookie). Prints
 * the response as JSON on stdout.
 *
 * Usage: php post-admin-appointment.php <user-id> <json-body> <idempotency-key>
 */

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['auth']->guard('sanctum')->setUser(User::query()->findOrFail((int) $argv[1]));

$request = Request::create(
    '/api/v1/admin/appointments',
    'POST',
    server: [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT_LANGUAGE' => 'pt-BR',
        'HTTP_IDEMPOTENCY_KEY' => $argv[3],
        'REMOTE_ADDR' => '10.0.0.9',
    ],
    content: $argv[2],
);

$response = $kernel->handle($request);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
]));

$kernel->terminate($request, $response);
