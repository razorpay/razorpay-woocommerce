<?php
declare(strict_types=1);
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require_once('vendor/autoload.php');


function getToken() {
    $secret_Key    = '68V0zWFrS72GbpPreidkQFLfj4v9m3Ti+DXc8OB0gcM=';
    $date          = new DateTimeImmutable();
    $expire_at     = $date->modify('+6 minutes')->getTimestamp();      // Add 6 MINUTES
    $domainName    = "magic.razorpay.com";
    $username      = "rzpTestID";                                           // Retrieved from filtered POST data

    $request_data = [
    'iat'      => $date->getTimestamp(),         // Issued at: time when the token was generated
    'iss'      => $domainName,                   // Issuer
    'nbf'      => $date->getTimestamp(),         // Not before
    'exp'      => $expire_at,                    // Expire
    'userName' => $username,                 // User name
    ];


    // Encode the array to a JWT string.
    return JWT::encode(
        $request_data,
        $secret_Key,
        'HS512'
    );
}

function decodeToken($jwt) {
    $secret_Key  = '68V0zWFrS72GbpPreidkQFLfj4v9m3Ti+DXc8OB0gcM=';
    $token       = JWT::decode($jwt, $secret_Key, ['HS512']);
    $now         = new DateTimeImmutable();
    $serverName  = "magic.razorpay.com";

   if ($token->iss !== $serverName ||
        $token->nbf > $now->getTimestamp() ||
        $token->exp < $now->getTimestamp())
      {
         header('HTTP/1.1 401 Unauthorized');
         return false;
      }

     return true;
}
