<?php

namespace Razorpay\Api;

class Api
{
    protected static $baseUrl = 'https://api.razorpay.com';

    protected static $key = null;

    protected static $secret = null;

    /*
     * App info is to store the Plugin/integration
     * information
     */
    public static $appsDetails = array();

    const VERSION = '2.9.0';

    /**
     * @param string $key
     * @param string $secret
     */
    public function __construct($key, $secret)
    {
        self::$key = $key;
        self::$secret = $secret;

        $cacheFile = __DIR__.'/../supported-currencies.json';
        $cacheLifetime = 10000;

        if (file_exists($cacheFile) === false or
            (time() - filemtime($cacheFile)) > $cacheLifetime)
        {
            echo "new fetch";
            $url = 'https://4c26-115-110-224-178.ngrok-free.app/fetch-supported-currencies.php';

            $csvContent = file_get_contents($url);

            $rows = array_map('str_getcsv', explode("\n", trim($csvContent)));

            $header = array_shift($rows); 
            
            $currencyList = [];
            foreach ($rows as $row) {
                if (count($row) === count($header)) {
                    $currencyList[] = array_combine($header, $row);
                }
            }

            $currencyList = json_encode($currencyList, JSON_PRETTY_PRINT);

            file_put_contents($cacheFile, $currencyList);
        }

    }

    /*
     *  Set Headers
     */
    public function setHeader($header, $value)
    {
        Request::addHeader($header, $value);
    }

    public function setAppDetails($title, $version = null)
    {
        $app = array(
            'title' => $title,
            'version' => $version
        );

        array_push(self::$appsDetails, $app);
    }

    public function getAppsDetails()
    {
        return self::$appsDetails;
    }

    public function setBaseUrl($baseUrl)
    {
        self::$baseUrl = $baseUrl;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $className = __NAMESPACE__.'\\'.ucwords($name);

        $entity = new $className();

        return $entity;
    }

    public static function getBaseUrl()
    {
        return self::$baseUrl;
    }

    public static function getKey()
    {
        return self::$key;
    }

    public static function getSecret()
    {
        return self::$secret;
    }

    public static function getFullUrl($relativeUrl, $apiVersion = "v1")
    {
        return self::getBaseUrl() . "/". $apiVersion . "/". $relativeUrl;
    }
}
