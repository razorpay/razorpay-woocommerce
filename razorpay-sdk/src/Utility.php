<?php

namespace Razorpay\Api;
use Requests;

class Utility
{
    const SHA256 = 'sha256';

    public static $currencyListCache = [];
    public static $currencyListCacheTimeout = null;
    
    public function verifyPaymentSignature($attributes)
    {
        $actualSignature = $attributes['razorpay_signature'];

        $paymentId = $attributes['razorpay_payment_id'];

        if (isset($attributes['razorpay_order_id']) === true)
        {
            $orderId = $attributes['razorpay_order_id'];

            $payload = $orderId . '|' . $paymentId;
        }
        else if (isset($attributes['razorpay_subscription_id']) === true)
        {
            $subscriptionId = $attributes['razorpay_subscription_id'];

            $payload = $paymentId . '|' . $subscriptionId;
        }
        else if (isset($attributes['razorpay_payment_link_id']) === true)
        {
            $paymentLinkId     = $attributes['razorpay_payment_link_id'];

            $paymentLinkRefId  = $attributes['razorpay_payment_link_reference_id'];

            $paymentLinkStatus = $attributes['razorpay_payment_link_status'];

            $payload = $paymentLinkId . '|'. $paymentLinkRefId . '|' . $paymentLinkStatus . '|' . $paymentId;
        }
        else
        {
            throw new Errors\SignatureVerificationError(
                'Either razorpay_order_id or razorpay_subscription_id or razorpay_payment_link_id must be present.');
        }

        $secret = Api::getSecret();

        self::verifySignature($payload, $actualSignature, $secret);
    }

    public function verifyWebhookSignature($payload, $actualSignature, $secret)
    {
        self::verifySignature($payload, $actualSignature, $secret);
    }

    public function verifySignature($payload, $actualSignature, $secret)
    {
        $expectedSignature = hash_hmac(self::SHA256, $payload, $secret);

        // Use lang's built-in hash_equals if exists to mitigate timing attacks
        if (function_exists('hash_equals'))
        {
            $verified = hash_equals($expectedSignature, $actualSignature);
        }
        else
        {
            $verified = $this->hashEquals($expectedSignature, $actualSignature);
        }

        if ($verified === false)
        {
            throw new Errors\SignatureVerificationError(
                'Invalid signature passed');
        }
    }

    private function hashEquals($expectedSignature, $actualSignature)
    {
        if (strlen($expectedSignature) === strlen($actualSignature))
        {
            $res = $expectedSignature ^ $actualSignature;
            $return = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--)
            {
                $return |= ord($res[$i]);
            }

            return ($return === 0);
        }

        return false;
    }

    public function currencyConverter($amount, $currencyCode)
    {

        // $cacheFile = __DIR__.'/../data.cache';
        // $cacheLifetime = 6 * 60 * 60;

        // if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {

        //     $csvContent = file_get_contents($cacheFile);

        //     $rows = array_map('str_getcsv', explode("\n", trim($csvContent)));

        //     $header = array_shift($rows); 
            
        //     $currencyList = [];
        //     foreach ($rows as $row) {
        //         if (count($row) === count($header)) {
        //             $currencyList[] = array_combine($header, $row);
        //         }
        //     }
        // } else {
        //     echo "new fetch";
        //     $url = 'https://4c26-115-110-224-178.ngrok-free.app/fetch-supported-currencies.php';

        //     $csvContent = file_get_contents($url);

        //     $rows = array_map('str_getcsv', explode("\n", trim($csvContent)));

        //     $header = array_shift($rows); 
            
        //     $currencyList = [];
        //     foreach ($rows as $row) {
        //         if (count($row) === count($header)) {
        //             $currencyList[] = array_combine($header, $row);
        //         }
        //     }

        //     file_put_contents($cacheFile, $csvContent);
        // }
        // var_dump(Utility::$currencyListCache);
        // var_dump(self::$currencyListCacheTimeout);

        // if (isset(Utility::$currencyListCacheTimeout) === false or
        //     (time() - Utility::$currencyListCacheTimeout) > 6 * 60 * 60)
        // {

        //     echo "new fetch";
        //     $url = 'https://4c26-115-110-224-178.ngrok-free.app/fetch-supported-currencies.php';

        //     $csvContent = file_get_contents($url);

        //     $rows = array_map('str_getcsv', explode("\n", trim($csvContent)));

        //     $header = array_shift($rows); 
            
        //     $currencyList = [];
        //     foreach ($rows as $row) {
        //         if (count($row) === count($header)) {
        //             $currencyList[] = array_combine($header, $row);
        //         }
        //     }
            
        //     Utility::$currencyListCache = $currencyList;
        //     Utility::$currencyListCacheTimeout = time();
        // }

        // $supportedCurrencies = Utility::$currencyListCache;

        // var_dump(self::$currencyListCache);
        // var_dump(self::$currencyListCacheTimeout);

        $cacheFile = __DIR__.'/../supported-currencies.json';
        $jsonList = file_get_contents($cacheFile);

        // Decode the JSON string into a PHP array
        $currencyList = json_decode($jsonList, true);

        foreach ($currencyList as $currency)
        {
            if ($currency['ISO Code'] === $currencyCode)
            {
                $orderAmount = $amount * pow(10,$currency['Exponent']);

                return [
                    'success'   => true,
                    'amount'    => $orderAmount
                ];
            }
        }

        return [
            'success'   => false,
            'error'     => 'Currency code not present in list of supported currencies'
        ];
    }
}
