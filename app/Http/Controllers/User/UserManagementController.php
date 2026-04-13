<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Laravel\Facades\Image;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'User Management', description: 'User Profile and Management Endpoints')]

class UserManagementController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/user/profile',
        summary: 'Get User Profile',
        description: 'Fetch the authenticated user\'s profile details.',
        security: [['bearerAuth' => []]],
        tags: ['User Management'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User profile fetched successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'User management'),
                        new OA\Property(property: 'data', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function profileManagement(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (! $user) {
                return $this->error('User not found', 404, 'USER_NOT_FOUND');
            }

            return $this->success('User profile fetched successfully', $user, 200, 'USER_MANAGEMENT');

        } catch (\Exception $e) {
            Log::error('Error in profileManagement: ', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
            ]);

            return $this->error('Failed to fetch user profile', 500, 'PROFILE_FETCH_ERROR');
        }
    }

    #[OA\Post(
        path: '/api/user/profile',
        summary: 'Update User Profile',
        description: 'Update the authenticated user\'s name, email, or phone.',
        security: [['bearerAuth' => []]],
        tags: ['User Management'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', example: '9876543210', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profile updated successfully'),
            new OA\Response(response: 400, description: 'Email or Phone already exists'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function updateProfileManagement(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->error('User not found', 404, 'USER_NOT_FOUND');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:125|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:15|unique:users,phone,' . $user->id,
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400, 'VALIDATION_ERROR');
        }

        try {
            $user->update($validator->validated());

            return $this->success('User profile updated successfully', $user, 200, 'USER_MANAGEMENT');

        } catch (\Exception $e) {
            Log::error('Error occurs while update the user details', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
                'Trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Internal server error', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    #[OA\Post(
        path: '/api/user/profile/change-password',
        summary: 'Change Password',
        description: 'Update the authenticated user\'s password.',
        security: [['bearerAuth' => []]],
        tags: ['User Management'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['old_password', 'new_password', 'confirm_password'],
                properties: [
                    new OA\Property(property: 'old_password', type: 'string', format: 'password', example: 'oldpassword123'),
                    new OA\Property(property: 'new_password', type: 'string', format: 'password', example: 'newpassword123'),
                    new OA\Property(property: 'confirm_password', type: 'string', format: 'password', example: 'newpassword123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password changed successfully'),
            new OA\Response(response: 400, description: 'Validation error or incorrect old password'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function changePasswordManagement(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->error('User not found', 404, 'USER_NOT_FOUND');
        }

        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string|max:255',
            'new_password' => 'required|string|max:255',
            'confirm_password' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400, 'VALIDATION_ERROR');
        }

        $data = $validator->validated();

        if ($data['new_password'] != $data['confirm_password']) {
            return $this->error('Passwords do not match', 400, 'PASSWORDS_DO_NOT_MATCH');
        }

        if (! Hash::check($data['old_password'], $user->password)) {
            return $this->error('Old password does not match', 400, 'OLD_PASSWORD_DO_NOT_MATCH');
        }

        try {
            $user->password = Hash::make($data['new_password']);
            $user->save();

            return $this->success('Password changed successfully', $user, 200, 'PASSWORD_CHANGED_SUCCESSFULLY');

        } catch (\Exception $e) {
            Log::error('Error in changePasswordManagement: ', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
            ]);

            return $this->error('Failed to change password', 500, 'PASSWORD_CHANGE_ERROR');
        }
    }

    #[OA\Post(
        path: '/api/user/profile/upload-profile-image',
        summary: 'Upload Profile Image',
        description: 'Upload and optimize (convert to WebP) user profile image.',
        security: [['bearerAuth' => []]],
        tags: ['User Management'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['profile_image'],
                    properties: [
                        new OA\Property(property: 'profile_image', type: 'string', format: 'binary', description: 'Image file (jpeg, png, webp)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profile image uploaded successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function uploadUserProfile(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->error('User not found', 404, 'USER_NOT_FOUND');
        }

        $validator = Validator::make($request->all(), [
            'profile_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422, 'VALIDATION_ERROR');
        }

        try {

            // Delete old image if exists
            if ($user->profile_image) {
                Storage::disk('public')->delete('profile_images/'.$user->profile_image);
            }

            $file = $request->file('profile_image');
            $filename = time().'.webp';

            // Convert to WebP using Intervention Image
            $image = Image::read($file->getRealPath());
            $encoded = $image->toWebp(90);

            // Store optimized image
            Storage::disk('public')->put('profile_images/'.$filename, (string) $encoded);

            $user->profile_image = $filename;
            $user->save();

            return $this->success('Profile image uploaded successfully', $user, 200, 'PROFILE_IMAGE_UPLOADED_SUCCESSFULLY');

        } catch (\Exception $e) {
            Log::error('Error in uploadUserProfile: ', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
            ]);

            return $this->error('Error in uploadUserProfile', 500, 'ERROR_IN_UPLOAD_USER_PROFILE');
        }

    }
}
