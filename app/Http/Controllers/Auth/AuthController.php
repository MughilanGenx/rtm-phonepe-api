<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Facades\JWTAuth;

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
                            new OA\Property(property: 'user', type: 'object', properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                                new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                                new OA\Property(property: 'phone', type: 'string', example: '9876543210', nullable: true),
                                new OA\Property(property: 'role', type: 'string', example: 'user'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                            ]),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, 'VALIDATION_ERROR');
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return $this->error('Invalid credentials', 401, 'AUTH_ERROR');
            }

            $token = JWTAuth::fromUser($user);

            return $this->success('Login successful', [
                'token' => $token,
                'user' => $user,
            ], 200, 'AUTH_SUCCESS');

        } catch (\Exception $e) {
            Log::error('Error occurs while login the user', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
                'Trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Internal server error', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    #[OA\Post(
        path: '/api/logout',
        summary: 'User Logout',
        description: 'Log out the user by invalidating the JWT token.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logout successful'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return $this->success('Logout successful', null, 200, 'AUTH_LOGOUT');
        } catch (\Exception $e) {
            return $this->error('Failed to logout', 500, 'AUTH_ERROR');
        }
    }

    #[OA\Post(
        path: '/api/register',
        summary: 'Register New User',
        description: 'Register a new user with a specific role. This endpoint is restricted to Admin users only.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'phone', 'password', 'confirm_password', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane@example.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '9876543211'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                    new OA\Property(property: 'role', type: 'string', enum: ['admin', 'user'], example: 'user'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User registered successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'User registered successfully'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'user', type: 'object', properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 2),
                                new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
                                new OA\Property(property: 'email', type: 'string', example: 'jane@example.com'),
                                new OA\Property(property: 'phone', type: 'string', example: '9876543211'),
                                new OA\Property(property: 'role', type: 'string', example: 'user'),
                                new OA\Property(property: 'is_newUser', type: 'boolean', example: true),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                            ]),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized - Admin role required'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    public function registerNewUser(Request $request)
    {
        $userRole = auth('api')->user()->role;

        if($userRole != Role::ADMIN) {
            return $this->error('You are not authorized to perform this action', 403, 'UNAUTHORIZED');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'email' => 'required|email|max:125|unique:users,email',
            'phone' => 'nullable|string|max:15|unique:users,phone',
            'password' => 'required|string|min:6|max:255',
            'role' => ['required', new Enum(Role::class)],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 422, 'VALIDATION_ERROR');
        }

        try {
            $data = $validator->validated();

            // if ($data['password'] != $data['confirm_password']) {
            //     return $this->error('Passwords do not match', 400, 'PASSWORDS_DO_NOT_MATCH');
            // }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'password' => Hash::make($data['password']),
                'is_newUser' => true
            ]);

            return $this->success('User registered successfully', [
                'user' => $user,
            ], 200, 'USER_REGISTERED_SUCCESSFULLY');
        } catch (\Exception $e) {
            Log::error('Error occurs while register the user', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
                'Trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Internal server error', 500, 'INTERNAL_SERVER_ERROR');
        }
    }
}
