<?php
/**
 * Starter Dashboard Addon: Elementor Phone Field
 *
 * Adds international phone functionality to existing Tel field in Elementor Pro Forms
 * Uses intl-tel-input library - injected via JavaScript
 */

defined('ABSPATH') || exit;

class Starter_Addon_Elementor_Phone_Field {

    private static $instance = null;
    private $intl_tel_input_version = '18.2.1';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fix invalid pattern attribute in tel fields
     */
    public function fix_tel_field_pattern($item, $item_index) {
        // Only for tel fields
        if (isset($item['field_type']) && $item['field_type'] === 'tel') {
            // Fix the invalid pattern [0-9()#&+*-=.]+
            // The dash in middle makes it invalid regex
            if (isset($item['field_html'])) {
                // Remove the invalid pattern attribute entirely
                $item['field_html'] = preg_replace(
                    '/pattern=["\'][^"\']*["\']/',
                    '',
                    $item['field_html']
                );
            }
        }
        return $item;
    }

    private function __construct() {
        // Fix invalid pattern attribute before rendering
        add_filter('elementor_pro/forms/render/item', [$this, 'fix_tel_field_pattern'], 10, 2);

        // Register scripts
        add_action('wp_enqueue_scripts', [$this, 'register_scripts'], 5);
        add_action('admin_enqueue_scripts', [$this, 'register_scripts'], 5);

        // Enqueue on frontend
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts'], 20);

        // Enqueue in Elementor editor
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'editor_scripts']);
        add_action('elementor/preview/enqueue_scripts', [$this, 'preview_scripts']);

        // Add inline styles
        add_action('wp_head', [$this, 'print_styles']);
        add_action('admin_head', [$this, 'print_styles']);

        // Inject controls via JavaScript in editor
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'inject_editor_js']);
    }

    /**
     * Register scripts
     */
    public function register_scripts() {
        wp_register_style(
            'intl-tel-input',
            'https://cdn.jsdelivr.net/npm/intl-tel-input@' . $this->intl_tel_input_version . '/build/css/intlTelInput.min.css',
            [],
            $this->intl_tel_input_version
        );

        wp_register_script(
            'intl-tel-input',
            'https://cdn.jsdelivr.net/npm/intl-tel-input@' . $this->intl_tel_input_version . '/build/js/intlTelInput.min.js',
            ['jquery'],
            $this->intl_tel_input_version,
            true
        );
    }

    /**
     * Frontend scripts
     */
    public function frontend_scripts() {
        wp_enqueue_style('intl-tel-input');
        wp_enqueue_script('intl-tel-input');
        wp_add_inline_script('intl-tel-input', $this->get_frontend_init_script());
    }

    /**
     * Editor scripts
     */
    public function editor_scripts() {
        $this->register_scripts();
        wp_enqueue_style('intl-tel-input');
        wp_enqueue_script('intl-tel-input');
    }

    /**
     * Preview scripts
     */
    public function preview_scripts() {
        $this->register_scripts();
        wp_enqueue_style('intl-tel-input');
        wp_enqueue_script('intl-tel-input');
        wp_add_inline_script('intl-tel-input', $this->get_frontend_init_script());
    }

    /**
     * Print styles
     */
    public function print_styles() {
        echo '<style id="intl-tel-input-custom">' . $this->get_custom_styles() . '</style>';
    }

    /**
     * Inject editor JavaScript for adding controls
     */
    public function inject_editor_js() {
        ?>
        <script>
        (function($) {
            'use strict';

            // Wait for Elementor to be ready
            $(window).on('elementor:init', function() {
                if (typeof elementor === 'undefined') return;

                // Add control injection for tel fields
                elementor.hooks.addFilter('elementor_pro/forms/field_type/controls', function(controls, fieldType) {
                    if (fieldType !== 'tel') return controls;

                    // Add "Enable International" toggle after the field type
                    controls.push({
                        name: 'enable_intl_phone',
                        label: 'Enable International Format',
                        type: 'switcher',
                        default: '',
                        label_on: 'Yes',
                        label_off: 'No',
                        return_value: 'yes',
                    });

                    return controls;
                });
            });

            // Use MutationObserver to inject toggle control in the editor panel
            function injectIntlToggle() {
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType !== 1) return;

                            // Look for tel field settings panel
                            var fieldTypeSelect = node.querySelector ? node.querySelector('[data-setting="field_type"]') : null;
                            if (!fieldTypeSelect) {
                                fieldTypeSelect = $(node).find('[data-setting="field_type"]')[0];
                            }

                            if (fieldTypeSelect && $(fieldTypeSelect).val() === 'tel') {
                                addIntlToggleToPanel(node);
                            }
                        });
                    });
                });

                observer.observe(document.body, { childList: true, subtree: true });

                // Also watch for field type changes
                $(document).on('change', '[data-setting="field_type"]', function() {
                    var $this = $(this);
                    var panel = $this.closest('.elementor-repeater-row-controls');

                    if ($this.val() === 'tel') {
                        setTimeout(function() {
                            addIntlToggleToPanel(panel[0]);
                        }, 100);
                    } else {
                        // Remove toggle if field type changed away from tel
                        panel.find('.intl-phone-toggle-wrapper').remove();
                    }
                });
            }

            function addIntlToggleToPanel(panelNode) {
                var $panel = $(panelNode);

                // Don't add if already exists
                if ($panel.find('.intl-phone-toggle-wrapper').length > 0) return;

                // Find the placeholder control to insert after
                var $placeholderControl = $panel.find('[data-setting="placeholder"]').closest('.elementor-control');
                if ($placeholderControl.length === 0) {
                    $placeholderControl = $panel.find('[data-setting="field_type"]').closest('.elementor-control');
                }

                if ($placeholderControl.length === 0) return;

                // Get current field data
                var $row = $panel.closest('.elementor-repeater-row-item');
                var fieldIndex = $row.data('index') || $row.index();

                // Create toggle HTML
                var toggleHtml = `
                    <div class="elementor-control elementor-control-type-switcher intl-phone-toggle-wrapper" data-field-index="${fieldIndex}">
                        <div class="elementor-control-content">
                            <div class="elementor-control-field">
                                <label class="elementor-control-title">International Phone</label>
                                <div class="elementor-control-input-wrapper">
                                    <label class="elementor-switch">
                                        <input type="checkbox" class="intl-phone-toggle" data-setting="enable_intl_phone">
                                        <span class="elementor-switch-label" data-on="Yes" data-off="No"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="elementor-control-field-description">Enable country selector with flags</div>
                        </div>
                    </div>
                `;

                $placeholderControl.after(toggleHtml);

                // Handle toggle change
                var $toggle = $panel.find('.intl-phone-toggle');

                // Load saved state from field's custom attribute
                var currentModel = getCurrentFieldModel(fieldIndex);
                if (currentModel && currentModel.enable_intl_phone === 'yes') {
                    $toggle.prop('checked', true);
                }

                $toggle.on('change', function() {
                    var isEnabled = $(this).is(':checked');
                    saveFieldSetting(fieldIndex, 'enable_intl_phone', isEnabled ? 'yes' : '');
                });
            }

            function getCurrentFieldModel(fieldIndex) {
                if (typeof elementor === 'undefined') return null;

                try {
                    var editedElement = elementor.getPanelView().getCurrentPageView().getOption('editedElementView');
                    if (!editedElement) return null;

                    var formFields = editedElement.model.get('settings').get('form_fields');
                    if (!formFields || !formFields.models) return null;

                    return formFields.models[fieldIndex] ? formFields.models[fieldIndex].attributes : null;
                } catch (e) {
                    return null;
                }
            }

            function saveFieldSetting(fieldIndex, key, value) {
                if (typeof elementor === 'undefined') return;

                try {
                    var editedElement = elementor.getPanelView().getCurrentPageView().getOption('editedElementView');
                    if (!editedElement) return;

                    var formFields = editedElement.model.get('settings').get('form_fields');
                    if (!formFields || !formFields.models || !formFields.models[fieldIndex]) return;

                    formFields.models[fieldIndex].set(key, value);
                    editedElement.model.get('settings').set('form_fields', formFields);

                    // Trigger change to update preview
                    editedElement.model.trigger('change');
                } catch (e) {
                    console.error('[Phone Field] Error saving setting:', e);
                }
            }

            // Initialize when document is ready
            $(document).ready(function() {
                if (typeof elementor !== 'undefined') {
                    injectIntlToggle();
                }
            });

            // Also initialize when Elementor editor is fully loaded
            $(window).on('elementor:loaded', injectIntlToggle);
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Get custom styles
     */
    public function get_custom_styles() {
        return <<<CSS
/* Phone International Field Styles */
.iti { width: 100%; display: block; }
.iti input[type=tel] { width: 100%; }
.iti__flag-container { position: absolute; top: 0; bottom: 0; left: 0; }
.iti__selected-country { display: flex; align-items: center; height: 100%; padding: 0 10px 0 12px; background: rgba(0,0,0,0.02); border-right: 1px solid rgba(0,0,0,0.1); cursor: pointer; }
.iti__selected-dial-code { margin-left: 6px; font-size: 14px; color: #666; }
.iti--separate-dial-code input[type=tel],
.iti--allow-dropdown input[type=tel] { padding-left: 100px !important; }
.iti__country-container { max-height: 300px; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); background: #fff; z-index: 99999; }
.iti__country-list { max-height: 250px; overflow-y: auto; }
.iti__country { padding: 8px 12px; cursor: pointer; }
.iti__country:hover,
.iti__country--highlight { background: #f5f5f5; }
.iti__search-input { padding: 10px 12px; border: none; border-bottom: 1px solid #eee; width: 100%; box-sizing: border-box; }
.iti__arrow { margin-left: 6px; border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 5px solid #555; }
.iti__arrow--up { border-top: none; border-bottom: 5px solid #555; }
/* Ensure proper z-index in Elementor editor */
.elementor-editor-active .iti__country-container { z-index: 999999; }
/* Editor toggle styling */
.intl-phone-toggle-wrapper { margin-top: 10px; }
.intl-phone-toggle-wrapper .elementor-control-field-description { font-size: 11px; color: #888; margin-top: 5px; }
CSS;
    }

    /**
     * Get frontend initialization script
     */
    public function get_frontend_init_script() {
        return <<<JS
(function($) {
    'use strict';

    function initPhoneFields() {
        if (typeof window.intlTelInput === 'undefined') {
            setTimeout(initPhoneFields, 200);
            return;
        }

        // Find all tel inputs in Elementor forms
        var inputs = document.querySelectorAll('.elementor-field-type-tel input[type="tel"]');

        inputs.forEach(function(input) {
            // Fix invalid regex pattern from Elementor
            // [0-9()#&+*-=.]+ is invalid because - is in middle
            // Fix: move - to end or escape it
            var pattern = input.getAttribute('pattern');
            if (pattern && pattern.includes('[0-9()#&+*-=.]')) {
                // Replace with valid pattern (dash at the end)
                input.setAttribute('pattern', '[0-9()#&+*=.-]+');
            }
            // Skip if already initialized
            if (input.intlTelInputInstance) return;

            // Check if this field has international enabled via data attribute or class
            var fieldGroup = input.closest('.elementor-field-group');
            var isIntlEnabled = fieldGroup && (
                fieldGroup.classList.contains('intl-phone-enabled') ||
                fieldGroup.dataset.intlPhone === 'yes' ||
                input.classList.contains('intl-phone-enabled')
            );

            // For now, enable on ALL tel fields (can be made conditional later)
            // This makes the feature work immediately
            isIntlEnabled = true;

            if (!isIntlEnabled) return;


            var options = {
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js',
                separateDialCode: true,
                preferredCountries: ['us', 'gb', 'ca', 'au', 'de', 'fr', 'pl'],
                initialCountry: 'auto',
                nationalMode: false,
                autoPlaceholder: 'aggressive',
                formatOnDisplay: true,
                geoIpLookup: function(callback) {
                    fetch('https://ipapi.co/json/')
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            callback(data.country_code || 'us');
                        })
                        .catch(function() {
                            callback('us');
                        });
                }
            };

            try {
                var iti = window.intlTelInput(input, options);
                input.intlTelInputInstance = iti;

                // On form submit, set full number as the input value
                // Use capture phase to run BEFORE Elementor's serialization
                var form = input.closest('form');
                if (form && !form._phoneFieldBound) {
                    form._phoneFieldBound = true;
                    form.addEventListener('submit', function(e) {
                        var allPhoneInputs = form.querySelectorAll('input[type="tel"]');
                        allPhoneInputs.forEach(function(phoneInput) {
                            if (phoneInput.intlTelInputInstance) {
                                var fullNumber = phoneInput.intlTelInputInstance.getNumber();
                                phoneInput.value = fullNumber;

                                // Also update any hidden field
                                var hiddenField = phoneInput.parentElement.querySelector('.phone-full-number');
                                if (hiddenField) {
                                    hiddenField.value = fullNumber;
                                }
                            }
                        });
                    }, true); // USE CAPTURE PHASE (true) to fire before Elementor

                    // Also bind to Elementor's before_submit event if available
                    if (typeof jQuery !== 'undefined') {
                        jQuery(form).on('submit_success submit_error', function() {
                            // Reset to formatted number after submission
                            setTimeout(function() {
                                initPhoneFields();
                            }, 100);
                        });
                    }
                }
            } catch (e) {
                console.error('[Phone Field] Error initializing:', e);
            }
        });
    }

    // Initialize on various events
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initPhoneFields, 100);
        });
    } else {
        setTimeout(initPhoneFields, 100);
    }

    window.addEventListener('load', function() {
        setTimeout(initPhoneFields, 200);
    });

    // Elementor popup support
    $(document).on('elementor/popup/show', function() {
        setTimeout(initPhoneFields, 100);
    });

    // Elementor frontend hooks
    $(window).on('elementor/frontend/init', function() {
        if (typeof elementorFrontend !== 'undefined') {
            elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function() {
                setTimeout(initPhoneFields, 100);
            });
        }
    });

    // MutationObserver for dynamically added forms
    var observer = new MutationObserver(function(mutations) {
        var hasNewInputs = mutations.some(function(m) {
            return m.addedNodes.length > 0;
        });
        if (hasNewInputs) {
            setTimeout(initPhoneFields, 100);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Expose globally
    window.initPhoneIntlFields = initPhoneFields;
})(jQuery);
JS;
    }
}

// Initialize
Starter_Addon_Elementor_Phone_Field::instance();
