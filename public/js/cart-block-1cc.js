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
                : 'wc-block-cart__submit-button';

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
     * Observes both new elements and updates inside existing cart block (e.g., class changes, re-render)
     */
    function observeCartBlockInsertion() {
        if (!window.MutationObserver) return;

        razorpayCartObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                // Check if new nodes are added or existing nodes are updated
                if (
                    mutation.type === 'childList' ||
                    mutation.type === 'attributes'
                ) {
                    const targetNode = mutation.target;

                    // Look for cart block container on every mutation (added or updated)
                    if (
                        $(targetNode).find('.wp-block-woocommerce-cart, .wc-block-cart').length > 0 ||
                        $(targetNode).hasClass('wp-block-woocommerce-cart') ||
                        $(targetNode).hasClass('wc-block-cart')
                    ) {
                        setTimeout(addMagicCheckoutButtonToCartBlock, 100);
                    }
                }
            });
        });

        razorpayCartObserver.observe(document.body, {
            childList: true,     // watch for new elements
            subtree: true,       // deeply observe all child elements
            attributes: true     // watch for attribute changes (like class updates)
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
