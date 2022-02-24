window.addEventListener('DOMContentLoaded', function() {

  var btn = document.getElementById('btn-1cc');
  var btnMini = document.getElementById('btn-1cc-mini-cart');
  var btnPdp = document.getElementById('btn-1cc-pdp');
  var rzpSpinnerBackdrop = document.getElementById('rzp-spinner-backdrop');
  var rzpSpinner = document.getElementById('rzp-spinner');
  var pageURL = jQuery(location).attr('href');
  var url = new URL(pageURL);
  var accessToken = new URLSearchParams(url.search).get('wcf_ac_token');

  // event triggered by wc on any cart change
  // as input function is the same, duplicate event listeners are NOT called
  jQuery(document.body).on('updated_cart_totals', function(event) {
    var btn = document.getElementById('btn-1cc');
    if (btn !== null) {
      btn.addEventListener('click', openRzpCheckout);
    }

    var btnMini = document.getElementById('btn-1cc-mini-cart');
    if (btnMini !== null) {
      btnMini.addEventListener('click', openRzpCheckout);
    }
  });

  function addEventListenerToMinicart(wcEvent) {
    jQuery(document.body).on(wcEvent, function(event) {
      var btnMini = document.getElementById('btn-1cc-mini-cart');
      if (btnMini !== null) {
        btnMini.addEventListener('click', openRzpCheckout);
      }
    });
  }

  addEventListenerToMinicart('wc_fragments_refreshed');
  addEventListenerToMinicart('wc_fragments_loaded');
  addEventListenerToMinicart('added_to_cart');

  if (btnPdp != null) {
    btnPdp.onclick = function() {

      var pdpCheckout = btnPdp.getAttribute('pdp_checkout');
      var productId = btnPdp.getAttribute('product_id');
      var quantity = btnPdp.getAttribute('quantity');

      rzp1ccCheckoutData.pdpCheckout = pdpCheckout;
      rzp1ccCheckoutData.productId = productId;
      rzp1ccCheckoutData.quantity = quantity;

      if (btnPdp.getAttribute('variation_id') != null) {
        var variationId = btnPdp.getAttribute('variation_id');
        var variations = btnPdp.getAttribute('variations');

        rzp1ccCheckoutData.variationId = variationId;
        rzp1ccCheckoutData.variations = variations;
      }

      //To support custom product fields plugin.
      const customFieldForm = document.getElementsByClassName('wcpa_form_outer');

      if (customFieldForm && customFieldForm.length > 0) {

        var customProductFieldForm = customFieldForm[0];

        var fieldValues = customProductFieldForm.getElementsByTagName('input');
        var fieldKey = customProductFieldForm.getElementsByTagName('label');
        var fieldArray = [];
        var fieldObj = {};

        for (i = 0; i < fieldKey.length; i++) {
          fieldObj[fieldKey[i].innerText] = fieldValues[i].value;
        }

        rzp1ccCheckoutData.fieldObj = fieldObj;
      }
    }
  }

  // fetch opts from server and open 1cc modal
  var rzp1cc = {
    orderApi: rzp1ccCheckoutData.siteurl + '/wp-json/1cc/v1/order/create',
    saveAbandonedCartApi: rzp1ccCheckoutData.siteurl + '/wp-json/1cc/v1/abandoned-cart',
    makeRequest: function(url, body) {
      return new Promise(function(resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-WP-Nonce', rzp1ccCheckoutData.nonce);
        xhr.onload = function() {
          if (this.status === 200) {
            resolve(rzp1cc.parseIfJson(this.response));
          } else {
            reject({ status: this.status, response: rzp1cc.parseIfJson(this.response) });
          }
        }
        xhr.onerror = function () {
          reject({ status: this.status, statusText: this.statusText});
        };
        xhr.send(JSON.stringify(body));
      });
    },
    parseIfJson: function (str) {
      try {
        return JSON.parse(str);
      } catch (e) {
        return str;
      }
    },
    setDisabled: function(id, state) {
      if (typeof state === 'undefined') {
        state = true;
      }
      var elem = document.getElementById(id);

      if(elem != null)
      {
        if (state === false) {
          elem.removeAttribute('disabled');
        } else {
          elem.setAttribute('disabled', state);
        }
      }
    },
    showSpinner: function(state) {
      if (rzpSpinnerBackdrop == null) {
        rzpSpinnerBackdrop = document.getElementById('rzp-spinner-backdrop');
      }
      if (rzpSpinner == null) {
        rzpSpinner = document.getElementById('rzp-spinner');
      }
      if (state === true) {
        rzpSpinnerBackdrop.classList.add('show');
        rzpSpinner.classList.add('show');
      } else {
        rzpSpinnerBackdrop.classList.remove('show');
        rzpSpinner.classList.remove('show');
      }
    },
    handleAbandonmentCart: function(rzpOrderId) {
      if(rzpOrderId != null) {
        var xhr = new XMLHttpRequest();
        try {
          var body = {
            order_id: rzpOrderId
          };
          xhr.open('POST', rzp1cc.saveAbandonedCartApi, true);
          xhr.setRequestHeader('Content-Type', 'application/json');
          xhr.send(JSON.stringify(body));
        } catch (e) {

        }
      }
    },
    enableCheckoutButtons: function() {
      rzp1cc.setDisabled('btn-1cc', false);
      rzp1cc.setDisabled('btn-1cc-mini-cart', false);
      rzp1cc.setDisabled('btn-1cc-pdp', false);
    }
  }

  if (btn !== null) {
    btn.addEventListener('click', openRzpCheckout);
  }

  if (btnMini !== null) {
    btnMini.addEventListener('click', openRzpCheckout);
  }

  if (btnPdp !== null) {
    btnPdp.addEventListener('click', openRzpCheckout);
  }
  
  async function openRzpCheckout(e) {
    e.preventDefault();
    rzp1cc.showSpinner(true);

    if (accessToken !== null) 
    {
      rzp1ccCheckoutData.token = accessToken;
    }
    
    var body = rzp1ccCheckoutData;

    rzp1cc.setDisabled('btn-1cc');
    rzp1cc.setDisabled('btn-1cc-mini-cart');
    rzp1cc.setDisabled('btn-1cc-pdp');

    rzp1cc.makeRequest(rzp1cc.orderApi, body)
      .then(data => {
        rzp1cc.showSpinner(false);
        try {
          var razorpayCheckout = new Razorpay({
            ...data,
            modal: {
              ondismiss: function() {
                rzp1cc.handleAbandonmentCart(data.order_id);
                rzp1cc.enableCheckoutButtons();
              }
            },
          });
          razorpayCheckout.open();

        } catch (e) {
          document.getElementById('error-message').innerHTML =
            "<div class='entry-content'><div class='woocommerce'><div class='woocommerce-notices-wrapper'><p class='cart-empty woocommerce-info' style='margin-left: -50px; margin-right: 75px'>Something went wrong, please try again after sometime.</p></div></div></div>";

          rzp1cc.enableCheckoutButtons();
          rzp1cc.showSpinner(false);

        }
      })
      .catch(e => {
        // Response sent to the User when cart is empty or order creation fails
        if (e.status == 400){
          if (e.response.code == 'BAD_REQUEST_EMPTY_CART'){
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>Order could not be placed as your cart is empty.</p>";
          } else if (e.response.code == 'ORDER_CREATION_FAILED'){
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>Razorpay Error: Order could not be placed, please try again after sometime.</p>";
          } else if (e.response.code == 'MIN_CART_AMOUNT_CHECK_FAILED'){
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>"+e.response.message+"</p>";
          } else if (e.response.code == 'WOOCOMMERCE_ORDER_CREATION_FAILED'){
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>Order could not be placed, please connect with the "+rzp1ccCheckoutData.blogname+"</p>";
          } else {
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>Something went wrong, please try again after sometime.</p>";
          }

        } else {
            document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>Something went wrong, please try again after sometime.</p>";
        }

        rzp1cc.enableCheckoutButtons();
        rzp1cc.showSpinner(false);
      });
  }
});