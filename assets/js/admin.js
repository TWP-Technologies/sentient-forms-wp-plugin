/**
 * Sentient Forms legacy admin JavaScript.
 */
(function($) {
    'use strict';

    function getAdminConfig() {
        return window.sentientFormsAdmin || {};
    }

    function getI18n(key, fallback) {
        var config = getAdminConfig();
        var i18n = config.i18n || {};

        return i18n[key] || fallback;
    }

    function getAjaxNonce() {
        var config = getAdminConfig();

        return config.nonce || config.ajaxNonce || config.ajax_nonce || '';
    }

    function getFormsForModal() {
        if (Array.isArray(window.sentientFormsFormsData)) {
            return window.sentientFormsFormsData;
        }

        var config = getAdminConfig();
        if (Array.isArray(config.forms)) {
            return config.forms;
        }

        return [];
    }

    function setResultMessage($result, className, message) {
        $result.empty();
        $('<span />').addClass(className).text(message).appendTo($result);
    }

    function setResultSpinner($result, message) {
        $result.empty();
        $('<span />').addClass('spinner is-active').appendTo($result);
        $result.append(document.createTextNode(' ' + message));
    }

    function init() {
        initTabs();

        if ($('#sentient-forms-settings-form').length) {
            initSettingsPage();
        }

        if ($('.sentient-forms-forms-table').length) {
            initFormsPage();
        }

        if ($('#sentient-forms-license-form').length) {
            initLicensePage();
        }
    }

    function initTabs() {
        $('.sentient-forms-tab-button').on('click', function(e) {
            e.preventDefault();

            var target = $(this).data('target');

            $('.sentient-forms-tab-content').removeClass('active');
            $('.sentient-forms-tab-button').removeClass('active');
            $('#' + target).addClass('active');
            $(this).addClass('active');
        });
    }

    function initSettingsPage() {
        $('#sentient-forms-test-connection').on('click', function() {
            var config = getAdminConfig();
            var nonce = getAjaxNonce();
            var $button = $(this);
            var $result = $('#sentient-forms-connection-result');
            var apiKey = $('#sentient_forms_proxy_api_key').val();

            if (!apiKey) {
                setResultMessage($result, 'error', getI18n('apiKeyRequired', 'API key is required to test connection.'));
                return;
            }

            if (!config.ajaxUrl || !nonce) {
                setResultMessage($result, 'error', getI18n('adminDataMissing', 'Sentient Forms admin data could not be loaded.'));
                return;
            }

            var originalText = $button.text();
            $button.text(getI18n('testingConnection', 'Testing connection...'));
            $button.prop('disabled', true);
            $result.empty();

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sentient_forms_test_connection',
                    nonce: nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        setResultMessage($result, 'success', response.data.message);
                    } else {
                        setResultMessage(
                            $result,
                            'error',
                            getI18n('connectionFailed', 'Connection failed.') + ' ' + (response.data.message || '')
                        );
                    }
                },
                error: function() {
                    setResultMessage($result, 'error', getI18n('connectionError', 'An error occurred during the connection test.'));
                },
                complete: function() {
                    $button.text(originalText);
                    $button.prop('disabled', false);
                }
            });
        });

        $('#sentient-forms-reset-settings').on('click', function() {
            if (window.confirm(getI18n('confirmReset', 'Reset settings to defaults?'))) {
                $('#sentient_forms_default_llm').val('gemini-flash');
                $('#sentient_forms_auto_apply_actions').prop('checked', false);
                $('#sentient_forms_async_processing').prop('checked', true);
                $('#sentient_forms_debug_mode').prop('checked', false);
                $('#sentient_forms_api_url').val('https://api.sentientforms.com/v1');
            }
        });
    }

    function initFormsPage() {
        var $modal = $('#sentient-forms-form-modal');
        var formsForModal = getFormsForModal();

        $('.sentient-forms-configure-form').on('click', function(e) {
            e.preventDefault();

            var formId = $(this).data('form-id');
            var adapter = $(this).data('adapter');
            var form = null;

            for (var i = 0; i < formsForModal.length; i++) {
                if (String(formsForModal[i].id) === String(formId) && String(formsForModal[i].adapter) === String(adapter)) {
                    form = formsForModal[i];
                    break;
                }
            }

            if (!form) {
                window.alert(getI18n('formDataMissing', 'Could not find form data for configuration.'));
                return;
            }

            $('#sentient-forms-modal-form-title').text(form.title || '');
            $('#sentient-forms-form-id').val(form.id);
            $('#sentient-forms-adapter-id').val(form.adapter);

            var formSettings = $('#sentient-forms-form-settings-form')[0];
            if (formSettings) {
                formSettings.reset();
            }

            $('.sentient-forms-actions-tab-content input[type="checkbox"]').prop('checked', false);

            var settings = form.settings || {};
            $('#sentient-forms-form-enabled').prop('checked', !!settings.enabled);
            applyActionSettings(settings.actions || {});

            $('.sentient-forms-actions-tab-button:first').addClass('active').siblings().removeClass('active');
            $('.sentient-forms-actions-tab-content-wrapper .sentient-forms-actions-tab-content:first')
                .addClass('active')
                .siblings()
                .removeClass('active');

            $modal.addClass('sentient-forms-modal-open');
        });

        $(document).on('click', '.sentient-forms-modal-close, .sentient-forms-modal-close-button, .sentient-forms-modal-backdrop', function(e) {
            if ($(this).hasClass('sentient-forms-modal-backdrop') && !$(e.target).is('.sentient-forms-modal-backdrop')) {
                return;
            }

            $modal.removeClass('sentient-forms-modal-open');
        });

        $('.sentient-forms-actions-tab-button').on('click', function() {
            var actionId = $(this).data('action-id');

            $(this).addClass('active').siblings().removeClass('active');
            $('.sentient-forms-actions-tab-content-wrapper .sentient-forms-actions-tab-content[data-action-id="' + actionId + '"]')
                .addClass('active')
                .siblings()
                .removeClass('active');
        });

        $('#sentient-forms-save-form-settings').on('click', function() {
            var config = getAdminConfig();
            var nonce = getAjaxNonce();
            var $button = $(this);
            var $result = $('#sentient-forms-form-settings-result');

            if (!config.ajaxUrl || !nonce) {
                setResultMessage($result, 'error', getI18n('adminDataMissing', 'Sentient Forms admin data could not be loaded.'));
                return;
            }

            $button.prop('disabled', true);
            setResultSpinner($result, getI18n('savingSettings', 'Saving settings...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sentient_forms_save_form_settings',
                    nonce: nonce,
                    form_id: $('#sentient-forms-form-id').val(),
                    adapter_id: $('#sentient-forms-adapter-id').val(),
                    settings: getFormSettingsFromModal()
                },
                success: function(response) {
                    if (response.success) {
                        setResultMessage($result, 'success', response.data.message);
                        updateCachedFormSettings(formsForModal, response.data.settings);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        setResultMessage(
                            $result,
                            'error',
                            getI18n('settingsFailed', 'Failed to save settings.') + ' ' + (response.data.message || '')
                        );
                        $button.prop('disabled', false);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    setResultMessage(
                        $result,
                        'error',
                        getI18n('settingsError', 'An error occurred while saving settings.') + ' ' + (errorThrown || '')
                    );
                    $button.prop('disabled', false);
                }
            });
        });
    }

    function applyActionSettings(actions) {
        $('.sentient-forms-actions-tab-content').each(function() {
            var $actionTab = $(this);

            $actionTab.find('input[type="text"], input[type="number"]').each(function() {
                $(this).val($(this).attr('value') || '');
            });
            $actionTab.find('textarea').each(function() {
                $(this).val($(this).text() || '');
            });
            $actionTab.find('select').each(function() {
                var defaultValue = $(this).find('option[selected]').val();
                if (typeof defaultValue !== 'undefined') {
                    $(this).val(defaultValue);
                } else {
                    $(this).prop('selectedIndex', 0);
                }
            });
        });

        Object.keys(actions).forEach(function(actionId) {
            var actionSettings = actions[actionId] || {};
            var $actionTab = $('.sentient-forms-actions-tab-content[data-action-id="' + actionId + '"]');

            $('#sentient-forms-action-' + actionId + '-enabled').prop('checked', !!actionSettings.enabled);

            Object.keys(actionSettings).forEach(function(settingKey) {
                if (settingKey === 'enabled' || settingKey === 'hooks') {
                    return;
                }

                var $field = $actionTab.find('[name="settings[actions][' + actionId + '][' + settingKey + ']"]');

                if ($field.is('input[type="checkbox"]')) {
                    $field.prop('checked', !!actionSettings[settingKey]);
                } else {
                    $field.val(actionSettings[settingKey]);
                }
            });

            var hooks = Array.isArray(actionSettings.hooks) ? actionSettings.hooks : [];
            $actionTab.find('input[name="settings[actions][' + actionId + '][hooks][]"]').each(function() {
                $(this).prop('checked', hooks.indexOf($(this).val()) !== -1);
            });
        });
    }

    function updateCachedFormSettings(formsForModal, settings) {
        var formId = $('#sentient-forms-form-id').val();
        var adapterId = $('#sentient-forms-adapter-id').val();

        for (var i = 0; i < formsForModal.length; i++) {
            if (String(formsForModal[i].id) === String(formId) && String(formsForModal[i].adapter) === String(adapterId)) {
                formsForModal[i].settings = settings;
                return;
            }
        }
    }

    function getFormSettingsFromModal() {
        var settings = {
            enabled: $('#sentient-forms-form-enabled').is(':checked'),
            actions: {}
        };

        $('.sentient-forms-actions-tab-content').each(function() {
            var actionId = $(this).data('action-id');
            var actionEnabled = $('#sentient-forms-action-' + actionId + '-enabled').is(':checked');

            settings.actions[actionId] = {
                enabled: actionEnabled,
                hooks: []
            };

            $(this).find('input[name="settings[actions][' + actionId + '][hooks][]"]:checked').each(function() {
                settings.actions[actionId].hooks.push($(this).val());
            });

            $(this).find('input[type="text"], input[type="number"], select, textarea').each(function() {
                var name = $(this).attr('name');
                var matches = name && name.match(/settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/);

                if (matches && matches[1] === String(actionId) && matches[2] !== 'enabled') {
                    settings.actions[actionId][matches[2]] = $(this).val();
                }
            });

            $(this).find('input[type="checkbox"]').each(function() {
                var name = $(this).attr('name');
                var matches = name && name.match(/settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/);

                if (matches && matches[1] === String(actionId) && matches[2] !== 'enabled' && matches[2] !== 'hooks[]') {
                    settings.actions[actionId][matches[2]] = $(this).is(':checked');
                }
            });
        });

        return settings;
    }

    function initLicensePage() {
        $('#sentient-forms-deactivate-license').on('click', function() {
            if (window.confirm(getI18n('confirmDeactivate', 'Deactivate this site license?'))) {
                $('#sentient_forms_license_key').val('');
                $('#sentient-forms-license-form').submit();
            }
        });

        $('#sentient-forms-check-license').on('click', function() {
            var $button = $(this);

            $button.text(getI18n('checking', 'Checking...'));
            $button.prop('disabled', true);
            window.location.reload();
        });
    }

    $(document).ready(init);
})(jQuery);
