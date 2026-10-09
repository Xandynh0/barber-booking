<?php

/**
 * Test helper for PublicAppointmentConcurrencyTest — never used by the
 * application. Runs in its own PHP process (so it has its own MySQL
 * connection), boots the real application and sends one request to
 * POST /api/v1/public/appointments through the HTTP kernel: same route,
 * middleware, FormRequest, controller and AppointmentBooker transaction as
 * production. Prints the response as JSON on stdout.
 *
 * Usage: php post-public-appointment.php <json-body> <idempotency-key> <client-ip>
 *
 * The database to use comes from the environment the test passes in
 * (DB_TEST_DATABASE etc.), never from defaults.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$request = Request::create(
    '/api/v1/public/appointments',
    'POST',
    server: [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT_LANGUAGE' => 'pt-BR',
        'HTTP_IDEMPOTENCY_KEY' => $argv[2],
        'REMOTE_ADDR' => $argv[3],
    ],
    content: $argv[1],
);

$response = $kernel->handle($request);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
]));

$kernel->terminate($request, $response);
