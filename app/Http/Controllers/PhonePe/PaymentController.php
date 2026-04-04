<?php

namespace App\Http\Controllers\PhonePe;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponse;
use App\Models\Payment;
use App\Enums\PaymentStatus;

class PaymentController extends Controller
{
    use ApiResponse;
    
    public function generatePaymentLink(Request $request){
        $rules = [
            'amount' => 'required|numeric|min:1',
            'merchant_order_id' => 'nullable|string|max:255',
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'required|string|max:15',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $merchant_order_id = $request->merchant_order_id ?? 'MERCHANT_' . strtoupper(substr(uniqid(), 0, 8));

        $payment = Payment::create([
            'merchant_order_id' => $merchant_order_id,
            'amount' => $request->amount,
            'customer_name' => $request->customer_name,
            'customer_email' => $request->customer_email,
            'customer_phone' => $request->customer_phone,
            'status' => PaymentStatus::INITIATED,
        ]);

        $localLink = url('/pay/'.$merchant_order_id);

        return $this->success('Payment link generated successfully', [
            'local_link' => $localLink,
            'merchant_order_id' => $merchant_order_id,
            'name' => $request->customer_name,
            'email' => $request->customer_email,
            'phone' => $request->customer_phone,
            'amount' => $request->amount,
        ]);

    }
}
