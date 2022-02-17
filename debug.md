localhost - incognito - key + order_id sent


kinsta - incognito - key + order_id sent



localhost - incognito - only order_id


kinsta - incognito - only order_id


TODO
- Confirm if we send both `key` and `order_id`
-


Bug on Chrome NOT on Firefox
Incog -> prefill number + email -> open checkout for non-logged in user -> goes to address before otp check

Firefox Curl
```
curl 'https://omega-api.stage.razorpay.in/v1/customers/status/+918888888888?x_entity_id=order_IDu8TYW7wKolMv&_[platform]=browser' --globoff -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:93.0) Gecko/20100101 Firefox/93.0' -H 'Accept: */*' -H 'Accept-Language: en-US,en;q=0.5' --compressed -H 'DNT: 1' -H 'Connection: keep-alive' -H 'Referer: https://omega-api.stage.razorpay.in/test/checkout.html?branch=feat/1cc' -H 'Cookie: razorpay_api_session=eyJpdiI6IjJUYkU1Kzc2cXNLa2ZzeW5wNzhPTWc9PSIsInZhbHVlIjoiRDFSdFJhTllcL2NrbnI3UFlYQnh4SHRCazNYS0JlNlVla05HWm9rQ0piaVwvbzduemtPQ3did1RiZDQwUnRvWnVQOTVmVkx3aWhMbGVOeFZZVXpyRThlcU9UNUE5Z2xyXC9YNE5FM2VxcnBhb292RTJuSGRzaE1WeGtGZGF2OERGQW4iLCJtYWMiOiJmZjBjOWM0OTNlM2JiMTk0OGQ3MzdkYzRlMDQxYTg4ZjgyYzM2MjIzYjRiNzYzYWI2MGYzNTgyNmVhZjE4Nzg3In0%3D' -H 'Sec-Fetch-Dest: empty' -H 'Sec-Fetch-Mode: cors' -H 'Sec-Fetch-Site: same-origin'
```


Chrome Curl
```
curl 'https://omega-api.stage.razorpay.in/v1/customers/status/+918888888888?x_entity_id=order_IDu8TYW7wKolMv&_\[platform\]=browser' \
  -H 'Connection: keep-alive' \
  -H 'sec-ch-ua: "Google Chrome";v="95", "Chromium";v="95", ";Not A Brand";v="99"' \
  -H 'sec-ch-ua-mobile: ?0' \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.54 Safari/537.36' \
  -H 'sec-ch-ua-platform: "macOS"' \
  -H 'Accept: */*' \
  -H 'Sec-Fetch-Site: same-origin' \
  -H 'Sec-Fetch-Mode: cors' \
  -H 'Sec-Fetch-Dest: empty' \
  -H 'Referer: https://omega-api.stage.razorpay.in/test/checkout.html?branch=feat/1cc' \
  -H 'Accept-Language: en-GB,en;q=0.9' \
  --compressed
```



curl --location --request POST 'https://api.razorpay.com/v1/orders' \
--header 'Authorization: Basic cnpwX3Rlc3RfMURQNW1tT2xGNUc1YWc6dGhpc2lzc3VwZXJzZWNyZXQ=' \
--header 'Content-Type: application/json' \
--data-raw '{
    "amount": 100000,
    "currency": "INR",
    "receipt": "ORDER_COUPON",
    "payment_capture": 1,
    "notes": {
        "notes_key_1": "Book 1"
    }
}'
