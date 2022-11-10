<?php

class Test_Scripts_Styles extends WP_UnitTestCase
{
    public function testRegisteredScripts()
    {
        $this->assertFileExists(PLUGIN_DIR . '/script.js');

        $this->assertFileExists(PLUGIN_DIR . '/public/js/admin-rzp-settings.js');
    }
}
