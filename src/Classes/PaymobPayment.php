<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Nafezly\Payments\Exceptions\MissingPaymentInfoException;
use Nafezly\Payments\Interfaces\PaymentInterface;

class PaymobPayment extends BaseController implements PaymentInterface
{
    private $paymob_public_key;
    private $paymob_secret_key;
    private $paymob_integration_id;


    public function __construct()
    {
        $this->paymob_public_key = config('nafezly-payments.PAYMOB_PUBLIC_API_KEY');
        $this->paymob_secret_key = config('nafezly-payments.PAYMOB_SECRET_API_KEY');
        $this->paymob_integration_id = config('nafezly-payments.PAYMOB_INTEGRATION_ID');
        $this->currency = config("nafezly-payments.PAYMOB_CURRENCY");
    }

    /**
     * @param $amount
     * @param null $user_id
     * @param null $user_first_name
     * @param null $user_last_name
     * @param null $user_email
     * @param null $user_phone
     * @param null $source
     * @return array
     * @throws MissingPaymentInfoException|ConnectionException|RequestException|\Random\RandomException
     */
    public function pay($amount = null, $user_id = null, $user_first_name = null, $user_last_name = null, $user_email = null, $user_phone = null, $source = null)
    {
        $this->setPassedVariablesToGlobal($amount,$user_id,$user_first_name,$user_last_name,$user_email,$user_phone,$source);
        $required_fields = ['amount', 'user_first_name', 'user_last_name', 'user_email', 'user_phone'];
        $this->checkRequiredFields($required_fields, 'PayMob');

        // New integration (Flash) that we are forced to use now doesnt let us register order first, so we generate one for us.
        // Docs: https://developers.paymob.com/egypt/checkout-api/integration-guide-and-api-reference/create-intention-payment-api
        $merchant_order_id = str($this->paymob_public_key)
            ->beforeLast('_')
            ->append('-')
            ->append(now()->getTimestampMs())
            ->append('-')
            ->append(bin2hex(random_bytes(16)))
            ->toString();

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Token ' . $this->paymob_secret_key,
        ])
            ->post('https://accept.paymob.com/v1/intention/', [
                "amount" => $this->amount * 100,
                "currency" => $this->currency,
                "payment_methods" => [
                    (int)$this->paymob_integration_id,
                ],
                "special_reference" => $merchant_order_id,
                "items" => [],
                "customer" => [
                    "first_name" => $this->user_first_name,
                    "last_name" => $this->user_last_name,
                    "email" => $this->user_email,
                ],
                "billing_data" => [
                    "apartment" => "NA",
                    "email" => $this->user_email,
                    "floor" => "NA",
                    "first_name" => $this->user_first_name,
                    "street" => "NA",
                    "building" => "NA",
                    "phone_number" => $this->user_phone,
                    "shipping_method" => "NA",
                    "postal_code" => "NA",
                    "city" => "NA",
                    "country" => "NA",
                    "last_name" => $this->user_last_name,
                    "state" => "NA"
                ],
            ])
            ->throw()
            ->json();

        return [
            'payment_id' => $merchant_order_id,
            'html' => "",
            'redirect_url' => "https://accept.paymob.com/unifiedcheckout/?publicKey=$this->paymob_public_key&clientSecret={$response['client_secret']}"
        ];
    }

    /**
     * @param Request $request
     * @return array
     */
    public function verify(Request $request): array
    {
        $string = $request['amount_cents'] . $request['created_at'] . $request['currency'] . $request['error_occured'] . $request['has_parent_transaction'] . $request['id'] . $request['integration_id'] . $request['is_3d_secure'] . $request['is_auth'] . $request['is_capture'] . $request['is_refunded'] . $request['is_standalone_payment'] . $request['is_voided'] . $request['order'] . $request['owner'] . $request['pending'] . $request['source_data_pan'] . $request['source_data_sub_type'] . $request['source_data_type'] . $request['success'];

        if ( hash_equals(hash_hmac('sha512', $string, config('nafezly-payments.PAYMOB_HMAC')),$request['hmac']) ){
            if ($request['success'] == "true") {
                return [
                    'success' => true,
                    'payment_id'=>$request['merchant_order_id'] ?? $request['order'],
                    'message' => __('nafezly::messages.PAYMENT_DONE'),
                    'process_data' => $request->all()
                ];
            } else {
                return [
                    'success' => false,
                    'payment_id'=>$request['merchant_order_id'] ?? $request['order'],
                    'message' => __('nafezly::messages.PAYMENT_FAILED_WITH_CODE',['CODE'=>$this->getErrorMessage($request['txn_response_code'])]),
                    'process_data' => $request->all()
                ];
            }

        } else {
            return [
                'success' => false,
                'payment_id'=>$request['merchant_order_id'] ?? $request['order'],
                'message' => __('nafezly::messages.PAYMENT_FAILED'),
                'process_data' => $request->all()
            ];
        }
    }
    public function getErrorMessage($code){
        $errors=[
            'BLOCKED'=>__('nafezly::messages.Process_Has_Been_Blocked_From_System'),
            'B'=>__('nafezly::messages.Process_Has_Been_Blocked_From_System'),
            '5'=>__('nafezly::messages.Balance_is_not_enough'),
            'F'=>__('nafezly::messages.Your_card_is_not_authorized_with_3D_secure'),
            '7'=>__('nafezly::messages.Incorrect_card_expiration_date'),
            '2'=>__('nafezly::messages.Declined'),
            '6051'=>__('nafezly::messages.Balance_is_not_enough'),
            '637'=>__('nafezly::messages.The_OTP_number_was_entered_incorrectly'),
            '11'=>__('nafezly::messages.Security_checks_are_not_passed_by_the_system'),
        ];
        if(isset($errors[$code]))
            return $errors[$code];
        else
            return __('nafezly::messages.An_error_occurred_while_executing_the_operation');
    }

    public function refund($transaction_id,$amount): array
    {
        $request_new_token = Http::withHeaders(['content-type' => 'application/json'])
            ->post('https://accept.paymobsolutions.com/api/auth/tokens', [
                "api_key" => $this->paymob_api_key
            ])->json();
        $refund_process = Http::withHeaders(['content-type' => 'application/json'])
            ->post('https://accept.paymob.com/api/acceptance/void_refund/refund',['auth_token'=>$request_new_token['token'],'transaction_id'=>$transaction_id,'amount_cents'=>$amount])->json();

        dd($refund_process);
        return [
            'transaction_id'=>$transaction_id,
            'amount'=>$amount,
        ];

    }

}