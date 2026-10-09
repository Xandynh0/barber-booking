<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            Log::error('Health check failed: database connection unavailable.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => __('errors.service_unavailable'),
                ],
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
