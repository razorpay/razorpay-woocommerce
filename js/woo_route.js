var $ = jQuery;

var max_fields      = 5; //maximum input boxes allowed
var wrapper         = $(".rzp_transfer_custom_field"); //Fields wrapper
var add_button      = $(".add_field_button"); //Add button ID

    var x = 1;
    $(add_button).click(function (e) {
        e.preventDefault();
        if (x < max_fields) {
            x++;
            $(wrapper).append('<p><input type="text" name="LA_number[]" placeholder="Linked Account Number"/><input type="number" name="LA_transfer_amount[]" class="LA_transfer_amount" placeholder="Amount"><label class="trf_settlement_label">Hold Settlement:</label><select name="LA_transfer_status[]"><optgroup label="On Hold"> <option value="1"> Yes</option><option value="0" selected> No</option></optgroup></select> <a href="#" class="remove_field">Remove</a></p>'); //add input box
        }
    });

    $(wrapper).on("click", ".remove_field", function (e) { //user click on remove text
        e.preventDefault();
        $(this).parent('p').remove();
        x--;
    });

    $(document).on('keyup', ".LA_transfer_amount",function () {

        var productPrice = $('#_regular_price').val();
        var trfAmount = 0;

        $('input[name^=LA_transfer_amount]').each(function () {
            var price = $(this).val();
            trfAmount += Number(price);
        });
        if (trfAmount > productPrice) {
            $('#transfer_err_msg').text('The sum of amount requested for transfer is greater than the product price');
        } else {
            $('#transfer_err_msg').text('');
        }
    });

$(document).on('keyup', "#trf_reversal_amount", function () {
    var defaultValue = parseInt($(this).attr('value'));
    var newValue = parseInt($(this).prop('value'));
    if (newValue > defaultValue) {
        $('#trf_reverse_text').text('Amount can\'t be greater than the total Reversible Amount.');
        $('#reverse_transfer_btn').attr("disabled", true);
    }else {
        $('#trf_reverse_text').text('');
        $("#reverse_transfer_btn").removeAttr("disabled");
    }
});

$(document).on('keyup', "#payment_trf_amount", function () {
    var defaultValue = parseInt($(this).attr('value'));
    var newValue = parseInt($(this).prop('value'));
    if (newValue > defaultValue) {
        $('#payment_trf_error').text('Transfer amount can not exceed payment amount.');
        $('#payment_transfer_btn').attr("disabled", true);
    }else {
        $('#payment_trf_error').text('');
        $("#payment_transfer_btn").removeAttr("disabled");
    }
});

$(function(){
    $('input[name="rzp_transfer_from"]').click(function(){
        var $routeTransferRadio = $(this);

        // if this was previously checked
        if ($routeTransferRadio.data('waschecked') == true)
        {
            $routeTransferRadio.prop('checked', false);
            $routeTransferRadio.data('waschecked', false);
        }
        else
            $routeTransferRadio.data('waschecked', true);

        // remove was checked from other radios
        $routeTransferRadio.siblings('input[name="rzp_transfer_from"]').data('waschecked', false);
    });
});


    $(".enable_hold_until").click(function() {
        $("#hold_until").attr("disabled", false);
    });

    $(".disable_hold_until").click(function() {
        $("#hold_until").attr("disabled", true);
    });
