jQuery(document).ready(function($) {

    let razorpayCartObserver;

    /**
     * Adds Razorpay Magic Checkout button inside WooCommerce Cart Block.
     */
    function addMagicCheckoutButtonToCartBlock() {
        try {
			// Find the checkout button in cart block - trying multiple selectors for different WC versions
            const checkoutButtonContainer = $(
                '.wp-block-woocommerce-cart .wc-block-cart__submit-container, ' +
                '.wp-block-woocommerce-cart .wc-block-cart__submit-button-container, ' +
                '.wc-block-cart .wc-block-cart__submit-container, ' +
                '.wc-block-cart .wc-block-cart__submit-button-container'
            );

            // Avoid duplicate button insertion
            if (checkoutButtonContainer.length === 0 || $('#btn-1cc').length > 0) return;

            const originalButton = checkoutButtonContainer.find(
                '.wc-block-cart__submit-button, .wc-block-components-checkout-button'
            ).first();
            // Clone label and classes
            const buttonLabel = originalButton.length ? originalButton.text().trim() : 'PROCEED TO CHECKOUT';
            const buttonClasses = originalButton.length
                ? originalButton.attr('class')
                : 'button alt wc-forward wc-block-cart__submit-button';

            const magicButton = $('<button>', {
                id: 'btn-1cc',
                type: 'button',
                class: `${buttonClasses} rzp-checkout-button`,
                text: buttonLabel
            });

            checkoutButtonContainer.append(magicButton);
            attachMagicCheckoutListener();

        } catch (error) {
            console.error('Razorpay 1CC: Error adding button to cart block:', error);
        }
    }

    /**
     * Attaches the Razorpay checkout listener to the custom button.
     * Waits and retries if `openRzpCheckout` function is not yet available.
     */
    function attachMagicCheckoutListener() {
        const magicButton = document.getElementById('btn-1cc');

        if (!magicButton) return;

        if (typeof openRzpCheckout === 'function') {
            magicButton.removeEventListener('click', openRzpCheckout);
            magicButton.addEventListener('click', openRzpCheckout);
        } else {
            // Retry after short delay
            setTimeout(attachMagicCheckoutListener, 100);
        }
    }

    /**
     * Observes DOM changes and adds button if cart block is dynamically inserted.
     */
    function observeCartBlockInsertion() {
        if (!window.MutationObserver) return;

        razorpayCartObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    for (let node of mutation.addedNodes) {
                        if (node.nodeType === 1) {
                            const $node = $(node);
                            if (
                                $node.find('.wp-block-woocommerce-cart, .wc-block-cart').length > 0 ||
                                $node.hasClass('wp-block-woocommerce-cart') ||
                                $node.hasClass('wc-block-cart')
                            ) {
                                setTimeout(addMagicCheckoutButtonToCartBlock, 100);
                                break;
                            }
                        }
                    }
                }
            });
        });

        razorpayCartObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    /**
     * Binds event listeners for cart updates (classic + block-based).
     */
    function bindCartUpdateListeners() {
        $(document.body).on(
            'updated_wc_div updated_cart_totals wc_cart_button_updated wc_blocks_cart_update wc_blocks_cart_loaded',
            function () {
                setTimeout(addMagicCheckoutButtonToCartBlock, 100);
            }
        );
    }

    // Init
    addMagicCheckoutButtonToCartBlock();
    bindCartUpdateListeners();
    observeCartBlockInsertion();

    // Clean up observer on page unload
    $(window).on('beforeunload', function () {
        if (razorpayCartObserver) {
            razorpayCartObserver.disconnect();
        }
    });
});
