<?php

/**
 * @covers \WC_Razorpay
 */
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../../../includes/state-map.php';

use Razorpay\MockApi\MockApi;

class Test_Maigc_State_Api extends WP_UnitTestCase
{

    public $razorpayTests;

    public function setup():void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }
    
    public function testGetWcStateCodeFromName(){

        $actualValue = getWcStateCodeFromName('LADAKH');

        $expectedValue = 'LA';

        $this->assertEquals($expectedValue, $actualValue);

    }

    // function testNormalizeWcStateCode()
    // {
    //     $actualValue = normalizeWcStateCode('TG');

    //     $expectedValue = 'TS';

    //      $this->assertEquals($expectedValue, $actualValue);
    // }

    // function testIsRazorpayPluginEnabled()
    // {
    //     $res = isRazorpayPluginEnabled();

    //      $this->assertIsBool($res);
           
    // }

    // function testIsTestModeEnabled()
    // {
    //     $res = isTestModeEnabled();

    //      $this->assertIsBool($res);

    // }

    // function testIsMiniCartCheckoutEnabled()
    // {
    //     $res = isMiniCartCheckoutEnabled();

    //      $this->assertIsBool($res);

    // }

    // function testIs1ccEnabled()
    // {
    //     $res = is1ccEnabled();

    //       $this->assertIsBool($res);

    // }

    // function testIsMandatoryAccCreationEnabled()
    // {
    //     $res = isMandatoryAccCreationEnabled();

    //       $this->assertIsBool($res);

    // }

    // function testIsPdpCheckoutEnabled()
    // {
    //     $res = isPdpCheckoutEnabled();

    //       $this->assertIsBool($res);

    // }

    // function testIsDebugModeEnabled()
    // {
    //     $res = isDebugModeEnabled();

    //       $this->assertIsBool($res);

    // }

    // function testValidateInput(){

    //     $param = array();
    //     $actualValue = validateInput('list', $param);

    //      $expectedValue = 'Field amount is required.';

    //     $this->assertEquals($expectedValue, $actualValue);

    // }

   
}
