'use strict';

(function($) {
  $(function() {
    woonp_type();
    woonp_forms();
  });

  // Variations are loaded via ajax
  $('#woocommerce-product-data').on('woocommerce_variations_loaded', function() {
    woonp_forms();
  });

  $(document).on('change', '.woonp_status_input, .woonp_status_select', function() {
    woonp_forms($(this).closest('.woonp_settings_form'));
  });

  $(document).on('change', '.woonp_type', function() {
    woonp_type();
  });

  $(document).on('change', 'select.woonp_type_single', function() {
    woonp_forms($(this).closest('.woonp_settings_form'));
  });

  // Global settings page
  function woonp_type() {
    if ($('.woonp_type').val() === 'select') {
      $('tr.woonp_show_if_type_input').css('display', 'none');
      $('tr.woonp_show_if_type_select').css('display', 'table-row');
    } else {
      $('tr.woonp_show_if_type_select').css('display', 'none');
      $('tr.woonp_show_if_type_input').css('display', 'table-row');
    }
  }

  // Product & variation settings, each form is handled on its own
  function woonp_forms($context) {
    $context = $context || $(document);

    $context.find('.woonp_settings_form').addBack('.woonp_settings_form').
        each(function() {
          var $form = $(this);
          var status = $form.find('.woonp_status_input:checked').val() ||
              $form.find('.woonp_status_select').val();
          var type = $form.find('select.woonp_type_single').val();

          if (status === 'overwrite') {
            $form.find('div.woonp_show_if_overwrite').show();
          } else {
            $form.find('div.woonp_show_if_overwrite').hide();
          }

          if (type === 'select') {
            $form.find('div.woonp_show_if_type_input').css('display', 'none');
            $form.find('div.woonp_show_if_type_select').
                css('display', 'flex');
          } else {
            $form.find('div.woonp_show_if_type_select').
                css('display', 'none');
            $form.find('div.woonp_show_if_type_input').css('display', 'flex');
          }
        });
  }
})(jQuery);
