<?php
global $product;
$productData = wp_json_encode(['id' => $product->get_id(), 'quantity' => 1]);
?>
<button
  id="btn-1cc-pdp"
  class="button alt single_add_to_cart_button"
  type="button"
  product_id="<?php echo esc_attr($product->get_id()); ?>"
  value="<?php echo esc_attr($product->get_id()); ?>"
  pdp_checkout="<?php echo true; ?>"
  >BUY NOW
</button>

<script type="">
  let i = 0;
  while (typeof quantity === 'undefined') {
    var quantity = document.getElementsByClassName("qty")[i].value;
    i++;
  }

  var btnPdp = document.getElementById('btn-1cc-pdp');

  btnPdp.setAttribute('quantity', quantity);

  jQuery('.qty').on('change',function(e)
  {
      let x = 0;
      while (typeof quantity === 'undefined') {
        var quantity = document.getElementsByClassName("qty")[x].value;
        x++;
      }
      btnPdp.setAttribute('quantity', quantity);

      if(quantity <= 0)
      {
          btnPdp.classList.add("disabled");
          btnPdp.disabled = true;
      }
      else
      {
          btnPdp.classList.remove("disabled");
          btnPdp.disabled = false;
      }
  });

  (function($){

      $('form.variations_form').on('show_variation', function(event, data){

          btnPdp.classList.remove("disabled");
          btnPdp.disabled = false;

          btnPdp.setAttribute('variation_id', data.variation_id);

          var variationArr = {};

          $.each( data.attributes, function( key, value ) {
            variationArr[key] = $("[name="+key+"]").val();
          });

          btnPdp.setAttribute('variations', JSON.stringify(variationArr));

      }).on('hide_variation', function() {

          btnPdp.classList.add("disabled");
          btnPdp.disabled = true;
      });
  })(jQuery);
</script>
