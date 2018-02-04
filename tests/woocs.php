<?php

class WOOCS
{
    public function __construct()
    {

    }

    function get_currencies()
    {
        $currencies = array(
            'GBP' => array(
                'name' => 'GBP',
                'rate' => .75,
                'symbol' => '£',
                'position' => 'right',
                'is_etalon' => 1,
                'description' => 'Great Britan Pound',
                'hide_cents' => 0,
                'flag' => ''
            ),
            'INR' => array(
                'name' => 'INR',
                'rate' => 0.89,
                'symbol' => '&rs;',
                'position' => 'left_space',
                'is_etalon' => 0,
                'description' => 'Indian Rs',
                'hide_cents' => 0,
                'flag' => ''
            )
        );
        return $currencies;
    }
}
