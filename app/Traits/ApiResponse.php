<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    public function success( $message = 'Success',$data = null, $code = 200, $status = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public function error($message = 'Error occurred', $code = 400, $status = null, $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }


    public function pending($data = null, $message = 'Payment is pending', $code = 202, $status = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public function redirect($url, $message = 'Redirecting to payment gateway', $data = []): JsonResponse
    {
        return response()->json([
            'success'      => true,
            'status'       => 'REDIRECT',
            'message'      => $message,
            'redirect_url' => $url,
            'data'         => $data,
        ], 200);
    }
}