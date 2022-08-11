const merchantName =
  preloadCheckoutData || '';
const styleSnippet = `
#magic-modal {
  font-family: Inter, lato, ubuntu, helvetica, sans-serif;
  font-feature-settings: "liga", 0;
  font-size: 14px;
  background: white;
  width: 344px;
  box-sizing: border-box;
  color: rgb(38, 58, 74);
  display: inline-flex;
  flex-direction: column;
  white-space: normal;
  z-index: 1;
  height: 560px;
  vertical-align: middle;
}

@media (min-width: 992px) {
  #magic-modal {
    height: 600px;
  }
}

#magic-wrapper {
  position: fixed;
  top: 0;
  width: 100%;
  height: 100%;
  max-height: 100%;
  bottom: 0;
  left: 0;
  right: 0;
  text-align: center;
  z-index: 2147483647;
  background: rgba(0, 0, 0, 0.6);

  -webkit-transition: opacity 0.6s ease-in;
  -moz-transition: opacity 0.6s ease-in;
   -ms-transition: opacity 0.6s ease-in;
    -o-transition: opacity 0.6s ease-in;
       transition: opacity 0.6s ease-in;
}

#magic-wrapper::after {
  content: '';
  height: 96%;
  display: inline-block;
  width: 0;
  vertical-align: middle;
}

.magic-shimmer {
  background: #F0F0F0;
}

.magic-shimmer::after {
  content: '';
  width: 100%;
  height: 100%;
  background-image: linear-gradient(114deg, #F0F0F0 0%, rgba(255, 255, 255, 0.7) 30%, rgba(255, 255, 255, 0) 40%, #F0F0F0 100%);
  background-repeat: no-repeat;
  background-size: 800px 104px; 
  display: inline-block;
  position: relative; 
  
  -webkit-animation-duration: 2s;
  -webkit-animation-fill-mode: forwards; 
  -webkit-animation-iteration-count: infinite;
  -webkit-animation-name: magicShimmer;
  -webkit-animation-timing-function: linear;
}

.magic-w-100 {
  width: 100%;
}

@-webkit-keyframes magicShimmer {
  0% {
    background-position: -468px 0;
  }
  
  100% {
    background-position: 468px 0; 
  }
}

#magic-merchant-heading {
  padding: 8px 16px 18px;
  height: 76px;
  min-height: 76px;
  box-sizing: border-box;
  display: flex;
  justify-content: space-between;
  align-items: end;
}

#magic-close-action {
  display: block;
  width: 60px;
  height: 20px;
}

#magic-navigation-section {
  box-shadow: 10px 10px 30px rgb(107 108 109 / 10%);
  border-top: 1px solid #e1e5ea;
  border-bottom: 1px solid #e1e5ea;
  display: flex;
  padding: 10px 16px;
  gap: 7px;
}

#magic-back-action {
  display: block;
  width: 20px;
  height: 22px;
}

.magic-flex-1 {
  flex: 1;
}

#magic-contact-widget {
  display: block;
  padding: 26px 16px 27px 16px;
  text-align: start;
}

#magic-contact-heading {
  height: 29px;
  display: flex;
  gap: 7px;
}

#magic-contact-icon {
  display: block;
  width: 29px;
}

#magic-contact-title {
  display: block;
  width: 120px;
}

#magic-contact-input {
  display: block;
  height: 29px;
  margin-top: 15px;
}

#magic-email-input {
  display: block;
  height: 29px;
  margin-top: 18px;
}

#magic-contact-extra {
  display: block;
  width: 156px;
  height: 29px;
  margin-top: 18px;
}

div.magic-seperator {
  display: block;
  height: 10px;
  background-color: #f8fafd;
}

#magic-coupons-widget {
  display: flex;
  justify-content: start;
  gap: 7px;
  padding: 20px 16px;
}

#magic-coupon-icon {
  display: block;
  width: 29px;
  height: 29px;
}

#magic-coupon-label {
  display: block;
  width: 120px;
  height: 29px;
}

#magic-cta-container {
  padding: 14px 16px;
  gap: 8px;
  display: flex;
  align-items: end;
  box-shadow: 0 -4px 8px rgb(107 108 109 / 13%);
  justify-content: space-between;
}

#magic-cta-button {
  display: block;
  width: 70%;
  height: 53px;
}

div.magic-cta-line {
  display: block;
  width: 66px;
  height: 20px;
}

@media screen and (max-width: 414px) {
  #magic-modal {
    width: 100%;
    height: 100%;
  }
}

.magic-scrollable {
  flex: 1;
  overflow-y: hidden;
  height: 100%;
}

#magic-merchant-name {
  display: block;
  font-size: 16px;
  font-weight: 600;
  line-height: 18px;
}
`;

const htmlSnippet = `
<div id="magic-modal">
  <div id="magic-merchant-heading">
    <div id="magic-merchant-name">${merchantName}</div>
    <div class="magic-shimmer" id="magic-close-action"></div>
  </div>
  <div id="magic-navigation-section">
    <div class="magic-shimmer" id="magic-back-action"></div>
    <div class="magic-shimmer magic-flex-1"></div>
  </div>
  <div class="magic-scrollable">
    <div id="magic-contact-widget">
      <div id="magic-contact-heading">
        <div class="magic-shimmer" id="magic-contact-icon"></div>
        <div class="magic-shimmer" id="magic-contact-title"></div>
      </div>
      <div id="magic-contact-input" class="magic-shimmer magic-w-100"></div>
      <div id="magic-email-input" class="magic-shimmer magic-w-100"></div>
      <div id="magic-contact-extra" class="magic-shimmer magic-w-100"></div>  
    </div>
    <div class="magic-seperator"></div>
    <div id="magic-coupons-widget">
      <div class="magic-shimmer" id="magic-coupon-icon"></div>
      <div class="magic-shimmer" id="magic-coupon-label"></div>
    </div>
    <div class="magic-seperator"></div>
    <div id="magic-coupons-widget">
      <div class="magic-shimmer" id="magic-coupon-icon"></div>
      <div class="magic-shimmer" id="magic-coupon-label"></div>
    </div>
    <div class="magic-seperator"></div>
  </div>
  <div id="magic-cta-container">
    <div style="text-align: start; display: flex; flex-direction: column; gap: 13px">
      <div class="magic-cta-line magic-shimmer"></div>
      <div class="magic-cta-line magic-shimmer"></div>
    </div>
    <div id="magic-cta-button" class="magic-shimmer"></div>
  </div>
</div>
`;

const styleNode = document.createElement('style');
styleNode.type = 'text/css';
styleNode.innerHTML = styleSnippet;

document.head.appendChild(styleNode);

window.MagicCheckout = {};

window.MagicCheckout.showModal = function() {
  const modal = document.createElement('div');
  modal.id = 'magic-wrapper';
  modal.innerHTML = htmlSnippet;

  document.body.appendChild(modal);
};

window.MagicCheckout.hideModal = function() {
  const modal = document.querySelector('#magic-wrapper');

  modal.style.opacity = '0';
  setTimeout(() => document.body.removeChild(modal), 600);
};