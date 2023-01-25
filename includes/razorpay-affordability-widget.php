<?php

use Razorpay\Api\Api;

function addAffordabilityWidgetHTML()
{
    $current_user = wp_get_current_user();
    if ((isAffordabilityWidgetTestModeEnabled() === false) or 
        (isAffordabilityWidgetTestModeEnabled() and
        ($current_user->has_cap('administrator') or 
        preg_match('/@razorpay.com$/i', $current_user->user_email))))
    {
        echo '<div id="razorpay-affordability-widget" style="display: block;"></div>
        <script src="https://cdn.razorpay.com/widgets/affordability/affordability.js">
        </script>

        <script>
            const key = "'.getKeyId().'";
            const amount = parseFloat("'.getPrice().'s") * 100;
            addEventListener("load", 
            function() {
                const widgetConfig = {
                    "key": key,
                    "amount": amount,
                    "theme": {
                        "color": "'.getThemeColor().'"
                    },
                    "features": {
                        "offers": {
                            "list": '.getAdditionalOffers().',
                        }
                    },
                    "display": {
                        "offers": '.getOffers().',
                        "emi": '.getEmi().',
                        "cardlessEmi": '.getCardlessEmi().',
                        "paylater": '.getPayLater().',
                        "widget": {
                            "main": {
                                "heading": {
                                    "color": "'.getHeadingColor().'",
                                    "fontSize": "'.getHeadingFontSize().'px"
                                },
                                "content": {
                                    "color": "'.getContentColor().'",
                                    "fontSize": "'.getContentFontSize().'px"
                                },
                                "link": {
                                    "color": "'.getLinkColor().'",
                                    "fontSize": "'.getLinkFontSize().'px"
                                },
                                "footer": {
                                    "color": "'.getFooterColor().'",
                                    "fontSize": "'.getFooterFontSize().'px",
                                    "darkLogo": '.getFooterDarkLogo().'// true is default show black text rzp logo
                                }
                            }
                        }
                    }
                };
                const rzpAffordabilitySuite = new RazorpayAffordabilitySuite(widgetConfig);
                rzpAffordabilitySuite.render();
            });

            jQuery(function($) { 

                $.fn.myFunction = function()
                {
                    var variants = (document.querySelector("form.variations_form").dataset.product_variations);
                    var selectedVariantID = document.querySelector("input.variation_id").value;
                    var selectedVariant = JSON.parse(variants).filter( variant => variant.variation_id === parseInt(selectedVariantID));
                    
                    if(typeof(selectedVariant[0]) != "undefined")
                    {
                        amt = selectedVariant[0].display_price * 100;
                        const widgetConfig = {
                            "key": key,
                            "amount": amt,
                            "theme": {
                                "color": "'.getThemeColor().'"
                            },
                            "features": {
                                "offers": {
                                    "list": '.getAdditionalOffers().',
                                }
                            },
                            "display": {
                                "offers": '.getOffers().',
                                "emi": '.getEmi().',
                                "cardlessEmi": '.getCardlessEmi().',
                                "paylater": '.getPayLater().',
                                "widget": {
                                    "main": {
                                        "heading": {
                                            "color": "'.getHeadingColor().'",
                                            "fontSize": "'.getHeadingFontSize().'px"
                                        },
                                        "content": {
                                            "color": "'.getContentColor().'",
                                            "fontSize": "'.getContentFontSize().'px"
                                        },
                                        "link": {
                                            "color": "'.getLinkColor().'",
                                            "fontSize": "'.getLinkFontSize().'px"
                                        },
                                        "footer": {
                                            "color": "'.getFooterColor().'",
                                            "fontSize": "'.getFooterFontSize().'px",
                                            "darkLogo": '.getFooterDarkLogo().'// true is default show black text rzp logo
                                        }
                                    }
                                }
                            }
                        };
                        const rzpAffordabilitySuite = new RazorpayAffordabilitySuite(widgetConfig);
                        rzpAffordabilitySuite.render();
                    }
                }

                $("input.variation_id").change(function(){
                    $.fn.myFunction();
                });

            });

        </script>
        ';
    }
}

function getKeyId()
{
    return get_option('woocommerce_razorpay_settings')['key_id'];
}

function getPrice()
{
    global $product;
    if ($product->is_type('simple') === true)
    {
        if ($product->is_on_sale()) 
        {
            $price = $product->get_sale_price();
        }
        else
        {
            $price = $product->get_regular_price();
        }
    }
    else
    {
        $price = $product->get_price(); 
    }

    return $price;
}

