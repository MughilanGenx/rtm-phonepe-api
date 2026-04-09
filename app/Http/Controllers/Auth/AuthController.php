<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: 'Authentication API Endpoints')]
class AuthController extends Controller
{
    use ApiResponse;
    #[OA\Post(
        path: '/api/login',
        summary: 'User Login',
        description: 'Authenticate a user and return a JWT token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Login successful'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'),
                            new OA\Property(property: 'user', type: 'object')
                        ])
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function login(Request $request) {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, 'VALIDATION_ERROR');
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials', 401, 'AUTH_ERROR');
        }

        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        return $this->success('Login successful', [
            'token' => $token,
            'user' => $user,
        ], 200, 'AUTH_SUCCESS');
    }
}
