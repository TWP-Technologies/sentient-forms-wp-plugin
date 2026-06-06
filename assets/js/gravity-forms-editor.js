/**
 * Sentient Forms Gravity Forms editor controls.
 */
(function($) {
    'use strict';

    function setFieldProperty(property, value) {
        if (typeof window.SetFieldProperty === 'function') {
            window.SetFieldProperty(property, value);
        }
    }

    function syncSentientFormsFieldSetting(field) {
        var $setting = $('#sentient_forms_field_setting');
        var enabled = !!(field && field.sentientFormsEnabled);

        $setting.find('input[type="checkbox"]').prop('checked', enabled);
    }

    $(document).on('change', '#sentient_forms_enabled', function() {
        setFieldProperty('sentientFormsEnabled', $(this).prop('checked'));
    });

    $(document).on('gform_load_field_settings', function(event, field) {
        syncSentientFormsFieldSetting(field);
    });
})(jQuery);
