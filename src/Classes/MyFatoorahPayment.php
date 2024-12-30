<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Nafezly\Payments\Interfaces\PaymentInterface;
use MyFatoorah\Library\API\Payment\MyFatoorahPayment as MyFatoorahPaymentProvider;
use MyFatoorah\Library\API\Payment\MyFatoorahPaymentStatus as MyFatoorahPaymentStatusProvider;

class MyFatoorahPayment extends BaseController implements PaymentInterface
{

    private $config = [];
    private $verify_route_name;

    public function __construct()
    {
        $this->config = [
            'apiKey' => config('nafezly-payments.MYFATOORAH_API_KEY'),
            'isTest' => strtolower(config('nafezly-payments.MYFATOORAH_MODE', 'test')) == 'test',
            'countryCode' => config('nafezly-payments.MYFATOORAH_COUNTRY_CODE')
        ];
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');

        $this->setCurrency(config('nafezly-payments.MYFATOORAH_CURRENCY'));
    }

    public function pay(
        $amount,
        $user_id = null,
        $user_first_name = null,
        $user_last_name = null,
        $user_email = null,
        $user_phone = null,
        $source = null
    ) {
        $this->setPassedVariablesToGlobal($amount, $user_id, $user_first_name, $user_last_name, $user_email,
            $user_phone, $source);
        $required_fields = ['amount', 'user_first_name', 'user_last_name'];
        $this->checkRequiredFields($required_fields, 'MYFATOORAH');
        $custom_reference = uniqid() . '-nafezly-' . uniqid();
        $res = (new MyFatoorahPaymentProvider($this->config))->getInvoiceURL([
            'InvoiceValue' => $amount,
            'CustomerName' => $user_first_name . ' ' . $user_last_name,
            'NotificationOption' => 'LNK',
            'DisplayCurrencyIso' => $this->currency,
            'CallBackUrl' => route($this->verify_route_name,
                ['payment' => "myfatootah", 'cus_ref' => $custom_reference]),
            'ErrorUrl' => route($this->verify_route_name, ['payment' => "myfatootah", 'cus_ref' => $custom_reference]),
            'Language' => in_array(app()->getLocale(), ['ar', 'en']) ? app()->getLocale() : 'en',
            'CustomerReference' => $custom_reference,


        ]);

        return [
            'payment_id' => $res['invoiceId'],
            'html' => "",
            'redirect_url' => $res['InvoiceURL']
        ];

    }

    public function verify(Request $request)
    {
        $data = (new MyFatoorahPaymentStatusProvider($this->config))->getPaymentStatus($request->get('cus_ref'),
            'CustomerReference');
        $status = $data->InvoiceStatus == "Paid";
        return [
            'success' => $status,
            'payment_id' => $data->InvoiceID,
            'message' => $status ? __('nafezly::messages.PAYMENT_DONE') : __('nafezly::messages.PAYMENT_FAILED'),
            'process_data' => json_decode(json_encode($data), true)
        ];
    }
}