function getOffers()
{
    $offers = isEnabled('rzp_afd_enable_offers');
    
    if (empty(get_option('rzp_afd_limited_offers')) === false and 
        $offers != 'false')
    {
        $offers = '{ "offerIds": [';
        foreach (explode(',', get_option('rzp_afd_limited_offers')) as $provider)
        {
            $offers = $offers.'"'.$provider.'"';
            $offers = $offers.',';
        }	
        $offers = $offers.']';
    }
    if (empty(get_option('rzp_afd_show_discount_amount')) === false and 
        $offers != 'false')
    {
        if ($offers != 'true')
        {
            $offers = $offers.',';
        }
        else 
        {
            $offers = '{';
        }
        $offers = $offers.'"showDiscount": ';
        $showDiscount = isEnabled('rzp_afd_show_discount_amount');
        $offers = $offers.$showDiscount;
        $offers = $offers.'}';
    }

    return $offers;
}

function getAdditionalOffers()
{
    $additionalOffers = '[]';
    if (empty(get_option('rzp_afd_additional_offers')) === false and 
        getOffers() != 'false')
    {
        $additionalOffers = '[';
        foreach (explode(",", get_option('rzp_afd_additional_offers')) as $provider)
        {
            $additionalOffers = $additionalOffers.'"'.$provider.'"';
            $additionalOffers = $additionalOffers.',';
        }	
        $additionalOffers = $additionalOffers.']';
    }

    return $additionalOffers;
}

function getEmi()
{
    $emi = isEnabled('rzp_afd_enable_emi');
    
    if (empty(get_option('rzp_afd_limited_emi_providers')) === false and 
        $emi != 'false')
    {
        $emi = '{ "issuers": [';
        foreach (explode(",", get_option('rzp_afd_limited_emi_providers')) as $provider)
        {
            $emi = $emi.'"'.$provider.'"';
            $emi = $emi.',';
        }	
        $emi = $emi.'] }';
    }

    return $emi;
}

function getCardlessEmi()
{
    $cardlessEmi = isEnabled('rzp_afd_enable_cardless_emi');
    
    if (empty(get_option('rzp_afd_limited_cardless_emi_providers')) === false and 
        $cardlessEmi != 'false')
    {
        $cardlessEmi = '{ "providers": [';
        foreach (explode(",", get_option('rzp_afd_limited_cardless_emi_providers')) as $provider)
        {
            $cardlessEmi = $cardlessEmi.'"'.$provider.'"';
            $cardlessEmi = $cardlessEmi.',';
        }	
        $cardlessEmi = $cardlessEmi.'] }';
    }

    return $cardlessEmi;
}

function getPayLater()
{
    $payLater = isEnabled('rzp_afd_enable_pay_later');
    
    if (empty(get_option('rzp_afd_limited_pay_later_providers')) === false and 
        $payLater != 'false')
    {
        $payLater = '{ "providers": [';
        foreach (explode(",", get_option('rzp_afd_limited_pay_later_providers')) as $provider)
        {
            $payLater = $payLater.'"'.$provider.'"';
            $payLater = $payLater.',';
        }	
        $payLater = $payLater.'] }';
    }

    return $payLater;
}

function getThemeColor()
{
    return getCustomisation('rzp_afd_theme_color');
}

function getHeadingColor()
{
    return getCustomisation('rzp_afd_heading_color');
}

function getHeadingFontSize()
{
    return getCustomisation('rzp_afd_heading_font_size');
}

function getContentColor()
{
    return getCustomisation('rzp_afd_content_color');
}

function getContentFontSize()
{
    return getCustomisation('rzp_afd_content_font_size');
}

function getLinkColor()
{
    return getCustomisation('rzp_afd_link_color');
}

function getLinkFontSize()
{
    return getCustomisation('rzp_afd_link_font_size');
}

function getFooterColor()
{
    return getCustomisation('rzp_afd_footer_color');
}

function getFooterFontSize()
{
    return getCustomisation('rzp_afd_footer_font_size');
}

function getFooterDarkLogo()
{
    $footerDarkLogo = isEnabled('rzp_afd_enable_dark_logo');
    
    return $footerDarkLogo;
}

function addSubSection() 
{
    global $current_section;

    $tab_id = 'checkout';

    $sections = array(
        'razorpay'              => __('Plugin Settings'),
        'affordability-widget'  => __('Affordability Widget'),
    );

    echo '<ul class="subsubsub">';

    $array_keys = array_keys($sections);

    foreach ($sections as $id => $label) 
    {
        if ($current_section === 'razorpay' or 
            $current_section === 'affordability-widget')
        {
            echo '<li><a href="'.admin_url('admin.php?page=wc-settings&tab='.$tab_id.
            '&section='.sanitize_title( $id )).'" class="'.($current_section === $id ? 'current' : '').'">'.
            $label.'</a> '.(end($array_keys) === $id ? '' : '|').' </li>';
        }
    }
   
    echo '</ul><br class="clear" />';
}

