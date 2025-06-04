jQuery(document).ready(function($) {
    $('.pmv-product-picker').each(function() {
        var $picker = $(this);
        var targetSelector = $picker.data('target');
        $picker.select2({
            placeholder: 'Search for a product or variant...',
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: PMVPicker.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'pmv_product_search',
                        q: params.term,
                        nonce: PMVPicker.nonce
                    };
                },
                processResults: function(data) {
                    return data;
                },
                cache: true
            },
            width: 'resolve'
        });

        // When a product is picked, set the ID field
        $picker.on('select2:select', function(e) {
            var id = e.params.data.id;
            if (targetSelector) {
                $(targetSelector).val(id);
            }
        });

        // When cleared, clear the ID field
        $picker.on('select2:clear', function(e) {
            if (targetSelector) {
                $(targetSelector).val('');
            }
        });
    });
});
