<style type="text/css">
#btn-1cc {
    margin-bottom: 11px !important;
    background-image: linear-gradient(to right, #2853FF, #1E4C9C) !important;
}
</style>
<div>
  <button
    id="btn-1cc"
    class="rzp-dual-checkout-button checkout-button button alt wc-forward"
    type="button"
    >CHECKOUT WITH MAGIC
  </button>
</div>

<div id="rzp-spinner-backdrop"></div>
<div id="rzp-spinner">
  <div id="loading-indicator"></div>
  <div id="icon">
    <img src="<?php echo plugin_dir_url(dirname(__FILE__)) . 'public/images/rzp-spinner.svg'; ?>" alt="Loading"  id="rzp-logo" />
  </div>
</div>
<div id="error-message">
</div>
