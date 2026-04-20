/**
 * Sentient Forms Admin JavaScript
 */
(function($) {
    'use strict';

    /**
     * Initialize the admin functionality
     */
    function init() {
        // Initialize tabs
        initTabs();

        // Initialize settings page
        if ($('#sentient-forms-settings-form').length) {
            initSettingsPage();
        }

        // Initialize forms page
        if ($('.sentient-forms-forms-table').length) {
            initFormsPage();
        }

        // Initialize license page
        if ($('#sentient-forms-license-form').length) {
            initLicensePage();
        }

        // Initialize dashboard
        if ($('.sentient-forms-dashboard').length) {
            initDashboard();
        }
    }

    /**
     * Initialize tabs functionality
     */
    function initTabs() {
        $('.sentient-forms-tab-button').on('click', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            
            // Hide all tabs
            $('.sentient-forms-tab-content').removeClass('active');
            $('.sentient-forms-tab-button').removeClass('active');
            
            // Show the selected tab
            $('#' + target).addClass('active');
            $(this).addClass('active');
        });
    }

    /**
     * Initialize settings page functionality
     */
    function initSettingsPage() {
        // Test connection button
        $('#sentient-forms-test-connection').on('click', function() {
            var $button = $(this);
            var $result = $('#sentient-forms-connection-result');
            var apiKey = $('#sentient_forms_proxy_api_key').val();
            
            if (!apiKey) {
                $result.html('<span class="error">' + sentientFormsAdmin.i18n.apiKeyRequired + '</span>');
                return;
            }
            
            var originalText = $button.text();
            $button.text(sentientFormsAdmin.i18n.testingConnection);
            $button.prop('disabled', true);
            $result.html('');
            
            $.ajax({
                url: sentientFormsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sentient_forms_test_connection',
                    nonce: sentientFormsAdmin.ajax_nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="success">' + response.data.message + '</span>');
                    } else {
                        $result.html('<span class="error">' + sentientFormsAdmin.i18n.connectionFailed + response.data.message + '</span>');
                    }
                },
                error: function() {
                    $result.html('<span class="error">' + sentientFormsAdmin.i18n.connectionError + '</span>');
                },
                complete: function() {
                    $button.text(originalText);
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Reset settings button
        $('#sentient-forms-reset-settings').on('click', function() {
            if (confirm(sentientFormsAdmin.i18n.confirmReset)) {
                // Reset form fields to defaults
                $('#sentient_forms_default_llm').val('gemini-flash');
                $('#sentient_forms_auto_apply_actions').prop('checked', false);
                $('#sentient_forms_async_processing').prop('checked', true);
                $('#sentient_forms_debug_mode').prop('checked', false);
                $('#sentient_forms_api_url').val('https://api.sentientforms.com/v1');
                
                // Don't reset the API key
            }
        });
    }

    /**
     * Initialize forms page functionality
     */
    function initFormsPage() {
        // Modal functionality
        var $modal = $('#sentient-forms-form-modal');

        // Open modal when Configure is clicked
        $('.sentient-forms-configure-form').on('click', function(e) {
            e.preventDefault();

            var formId = $(this).data('form-id');
            var adapter = $(this).data('adapter');
            var form = null;

            // Find the form data
            for (var i = 0; i < sentientFormsAdmin.forms.length; i++) {
                if (sentientFormsAdmin.forms[i].id == formId && sentientFormsAdmin.forms[i].adapter == adapter) {
                    form = sentientFormsAdmin.forms[i];
                    break;
                }
            }

            if (!form) {
                return;
            }

            // Set form title and IDs
            $('#sentient-forms-modal-form-title').text(form.title);
            $('#sentient-forms-form-id').val(form.id);
            $('#sentient-forms-adapter-id').val(form.adapter);

            // Set form settings
            var settings = form.settings || {};
            $('#sentient-forms-form-enabled').prop('checked', settings.enabled || false);

            // Set action settings
            var actions = settings.actions || {};

            // Reset all action settings first
            $('.sentient-forms-actions-tab-content').each(function() {
                var actionId = $(this).data('action-id');
                $(this).find('input[type="checkbox"]').prop('checked', false);
                $(this).find('input[type="text"], input[type="number"]').val('');
                $(this).find('textarea').val('');
                $(this).find('select').each(function() {
                    $(this).val($(this).find('option:first').val());
                });
            });

            // Set action settings from form data
            for (var actionId in actions) {
                if (!actions.hasOwnProperty(actionId)) {
                    continue;
                }

                var actionSettings = actions[actionId];

                // Set enabled
                $('#sentient-forms-action-' + actionId + '-enabled').prop('checked', actionSettings.enabled || false);

                // Set other settings
                for (var settingId in actionSettings) {
                    if (!actionSettings.hasOwnProperty(settingId) || settingId === 'enabled') {
                        continue;
                    }

                    var $setting = $('#sentient-forms-action-' + actionId + '-' + settingId);

                    if ($setting.is('input[type="checkbox"]')) {
                        $setting.prop('checked', actionSettings[settingId] || false);
                    } else if ($setting.is('select')) {
                        $setting.val(actionSettings[settingId]);
                    } else if ($setting.is('textarea')) {
                        $setting.val(actionSettings[settingId]);
                    } else {
                        $setting.val(actionSettings[settingId]);
                    }
                }

                // Set hooks
                if (actionSettings.hooks && Array.isArray(actionSettings.hooks)) {
                    var $hooks = $('input[name="settings[actions][' + actionId + '][hooks][]"]');
                    $hooks.each(function() {
                        if (actionSettings.hooks.indexOf($(this).val()) !== -1) {
                            $(this).prop('checked', true);
                        }
                    });
                }
            }

            // Show the first action tab
            $('.sentient-forms-actions-tab-button:first').addClass('active');
            $('.sentient-forms-actions-tab-content:first').addClass('active');

            // Show the modal
            $modal.addClass('sentient-forms-modal-open');
        });

        // Close modal when X is clicked
        $('.sentient-forms-modal-close').on('click', function() {
            $modal.removeClass('sentient-forms-modal-open');
        });

        // Close modal when clicking on the backdrop
        $(document).on('click', '.sentient-forms-modal-backdrop', function() {
            $modal.removeClass('sentient-forms-modal-open');
        });
        
        // Tab functionality
        $('.sentient-forms-actions-tab-button').on('click', function() {
            var actionId = $(this).data('action-id');
            
            // Remove active class from all buttons and content
            $('.sentient-forms-actions-tab-button').removeClass('active');
            $('.sentient-forms-actions-tab-content').removeClass('active');
            
            // Add active class to clicked button and corresponding content
            $(this).addClass('active');
            $('.sentient-forms-actions-tab-content[data-action-id="' + actionId + '"]').addClass('active');
        });
        
        // Save form settings
        $('#sentient-forms-save-form-settings').on('click', function() {
            var $button = $(this);
            var $result = $('#sentient-forms-form-settings-result');
            
            $button.prop('disabled', true);
            $result.html('<span class="spinner is-active"></span> ' + sentientFormsAdmin.i18n.savingSettings);
            
            $.ajax({
                url: sentientFormsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sentient_forms_save_form_settings',
                    nonce: sentientFormsAdmin.ajax_nonce,
                    form_id: $('#sentient-forms-form-id').val(),
                    adapter_id: $('#sentient-forms-adapter-id').val(),
                    settings: getFormSettings()
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="success">' + response.data.message + '</span>');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        $result.html('<span class="error">' + sentientFormsAdmin.i18n.settingsFailed + response.data.message + '</span>');
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    $result.html('<span class="error">' + sentientFormsAdmin.i18n.settingsError + '</span>');
                    $button.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Get form settings as an object
     * 
     * @return {Object} The form settings
     */
    function getFormSettings() {
        var settings = {
            enabled: $('#sentient-forms-form-enabled').is(':checked'),
            actions: {}
        };
        
        // Get action settings
        $('.sentient-forms-actions-tab-content').each(function() {
            var actionId = $(this).data('action-id');
            var actionEnabled = $('#sentient-forms-action-' + actionId + '-enabled').is(':checked');
            
            if (!actionEnabled) {
                return;
            }
            
            settings.actions[actionId] = {
                enabled: true,
                hooks: []
            };
            
            // Get hooks
            $('input[name="settings[actions][' + actionId + '][hooks][]"]:checked').each(function() {
                settings.actions[actionId].hooks.push($(this).val());
            });
            
            // Get other settings
            $(this).find('input[type="text"], input[type="number"], select, textarea').each(function() {
                var name = $(this).attr('name');
                var matches = name.match(/settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/);
                
                if (matches && matches[1] === actionId) {
                    var settingId = matches[2];
                    settings.actions[actionId][settingId] = $(this).val();
                }
            });
            
            // Get checkboxes
            $(this).find('input[type="checkbox"]').each(function() {
                var name = $(this).attr('name');
                var matches = name.match(/settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/);
                
                if (matches && matches[1] === actionId && matches[2] !== 'hooks[]') {
                    var settingId = matches[2];
                    settings.actions[actionId][settingId] = $(this).is(':checked');
                }
            });
        });
        
        return settings;
    }

    /**
     * Initialize license page functionality
     */
    function initLicensePage() {
        // Deactivate license
        $('#sentient-forms-deactivate-license').on('click', function() {
            if (confirm(sentientFormsAdmin.i18n.confirmDeactivate)) {
                // Clear the license key field and submit the form
                $('#sentient_forms_license_key').val('');
                $('#sentient-forms-license-form').submit();
            }
        });
        
        // Check license status
        $('#sentient-forms-check-license').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text(sentientFormsAdmin.i18n.checking);
            $button.prop('disabled', true);
            
            // Reload the page to check the license status
            window.location.reload();
        });
    }

    /**
     * Initialize dashboard functionality
     */
    function initDashboard() {
        // Legacy PHP dashboard currently has no interactive local-first controls.
    }

    // Initialize when the DOM is ready
    $(document).ready(init);

})(jQuery);
