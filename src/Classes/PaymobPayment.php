<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Nafezly\Payments\Exceptions\MissingPaymentInfoException;
use Nafezly\Payments\Interfaces\PaymentInterface;

class PaymobPayment implements PaymentInterface
{

    private $paymob_api_key;
    private $paymob_integration_id;
    private $paymob_iframe_id;


    public function __construct()
    {
        $this->paymob_api_key = config('nafezly-payments.PAYMOB_API_KEY');
        $this->paymob_integration_id = config('nafezly-payments.PAYMOB_INTEGRATION_ID');
        $this->paymob_iframe_id = config("nafezly-payments.PAYMOB_IFRAME_ID");
    }

    /**
     * @param $amount
     * @param null $user_id
     * @param null $user_name
     * @param null $user_email
     * @param null $user_phone
     * @param null $source
     * @return void
     * @throws MissingPaymentInfoException
     */
    public function pay($amount, $user_id = null, $user_name = null, $user_email = null, $user_phone = null, $source = null)
    {
        if (is_null($user_name)) throw new MissingPaymentInfoException('user_name', 'PayMob');
        if (is_null($user_email)) throw new MissingPaymentInfoException('user_email', 'PayMob');
        if (is_null($user_phone)) throw new MissingPaymentInfoException('user_phone', 'PayMob');

        $request_new_token = Http::withHeaders(['content-type' => 'application/json'])
            ->post('https://accept.paymobsolutions.com/api/auth/tokens', [
                "api_key" => $this->paymob_api_key
            ])->json();

        $get_order = Http::withHeaders(['content-type' => 'application/json'])
            ->post('https://accept.paymobsolutions.com/api/ecommerce/orders', [
                "auth_token" => $request_new_token['token'],
                "delivery_needed" => "false",
                "amount_cents" => $amount * 100,
                "items" => []
            ])->json();

        $get_url_token = Http::withHeaders(['content-type' => 'application/json'])
            ->post('https://accept.paymobsolutions.com/api/acceptance/payment_keys', [
                "auth_token" => $request_new_token['token'],
                "expiration" => 36000,
                "amount_cents" => $get_order['amount_cents'],
                "order_id" => $get_order['id'],
                "billing_data" => [
                    "apartment" => "NA",
                    "email" => $user_email,
                    "floor" => "NA",
                    "first_name" => $user_name,
                    "street" => "NA",
                    "building" => "NA",
                    "phone_number" => $user_phone,
                    "shipping_method" => "NA",
                    "postal_code" => "NA",
                    "city" => "NA",
                    "country" => "NA",
                    "last_name" => $user_name,
                    "state" => "NA"
                ],
                "currency" => "EGP",
                "integration_id" => $this->paymob_integration_id
            ])->json();


        header("location: https://accept.paymobsolutions.com/api/acceptance/iframes/" . $this->paymob_iframe_id . "?payment_token=" . $get_url_token['token']);
        die();
    }

    /**
     * @param Request $request
     * @return array
     */
    public function verify(Request $request): array
    {
        $string = $request['amount_cents'] . $request['created_at'] . $request['currency'] . $request['error_occured'] . $request['has_parent_transaction'] . $request['id'] . $request['integration_id'] . $request['is_3d_secure'] . $request['is_auth'] . $request['is_capture'] . $request['is_refunded'] . $request['is_standalone_payment'] . $request['is_voided'] . $request['order'] . $request['owner'] . $request['pending'] . $request['source_data_pan'] . $request['source_data_sub_type'] . $request['source_data_type'] . $request['success'];

        if (hash_hmac('sha512', $string, config('nafezly-payments.PAYMOB_HMAC'))) {
            if ($request['success'] == "true") {
                return [
                    'success' => true,
                    'message' => __('messages.PAYMENT_DONE'),
                    'process_data' => $request->all()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => __('messages.PAYMENT_FAILED'),
                    'process_data' => $request->all()
                ];
            }

        } else {
            return [
                'success' => false,
                'message' => __('messages.PAYMENT_FAILED'),
                'process_data' => $request->all()
            ];
        }
    }
}