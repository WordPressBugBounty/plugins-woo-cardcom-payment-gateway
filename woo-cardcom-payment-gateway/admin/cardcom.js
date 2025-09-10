! function($) {
    $(function() {
        try {
            if ($("#woocommerce_cardcom_operation").val() === '6') {
                $('#woocommerce_cardcom_OrderStatus').val('on-hold');
                $('#woocommerce_cardcom_OrderStatus').prop('disabled', true);
            } else {
                $('#woocommerce_cardcom_OrderStatus').prop('disabled', false);
            }

            $('#woocommerce_cardcom_operation').on('change', function() {
                if ($(this).val() === '6') {
                    $('#woocommerce_cardcom_OrderStatus').val('on-hold').change();
                    $('#woocommerce_cardcom_OrderStatus').prop('disabled', true);
                } else {
                    $('#woocommerce_cardcom_OrderStatus').prop('disabled', false);
                }
            });

            // Safeguard against null elements
            var hiddenField = document.getElementById('woocommerce_cardcom_currency');
            var currencyDisabledField = document.getElementById('woocommerce_cardcom_currency_disabled');

            if (hiddenField && currencyDisabledField) {
                currencyDisabledField.value = hiddenField.value;
            } 
        } catch (error) {
            console.error("An error occurred in the Cardcom plugin script:", error);
        }
    });
}(jQuery);