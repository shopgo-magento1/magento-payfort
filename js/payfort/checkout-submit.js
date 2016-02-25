function submitMerchantPage(merchantPageDataUrl) {
    
    //get iframe data
    jQuery.ajax({
        url: merchantPageDataUrl,
        type: 'post',
        //data: postData,
        success: function (data) {

            var respnse = jQuery.parseJSON(data);
            jQuery('#payfort_payment_form').html('');
            jQuery.each(respnse.params, function(k, v){
                jQuery('<input>').attr({
                    type: 'hidden',
                    id: k,
                    name: k,
                    value: v
                }).appendTo('#payfort_payment_form'); 
            });
            showMerchantPage(respnse.url);
        },
        error: function () {
            alert("Can't load payment page!");
        }
    });
}

function showMerchantPage(merchantPageUrl) {
    if(jQuery("#payfort_merchant_page").size()) {
        jQuery( "#payfort_merchant_page" ).remove();
    }
    jQuery("#review-buttons-container .btn-checkout").hide();
    jQuery("#review-please-wait").show();
    
    jQuery('<iframe  name="payfort_merchant_page" id="payfort_merchant_page"height="550px" frameborder="0" scrolling="no" onload="pfIframeLoaded(this)" style="display:none"></iframe>').appendTo('#pf_iframe_content');
    jQuery('.pf-iframe-spin').show();
    jQuery('.pf-iframe-close').hide();
    jQuery( "#payfort_merchant_page" ).attr("src", merchantPageUrl);
    jQuery( "#payfort_payment_form" ).attr("action", merchantPageUrl);
    jQuery( "#payfort_payment_form" ).attr("target","payfort_merchant_page");
    jQuery( "#payfort_payment_form" ).submit();
    jQuery( "#div-pf-iframe" ).show();
}

function pfClosePopup() {
    jQuery( "#div-pf-iframe" ).hide();
    jQuery( "#payfort_merchant_page" ).remove();
    window.location = jQuery( "#payfort_cancel_url" ).val();
}
function pfIframeLoaded(ele) {
    jQuery('.pf-iframe-spin').hide();
    jQuery('.pf-iframe-close').show();
    jQuery('#payfort_merchant_page').show();
}