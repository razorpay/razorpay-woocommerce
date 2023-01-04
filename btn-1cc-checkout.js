if (document.readyState !== 'loading') {
  btnCheckout();
} else {
 document.addEventListener('DOMContentLoaded', function () {
     btnCheckout();
 });
}

function btnCheckout(){

var btn = document.getElementById('btn-1cc');
var mobileBtn = document.querySelectorAll('#btn-1cc')[1];
var btnMini = document.getElementById('btn-1cc-mini-cart');
var btnPdp = document.getElementById('btn-1cc-pdp');
var rzpSpinnerBackdrop = document.getElementById('rzp-spinner-backdrop');
var rzpSpinner = document.getElementById('rzp-spinner');
var pageURL = jQuery(location).attr('href');
var url = new URL(pageURL);
var accessToken = new URLSearchParams(url.search).get('wcf_ac_token');
var referrerDomain = document.referrer.toString();
var flycartBtn = document.getElementsByClassName("woofc-action-checkout")[0];
var caddyBtn = document.getElementsByClassName('cc-button cc-button-primary')[0];
var sidecartBtn = document.getElementsByClassName('xoo-wsc-ft-btn button btn xoo-wsc-ft-btn-checkout')[0];
rzp1ccCheckoutData.referrerDomain = referrerDomain;

// event triggered by wc on any cart change
// as input function is the same, duplicate event listeners are NOT called
jQuery(document.body).on('updated_cart_totals', function(event) {
 var btn = document.getElementById('btn-1cc');
 if (btn !== null) {
   btn.addEventListener('click', openRzpCheckout);
 }

 if (mobileBtn != null) {
  mobileBtn.addEventListener('click', openRzpCheckout);
}

 var btnMini = document.getElementById('btn-1cc-mini-cart');
 if (btnMini !== null) {
   btnMini.addEventListener('click', openRzpCheckout);
 }

 var flycartBtn = document.getElementsByClassName("woofc-action-checkout")[0];
 
 if (flycartBtn != null) {
   flycartBtn.addEventListener('click', openRzpCheckout);
 }

 var caddyBtn = document.getElementsByClassName('cc-button cc-button-primary')[0];
 
 if (caddyBtn != null) {
   caddyBtn.addEventListener('click', openRzpCheckout);
 }

 var sidecartBtn = document.getElementsByClassName('xoo-wsc-ft-btn button btn xoo-wsc-ft-btn-checkout')[0];
 if (sidecartBtn != null) {
  sidecartBtn.addEventListener('click', openRzpCheckout);
}
});

function addEventListenerToMinicart(wcEvent) {
 jQuery(document.body).on(wcEvent, function(event) {
   var btnMini = document.getElementById('btn-1cc-mini-cart');
   if (btnMini !== null) {
     btnMini.addEventListener('click', openRzpCheckout);
   }

   var flycartBtn = document.getElementsByClassName("woofc-action-checkout")[0];

  if (flycartBtn != null) {
    flycartBtn.addEventListener('click', openRzpCheckout);
   }
   var caddyBtn = document.getElementsByClassName('cc-button cc-button-primary')[0];

  if (caddyBtn != null) {
    caddyBtn.addEventListener('click', openRzpCheckout);
   }

   var sidecartBtn = document.getElementsByClassName('xoo-wsc-ft-btn button btn xoo-wsc-ft-btn-checkout')[0];
  
   if (sidecartBtn != null) {
    sidecartBtn.addEventListener('click', openRzpCheckout);
   }

 });
}

var stickyBtn = document.querySelectorAll('#btn-1cc-pdp')[1];

if (stickyBtn != null) {

  // For attaching event listener to Woodmart's sticky add-to-cart
 document.addEventListener('scroll',(e)=>{
  
   let i = 0;
    while (typeof quantity === 'undefined') {
       var quantity = document.getElementsByClassName("qty")[i].value;
       i++;
    }

   stickyBtn.setAttribute('quantity', quantity);

     jQuery('.qty').on('change',function(e)
    {
       let x = 0;
        while (typeof quantity === 'undefined') {
         var quantity = document.getElementsByClassName("qty")[x].value;
         x++;
        }

       stickyBtn.setAttribute('quantity', quantity);

      if(quantity <= 0)
      {
         stickyBtn.classList.add("disabled");
         stickyBtn.disabled = true;
      }
       else
      {
         stickyBtn.classList.remove("disabled");
         stickyBtn.disabled = false;
     }
 });

 (function($){

     $('form.variations_form').on('show_variation', function(event, data){

         stickyBtn.classList.remove("disabled");
         stickyBtn.disabled = false;

         stickyBtn.setAttribute('variation_id', data.variation_id);

         var variationArr = {};

         $.each( data.attributes, function( key, value ) {
           variationArr[key] = $("[name="+key+"]").val();
         });

         stickyBtn.setAttribute('variations', JSON.stringify(variationArr));

     }).on('hide_variation', function() {

         stickyBtn.classList.add("disabled");
         stickyBtn.disabled = true;
     });
 })(jQuery);

   if (stickyBtn != null) {
       stickyBtn.onclick = function(){

         var pdpCheckout = stickyBtn.getAttribute('pdp_checkout');
         var productId = stickyBtn.getAttribute('product_id');
         var quantity = stickyBtn.getAttribute('quantity');
     
         rzp1ccCheckoutData.pdpCheckout = pdpCheckout;
         rzp1ccCheckoutData.productId = productId;
         rzp1ccCheckoutData.quantity = quantity;
     
         if (btnPdp.getAttribute('variation_id') != null) {
           var variationId = stickyBtn.getAttribute('variation_id');
           var variations = stickyBtn.getAttribute('variations');
     
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
 
     if (stickyBtn !== null) {
        stickyBtn.addEventListener('click', openRzpCheckout);
      }
})
}


addEventListenerToMinicart('wc_fragments_refreshed');
addEventListenerToMinicart('wc_fragments_loaded');
addEventListenerToMinicart('added_to_cart');

if (btnPdp != null) {
   btnPdp.onclick = productInfoHandler;
}

function productInfoHandler(){

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
   jQuery(document.body).trigger('wc_fragment_refresh');
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
 },
 getBrowserTime: function() {
   var dateTime = [];
   var date = new Date(),
       days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
       months = ['January', 'February', 'March', 'April', 'May', 'June',
           'July', 'August', 'September', 'October', 'November', 'December'
       ],
       hours = ['00-01', '01-02', '02-03', '03-04', '04-05', '05-06', '06-07', '07-08',
           '08-09', '09-10', '10-11', '11-12', '12-13', '13-14', '14-15', '15-16', '16-17',
           '17-18', '18-19', '19-20', '20-21', '21-22', '22-23', '23-24'
       ];
   dateTime.push(hours[date.getHours()]);
   dateTime.push(days[date.getDay()]);
   dateTime.push(months[date.getMonth()]);

   rzp1ccCheckoutData.dateTime = dateTime;
 }
}

if (btn !== null) {
 btn.addEventListener('click', openRzpCheckout);
}

if (mobileBtn != null) {
  mobileBtn.addEventListener('click', openRzpCheckout);
}

if (btnMini !== null) {
 btnMini.addEventListener('click', openRzpCheckout);
}

if (btnPdp !== null) {
 btnPdp.addEventListener('click', openRzpCheckout);
}

if (flycartBtn != null) {
 flycartBtn.addEventListener('click', openRzpCheckout);
}

if (caddyBtn != null) {
  caddyBtn.addEventListener('click', openRzpCheckout);
 }

 if (sidecartBtn != null) {
  sidecartBtn.addEventListener('click', openRzpCheckout);
 }

async function openRzpCheckout(e) {
 e.preventDefault();

 if( btnPdp !== null && btnPdp.classList.contains('disabled')){
   return;
 } 
 rzp1cc.showSpinner(true);

 if (accessToken !== null) 
 {
   rzp1ccCheckoutData.token = accessToken;
 }

 rzp1cc.getBrowserTime();
 

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
           },
           onload: setTimeout(() => {
             rzp1cc.handleAbandonmentCart(data.order_id);
             rzp1cc.enableCheckoutButtons();
           }, 25000),
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
       } else if (e.response.code == 'MIN_CART_AMOUNT_CHECK_FAILED' || e.response.code == 'WOOCOMMERCE_ORDER_CREATION_FAILED'){
         document.getElementById('error-message').innerHTML = "<p style='margin-top: revert;text-color: #e2401c !important;color: #e80707;'>"+e.response.message+"</p>"; // nosemgrep: insecure-innerhtml
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
}