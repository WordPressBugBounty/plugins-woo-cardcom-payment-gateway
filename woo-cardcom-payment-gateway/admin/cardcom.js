! function($) {
    $(function() {
        
        if ($("#woocommerce_cardcom_operation").val() === '6') {
            $('#woocommerce_cardcom_OrderStatus').val('on-hold');
            $('#woocommerce_cardcom_OrderStatus').prop('disabled', true);
        }else{
            $('#woocommerce_cardcom_OrderStatus').prop('disabled', false);
        }

        $('#woocommerce_cardcom_operation').on('change', function() {
            if ($(this).val() === '6') {
                $('#woocommerce_cardcom_OrderStatus').val('on-hold').change();
                $('#woocommerce_cardcom_OrderStatus').prop('disabled', true);
            }
            else{
                $('#woocommerce_cardcom_OrderStatus').prop('disabled', false);
            }
        });
        

        var hiddenField = document.getElementById('woocommerce_cardcom_currency').value;
        document.getElementById('woocommerce_cardcom_currency_disabled').value = hiddenField;

        
    })
}(jQuery);