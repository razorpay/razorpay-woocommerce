<?php

class Util
{
    // wrapper for wp has_action() - for easy coding
    static public function has_action($action, $obj, $function )
    {
        $registered = has_action( $action,
            array(
                $obj,
                $function
            ));
        if ($registered)
        {
            return true;
        }
        else
        {
            return false;
        }
    }
}