function getAffordabilityWidgetSettings() 
{
    global $current_section;
    $settings = array();

    if ($current_section === 'affordability-widget') 
    {
        $settings = array(
            'section_title' => array(
                'name'                  => __('Affordability Widget Settings'),
                'type'                  => 'title',
                'desc'                  => '',
                'id'                    => 'rzp_afd_section_title'
            ),
            'enable' => array(
                'title'                 => __('Affordability Widget Enable/Disable'),
                'type'                  => 'hidden',
                'desc'                  => __('Enable Affordability Widget?'),
                'default'               => 'no',
                'id'                    => 'rzp_afd_enable'
            ),
            'enable_test_mode' => array(
                'title'                 => __('Test Mode Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable Test Mode?'),
                'default'               => 'no',
                'id'                    => 'rzp_afd_enable_test_mode'
            ),
            'enable_offers' => array(
                'title'                 => __('Offers Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable offers?'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_enable_offers'
            ),
            'additional_offers' => array(
                'title'                 => __('Additional Offers'),
                'type'                  => 'textarea',
                'desc'                  =>  __('Enter offer id for offer that did not have the \'Show Offer on Checkout\' option enabled at the time of creation.'),
                'id'                    => 'rzp_afd_additional_offers'
            ),
            'limited_offers' => array(
                'title'                 => __('Limited Offers'),
                'type'                  => 'textarea',
                'desc'                  =>  __('In case you want to display limited offers on the widget, enter the offer_id of your choice that you want to display.'),
                'id'                    => 'rzp_afd_limited_offers'
            ),
            'show_discount_amount' => array(
                'title'                 => __('Show Discount Amount'),
                'type'                  => 'checkbox',
                'desc'                  => __('Display the exact amount of discount on offers'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_show_discount_amount'
            ),
            'enable_emi' => array(
                'title'                 => __('Card EMI Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable Card EMI?'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_enable_emi'
            ),
            'limited_emi_providers' => array(
                'title'                 => __('Limited Card EMI Providers'),
                'type'                  => 'textarea',
                'desc'                  =>  __('In case you want to display limited EMI options on the widget, enter the list of provider codes based on your requirement.Please find the list of <a href="https://razorpay.com/docs/payments/payment-gateway/affordability/faqs/#2-what-are-the-standard-credit-card-interest">provider codes</a> here.'),
                'id'                    => 'rzp_afd_limited_emi_providers'
            ),
            'enable_cardless_emi' => array(
                'title'                 => __('Cardless EMI Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable Cardless EMI?'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_enable_cardless_emi'
            ),
            'limited_cardles_emi_providers' => array(
                'title'                 => __('Limited Cardless EMI Providers'),
                'type'                  => 'textarea',
                'desc'                  =>  __('In case you want to display limited Cardless EMI options on the widget, enter the list of provider codes based on your requirement.Please find the list of <a href="https://razorpay.com/docs/payments/payment-methods/emi/cardless-emi/">provider codes</a> here.'),
                'id'                    => 'rzp_afd_limited_cardless_emi_providers'
            ),
            'enable_pay_later' => array(
                'title'                 => __('Pay Later Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable Pay Later?'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_enable_pay_later'
            ),
            'limited_pay_later_providers' => array(
                'title'                 => __('Limited Pay Later Providers'),
                'type'                  => 'textarea',
                'desc'                  =>  __('In case you want to display limited Pay Later options on the widget, enter the list of provider codes based on your requirement.Please find the list of <a href="https://razorpay.com/docs/payments/payment-methods/pay-later/">provider codes</a> here.'),
                'id'                    => 'rzp_afd_limited_pay_later_providers'
            ),
            'theme_color' => array(
                'title'                 => __('Theme Color'),
                'type'                  => 'text',
                'desc'                  => __('Enter the 6 character hex code of the theme color based on your requirement. Default is blue.'),
                'id'                    => 'rzp_afd_theme_color'
            ),
            'heading_color' => array(
                'title'                 => __('Heading Color'),
                'type'                  => 'text',
                'desc'                  => __('Enter the heading color based on your requirement. Default is black.' ),
                'id'                    => 'rzp_afd_heading_color'
            ),
            'heading_font_size' => array(
                'title'                 => __('Heading Font Size'),
                'type'                  => 'text',
                'desc'                  => __('Enter the font size of heading in px based on your requirement. Default is 10px.'),
                'id'                    => 'rzp_afd_heading_font_size'
            ),
            'content_color' => array(
                'title'                 => __('Content Color'),
                'type'                  => 'text',
                'desc'                  => __('Enter the content color based on your requirement. Default is grey.'),
                'id'                    => 'rzp_afd_content_color'
            ),
            'content_font_size' => array(
                'title'                 => __('Content Font Size'),
                'type'                  => 'text',
                'desc'                  => __('Enter the font size of content in px based on your requirement. Default is 10px.'),
                'id'                    => 'rzp_afd_content_font_size'
            ),
            'link_color' => array(
                'title'                 => __('Link Color'),
                'type'                  => 'text',
                'desc'                  => __('Enter the color based on your requirement. Default is blue.'),
                'id'                    => 'rzp_afd_link_color'
            ),
            'link_font_size' => array(
                'title'                 => __('Link Font Size'),
                'type'                  => 'text',
                'desc'                  => __('Enter the font size of link in px based on your requirement. Default is 10px.'),
                'id'                    => 'rzp_afd_link_font_size'
            ),
            'footer_color' => array(
                'title'                 => __('Footer Color'),
                'type'                  => 'text',
                'desc'                  => __('Enter the color based on your requirement. Default is grey.'),
                'id'                    => 'rzp_afd_footer_color'
            ),
            'footer_font_size' => array(
                'title'                 => __('Footer Font Size'),
                'type'                  => 'text',
                'desc'                  => __('Enter the font size of footer in px based on your requirement. Default is 10px.'),
                'id'                    => 'rzp_afd_footer_font_size'
            ),
            'dark_logo' => array(
                'title'                 => __('Dark Logo Enable/Disable'),
                'type'                  => 'checkbox',
                'desc'                  => __('Enable Dark Logo?'),
                'default'               => 'yes',
                'id'                    => 'rzp_afd_enable_dark_logo'
            ),
            'section_end' => array(
                 'type'                 => 'sectionend',
                 'id'                   => 'wc_settings_tab_demo_section_end'
            ),
        );
    } 

    return apply_filters('wc_affordability_widget_settings', $settings);
}

function displayAffordabilityWidgetSettings() 
{
    woocommerce_admin_fields(getAffordabilityWidgetSettings()); 
}

function updateAffordabilityWidgetSettings() 
{
    woocommerce_update_options(getAffordabilityWidgetSettings());
    try
    {
        if (isset($_POST['woocommerce_razorpay_key_id']) and
            empty($_POST['woocommerce_razorpay_key_id']) === false and
            isset($_POST['woocommerce_razorpay_key_secret']) and
            empty($_POST['woocommerce_razorpay_key_secret']) === false)
        {
            $api = new Api($_POST['woocommerce_razorpay_key_id'], $_POST['woocommerce_razorpay_key_secret']);
        }
        else
        {
            $api = new Api(get_option('woocommerce_razorpay_settings')['key_id'],get_option('woocommerce_razorpay_settings')['key_secret']);
        }
        
        $merchantPreferences = $api->request->request('GET', 'accounts/me/features');
        
        if (isset($merchantPreferences) === false or
            isset($merchantPreferences['assigned_features']) === false)
        {
            throw new Exception("Error in Api call.");
        }

        update_option('rzp_afd_enable', 'no');
        foreach ($merchantPreferences['assigned_features'] as $preference)
        {
            if ($preference['name'] === 'affordability_widget' or
                $preference['name'] === 'affordability_widget_set')
            {
                update_option('rzp_afd_enable', 'yes');
                break;
            }
        }
        
    }
    catch (\Exception $e)
    {
        rzpLogError($e->getMessage());
        return;
    }
}

function isEnabled($feature)
{
    if (empty(get_option($feature)) === true)
    {
        return 'true';
    }
    $value = 'false';

    if (empty(get_option($feature)) === false and 
        get_option($feature) === 'yes')
    {
        $value = 'true';
    }
    
    return $value;
}

function getCustomisation($customisation)
{
    $defaultCustomisationValues = [
        'rzp_afd_theme_color'               => '#8BBFFF',
        'rzp_afd_heading_color'             => 'black',
        'rzp_afd_heading_font_size'         => '10',
        'rzp_afd_content_color'             => 'grey',
        'rzp_afd_content_font_size'         => '10',
        'rzp_afd_link_color'                => 'blue',
        'rzp_afd_link_font_size'            => '10',
        'rzp_afd_footer_color'              => 'grey',
        'rzp_afd_footer_font_size'          => '10'
    ];

    $customisationValue = $defaultCustomisationValues[$customisation];
    if (empty(get_option($customisation)) === false)
    {
        $customisationValue = get_option($customisation);
    }
    
    return $customisationValue;
}

function isAffordabilityWidgetTestModeEnabled()
{
    if (empty(get_option('rzp_afd_enable_test_mode')) === true)
    {
        return false;
    }
    return (
        empty(get_option('rzp_afd_enable_test_mode')) === false and
        get_option('rzp_afd_enable_test_mode') === 'yes'
    );
}	
