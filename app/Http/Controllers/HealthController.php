<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Health', description: 'API health check endpoints')]
class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/health',
        summary: 'API Health Check',
        description: 'Returns API health status.',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'API is healthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'message', type: 'string', example: 'API is healthy'),
                    ]
                )
            )
        ]
    )]
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'API is healthy',
        ]);
    }
}
