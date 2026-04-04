<?php

namespace App\Http\Controllers\PhonePe;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PhonepeServices;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private PhonepeServices $phonePe) {}

    public function generatePaymentLink(Request $request)
    {
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

        $merchant_order_id = $request->merchant_order_id ?? 'MERCHANT_'.strtoupper(substr(uniqid(), 0, 8));

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

    public function processSharedLink($merchantOrderId)
    {
        $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

        if (! $payment) {
            Log::error('Payment not found', ['merchant_order_id' => $merchantOrderId]);

            return $this->error('Payment not found', 404);
        }

        if ($payment->status !== PaymentStatus::INITIATED) {
            Log::error('Payment already processed', ['merchant_order_id' => $merchantOrderId]);

            return $this->error('Payment already processed', 400);
        }

        if ($payment->phonepe_link) {
            return $this->redirect($payment->phonepe_link);
        }

        try {

            $response = $this->phonePe->initiatePayment([
                'merchant_order_id' => $merchantOrderId,
                'amount' => $payment->amount,
                'name' => $payment->customer_name,
                'email' => $payment->customer_email,
                'phone' => $payment->customer_phone,
            ]);

            if (isset($response['redirectUrl'])) {
                $payment->update(['phonepe_link' => $response['redirectUrl']]);

                return redirect()->away($response['redirectUrl']);
            }

            return $this->error('Payment initiation failed. Please try again.', 400);

        } catch (\Exception $e) {

            Log::error('Payment initiation failed', [
                'merchant_order_id' => $merchantOrderId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Payment initiation failed. Please try again.', 400);
        }

        return $this->success('Payment link processed successfully', [
            'payment' => $payment,
        ]);

    }

    public function callback(Request $request)
    {
        Log::info('PhonePe Callback Reached', [
            'method' => $request->method(),
            'payload' => $request->all(),
            'url' => $request->fullUrl(),
        ]);

        $payload = $this->phonePe->decodeResponsePayload($request->all());
        if ($payload !== $request->all()) {
            Log::info('Decoded PhonePe Callback Payload', ['payload' => $payload]);
        }

        $merchantOrderId = $this->phonePe->extractMerchantOrderId($payload)
            ?? $request->input('merchantOrderId')
            ?? $request->input('transactionId');

        if (! $merchantOrderId) {
            // Check if it's in the URL itself if PhonePe didn't append it correctly
            return redirect()->route('payment.failed', ['error' => 'Invalid callback. Order ID missing. Check logs for payload details.']);
        }

        try {
            $statusResponse = $this->phonePe->checkStatus($merchantOrderId);
            $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

            if (! $payment) {
                return redirect()->route('payment.failed', ['error' => 'Order not found in database: '.$merchantOrderId]);
            }

            $attrs = $this->phonePe->attributesFromStatusResponse($statusResponse);
            unset($attrs['merchant_order_id']);
            $payment->update($attrs);

            $newStatus = $payment->fresh()->status;

            if ($newStatus === 'COMPLETED') {
                return redirect()->route('payment.success', ['order' => $merchantOrderId])
                    ->with('payment', $payment->fresh());
            }

            if ($newStatus === 'PENDING') {
                return redirect()->route('payment.failed', [
                    'error' => 'Payment is still PENDING. Please refresh the history page in a few moments.',
                ]);
            }

            return redirect()->route('payment.failed', [
                'error' => 'Payment failed. State: '.$newStatus,
            ]);

        } catch (\Exception $e) {
            return redirect()->route('payment.failed', [
                'error' => 'Status check failed: '.$e->getMessage(),
            ]);
        }
    }
}
