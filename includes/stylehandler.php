<?php
function styleHandler($theme){
 
    $theme         = strtolower(str_replace(' ', '', $theme));
    $defaultStyle  = plugin_dir_url(__DIR__)  . 'public/css/1cc-product-checkout.css';
    $cssFilePath   = plugin_dir_path( __DIR__ ). '/public/css/'. $theme .'.css';
    $css           = plugin_dir_url(__DIR__)  . 'public/css/'. $theme .'.css';

    return is_file($cssFilePath)?
      $css
    :$defaultStyle;
}
?>
