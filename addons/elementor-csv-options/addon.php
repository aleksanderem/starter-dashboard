<?php
/**
 * Starter Dashboard Addon: Elementor CSV Options
 *
 * Adds ability to upload CSV files for Select, Radio, Checkbox fields in Elementor Pro Forms
 * CSV format: Label, Value, Selected(optional)
 *
 * This addon injects UI controls via JavaScript to avoid Elementor repeater conflicts.
 */

defined('ABSPATH') || exit;

class Starter_Addon_Elementor_CSV_Options {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX handlers
        add_action('wp_ajax_starter_csv_parse', [$this, 'ajax_parse_csv']);
        add_action('wp_ajax_starter_hubspot_field_options', [$this, 'ajax_get_hubspot_field_options']);

        // Enqueue editor scripts
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_scripts']);

        // Allow CSV uploads
        add_filter('upload_mimes', [$this, 'allow_csv_upload']);
    }

    /**
     * Allow CSV file uploads
     */
    public function allow_csv_upload($mimes) {
        $mimes['csv'] = 'text/csv';
        return $mimes;
    }

    /**
     * Enqueue editor scripts
     */
    public function enqueue_editor_scripts() {
        wp_enqueue_media();

        $nonce = wp_create_nonce('starter_csv_options');
        $hubspot_nonce = wp_create_nonce('starter_settings'); // HubSpot addon nonce
        $ajax_url = admin_url('admin-ajax.php');

        // Check if HubSpot addon is active and configured
        $hubspot_token = get_option('starter_hubspot_access_token', '');
        $hubspot_enabled = !empty($hubspot_token);

        $js = $this->get_editor_js($nonce, $ajax_url, $hubspot_enabled, $hubspot_nonce);
        wp_add_inline_script('elementor-editor', $js);
    }

    /**
     * Get editor JavaScript - injects CSV controls into field settings
     */
    private function get_editor_js($nonce, $ajax_url, $hubspot_enabled = false, $hubspot_nonce = '') {
        $hubspot_enabled_js = $hubspot_enabled ? 'true' : 'false';

        return <<<JS
(function($) {
    'use strict';

    var csvInitialized = false;
    var hubspotEnabled = {$hubspot_enabled_js};
    var hubspotFormsCache = null;
    var hubspotFieldsCache = {};

    function initCSVOptions() {
        if (csvInitialized) return;
        csvInitialized = true;

        console.log('[CSV Options] Initializing... HubSpot enabled:', hubspotEnabled);

        // Use MutationObserver to watch for field settings panels
        var observer = new MutationObserver(function(mutations) {
            // Look for field_options textarea in repeater items
            var optionsControls = document.querySelectorAll('.elementor-control-field_options');

            optionsControls.forEach(function(control) {
                if (control.dataset.csvInjected) return;

                // Check if parent is a select/radio/checkbox field
                var repeaterItem = $(control).closest('.elementor-repeater-row-controls');
                if (!repeaterItem.length) return;

                var fieldTypeSelect = repeaterItem.find('[data-setting="field_type"]');
                var fieldType = fieldTypeSelect.val();

                if (['select', 'radio', 'checkbox'].indexOf(fieldType) === -1) return;

                control.dataset.csvInjected = 'true';
                injectCSVControls($(control), repeaterItem);

                // Watch for field type changes
                fieldTypeSelect.off('change.csvOptions').on('change.csvOptions', function() {
                    var newType = $(this).val();
                    var csvContainer = repeaterItem.find('.csv-options-injected');

                    if (['select', 'radio', 'checkbox'].indexOf(newType) !== -1) {
                        csvContainer.show();
                    } else {
                        csvContainer.hide();
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Also handle button clicks via delegation
        $(document).on('click', '.csv-upload-btn', handleUploadClick);
        $(document).on('click', '.csv-remove-file', handleRemoveClick);
        $(document).on('click', '.csv-load-options-btn', handleLoadClick);
        $(document).on('change', '.csv-data-type-select', handleDataTypeChange);
        $(document).on('change', '.csv-file-type-select', handleFileTypeChange);
        $(document).on('input', '.csv-url-input', debounce(handleUrlInput, 500));

        // HubSpot handlers
        $(document).on('change', '.hubspot-form-select', handleHubSpotFormChange);
        $(document).on('click', '.hubspot-load-options-btn', handleHubSpotLoadClick);
    }

    function injectCSVControls(optionsControl, repeaterItem) {
        var html = buildCSVControlsHTML();
        optionsControl.after(html);
    }

    function buildCSVControlsHTML() {
        var html = '<div class="elementor-control csv-options-injected" style="padding: 0 20px 15px;">';

        // Data Type
        html += '<div class="elementor-control-field" style="margin-bottom: 10px;">';
        html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">Data Source</label>';
        html += '<div class="elementor-control-input-wrapper">';
        html += '<select class="csv-data-type-select" style="width:100%;">';
        html += '<option value="manual">Manual Entry</option>';
        html += '<option value="csv">CSV File</option>';
        if (hubspotEnabled) {
            html += '<option value="hubspot">HubSpot Field</option>';
        }
        html += '</select>';
        html += '</div></div>';

        // CSV Options Container (hidden by default)
        html += '<div class="csv-options-container" style="display:none;">';

        // File Type
        html += '<div class="elementor-control-field" style="margin-bottom: 10px;">';
        html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">File Type</label>';
        html += '<div class="elementor-control-input-wrapper">';
        html += '<select class="csv-file-type-select" style="width:100%;">';
        html += '<option value="upload">Upload File</option>';
        html += '<option value="url">File URL</option>';
        html += '</select>';
        html += '</div></div>';

        // Upload Section
        html += '<div class="csv-upload-section">';
        html += '<div class="elementor-control-field">';
        html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">Upload CSV File</label>';

        // File display (hidden initially)
        html += '<div class="csv-file-display" style="display:none;background:rgba(0,0,0,0.15);border-radius:4px;padding:10px;margin-bottom:8px;">';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;">';
        html += '<span class="csv-filename" style="font-size:12px;display:flex;align-items:center;gap:6px;"><i class="eicon-document-file"></i> <span class="csv-filename-text"></span></span>';
        html += '<button type="button" class="csv-remove-file" style="background:none;border:none;color:#ff6b6b;cursor:pointer;padding:4px;"><i class="eicon-trash-o"></i></button>';
        html += '</div></div>';

        // Upload button
        html += '<button type="button" class="elementor-button csv-upload-btn" style="width:100%;"><i class="eicon-upload"></i> Choose CSV File</button>';
        html += '</div></div>';

        // URL Section (hidden by default)
        html += '<div class="csv-url-section" style="display:none;">';
        html += '<div class="elementor-control-field">';
        html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">CSV File URL</label>';
        html += '<input type="text" class="csv-url-input" placeholder="https://example.com/options.csv" style="width:100%;padding:8px;border:1px solid rgba(255,255,255,0.1);border-radius:3px;background:rgba(0,0,0,0.15);color:#fff;">';
        html += '</div></div>';

        // Load Button
        html += '<button type="button" class="elementor-button elementor-button-success csv-load-options-btn" style="width:100%;margin-top:10px;"><i class="eicon-sync"></i> Load Options from CSV</button>';

        // Format info
        html += '<p style="font-size:11px;color:#999;font-style:italic;margin:10px 0 0;">CSV format: Label, Value, true/false(optional)</p>';

        html += '</div>'; // .csv-options-container

        // HubSpot Options Container (hidden by default)
        if (hubspotEnabled) {
            html += '<div class="hubspot-options-container" style="display:none;">';

            // HubSpot Form Select
            html += '<div class="elementor-control-field" style="margin-bottom: 10px;">';
            html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">HubSpot Form</label>';
            html += '<div class="elementor-control-input-wrapper">';
            html += '<select class="hubspot-form-select" style="width:100%;">';
            html += '<option value="">-- Select Form --</option>';
            html += '</select>';
            html += '<p class="hubspot-form-loading" style="display:none;font-size:11px;color:#999;margin:5px 0 0;"><i class="eicon-loading eicon-animation-spin"></i> Loading forms...</p>';
            html += '</div></div>';

            // HubSpot Field Select
            html += '<div class="elementor-control-field" style="margin-bottom: 10px;">';
            html += '<label class="elementor-control-title" style="display:block;margin-bottom:5px;">HubSpot Field</label>';
            html += '<div class="elementor-control-input-wrapper">';
            html += '<select class="hubspot-field-select" style="width:100%;" disabled>';
            html += '<option value="">-- Select Field --</option>';
            html += '</select>';
            html += '<p class="hubspot-field-loading" style="display:none;font-size:11px;color:#999;margin:5px 0 0;"><i class="eicon-loading eicon-animation-spin"></i> Loading fields...</p>';
            html += '<p class="hubspot-field-info" style="display:none;font-size:11px;color:#71bf44;margin:5px 0 0;"></p>';
            html += '</div></div>';

            // Load Button
            html += '<button type="button" class="elementor-button elementor-button-success hubspot-load-options-btn" style="width:100%;" disabled><i class="eicon-download-bold"></i> Load Options from HubSpot</button>';

            html += '</div>'; // .hubspot-options-container
        }

        // Hidden inputs for storing data
        html += '<input type="hidden" class="csv-stored-url" value="">';

        html += '</div>'; // .csv-options-injected

        return html;
    }

    function handleDataTypeChange(e) {
        var select = $(e.target);
        var container = select.closest('.csv-options-injected');
        var csvContainer = container.find('.csv-options-container');
        var hubspotContainer = container.find('.hubspot-options-container');
        var value = select.val();

        // Hide all
        csvContainer.hide();
        hubspotContainer.hide();

        if (value === 'csv') {
            csvContainer.slideDown(200);
        } else if (value === 'hubspot') {
            hubspotContainer.slideDown(200);
            // Load HubSpot forms if not cached
            loadHubSpotForms(container);
        }
    }

    function loadHubSpotForms(container) {
        var formSelect = container.find('.hubspot-form-select');
        var loadingText = container.find('.hubspot-form-loading');

        // If already have options (besides the placeholder), skip
        if (formSelect.find('option').length > 1) return;

        // Use cache if available
        if (hubspotFormsCache) {
            populateFormSelect(formSelect, hubspotFormsCache);
            return;
        }

        loadingText.show();
        formSelect.prop('disabled', true);

        $.ajax({
            url: '{$ajax_url}',
            type: 'GET',
            data: {
                action: 'starter_hubspot_get_forms',
                nonce: '{$hubspot_nonce}'
            },
            success: function(response) {
                loadingText.hide();
                formSelect.prop('disabled', false);

                if (response.success && response.data) {
                    hubspotFormsCache = response.data;
                    populateFormSelect(formSelect, response.data);
                } else {
                    formSelect.append('<option value="" disabled>Error loading forms</option>');
                }
            },
            error: function() {
                loadingText.hide();
                formSelect.prop('disabled', false);
                formSelect.append('<option value="" disabled>Error loading forms</option>');
            }
        });
    }

    function populateFormSelect(select, forms) {
        select.find('option:not(:first)').remove();
        forms.forEach(function(form) {
            select.append('<option value="' + form.id + '">' + escapeHtml(form.name) + '</option>');
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function handleHubSpotFormChange(e) {
        var formSelect = $(e.target);
        var container = formSelect.closest('.csv-options-injected');
        var fieldSelect = container.find('.hubspot-field-select');
        var loadBtn = container.find('.hubspot-load-options-btn');
        var loadingText = container.find('.hubspot-field-loading');
        var fieldInfo = container.find('.hubspot-field-info');
        var formId = formSelect.val();

        // Reset field select
        fieldSelect.find('option:not(:first)').remove();
        fieldSelect.prop('disabled', true);
        loadBtn.prop('disabled', true);
        fieldInfo.hide();

        if (!formId) return;

        // Check cache
        if (hubspotFieldsCache[formId]) {
            populateFieldSelect(fieldSelect, hubspotFieldsCache[formId]);
            fieldSelect.prop('disabled', false);
            return;
        }

        loadingText.show();

        $.ajax({
            url: '{$ajax_url}',
            type: 'GET',
            data: {
                action: 'starter_hubspot_get_form_fields',
                nonce: '{$hubspot_nonce}',
                form_guid: formId
            },
            success: function(response) {
                loadingText.hide();

                if (response.success && response.data) {
                    hubspotFieldsCache[formId] = response.data;
                    populateFieldSelect(fieldSelect, response.data);
                    fieldSelect.prop('disabled', false);
                } else {
                    fieldSelect.append('<option value="" disabled>Error loading fields</option>');
                }
            },
            error: function() {
                loadingText.hide();
                fieldSelect.append('<option value="" disabled>Error loading fields</option>');
            }
        });
    }

    function populateFieldSelect(select, fields) {
        select.find('option:not(:first)').remove();

        // Only show fields that have options (dropdown, radio, checkbox)
        var optionFields = fields.filter(function(f) {
            return f.options && f.options.length > 0;
        });

        if (optionFields.length === 0) {
            select.append('<option value="" disabled>No dropdown/select fields in this form</option>');
            return;
        }

        optionFields.forEach(function(field, idx) {
            var optionCount = field.options ? field.options.length : 0;
            var label = field.label + ' (' + optionCount + ' options)';
            select.append('<option value="' + idx + '" data-field-index="' + idx + '">' + escapeHtml(label) + '</option>');
        });

        // Store filtered fields for later use
        select.data('optionFields', optionFields);
    }

    function handleHubSpotLoadClick(e) {
        e.preventDefault();
        var btn = $(e.target).closest('.hubspot-load-options-btn');
        var container = btn.closest('.csv-options-injected');
        var repeaterItem = container.closest('.elementor-repeater-row-controls');
        var fieldSelect = container.find('.hubspot-field-select');
        var fieldInfo = container.find('.hubspot-field-info');

        var selectedIdx = fieldSelect.val();
        var optionFields = fieldSelect.data('optionFields');

        if (!selectedIdx || !optionFields) {
            alert('Please select a HubSpot field first.');
            return;
        }

        var field = optionFields[parseInt(selectedIdx)];
        if (!field || !field.options) {
            alert('Selected field has no options.');
            return;
        }

        // Format for Elementor: label|value
        var formatted = field.options.map(function(opt) {
            if (opt.value && opt.value !== opt.label) {
                return opt.label + '|' + opt.value;
            }
            return opt.label;
        }).join('\\n');

        // Update field_options textarea
        var textarea = repeaterItem.find('[data-setting="field_options"]');
        if (textarea.length) {
            textarea.val(formatted).trigger('input');

            // Show success
            fieldInfo.html('<i class="eicon-check"></i> Loaded ' + field.options.length + ' options from "' + escapeHtml(field.label) + '"').show();
            btn.html('<i class="eicon-check"></i> Options Loaded!');
            setTimeout(function() {
                btn.html('<i class="eicon-download-bold"></i> Load Options from HubSpot');
            }, 2000);
        }
    }

    // Enable/disable load button based on field selection
    $(document).on('change', '.hubspot-field-select', function() {
        var fieldSelect = $(this);
        var container = fieldSelect.closest('.csv-options-injected');
        var loadBtn = container.find('.hubspot-load-options-btn');
        var fieldInfo = container.find('.hubspot-field-info');

        fieldInfo.hide();
        loadBtn.prop('disabled', !fieldSelect.val());
    });

    function handleFileTypeChange(e) {
        var select = $(e.target);
        var container = select.closest('.csv-options-injected');

        if (select.val() === 'upload') {
            container.find('.csv-upload-section').show();
            container.find('.csv-url-section').hide();
        } else {
            container.find('.csv-upload-section').hide();
            container.find('.csv-url-section').show();
        }
    }

    function handleUploadClick(e) {
        e.preventDefault();
        var btn = $(e.target).closest('.csv-upload-btn');
        var container = btn.closest('.csv-options-injected');

        var mediaUploader = wp.media({
            title: 'Select CSV File',
            button: { text: 'Use this file' },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            console.log('[CSV Options] File selected:', attachment);

            container.find('.csv-stored-url').val(attachment.url);
            container.find('.csv-filename-text').text(attachment.filename || attachment.title || 'file.csv');
            container.find('.csv-file-display').show();
            btn.hide();
        });

        mediaUploader.open();
    }

    function handleRemoveClick(e) {
        e.preventDefault();
        var btn = $(e.target).closest('.csv-remove-file');
        var container = btn.closest('.csv-options-injected');

        container.find('.csv-stored-url').val('');
        container.find('.csv-filename-text').text('');
        container.find('.csv-file-display').hide();
        container.find('.csv-upload-btn').show();
    }

    function handleUrlInput(e) {
        var input = $(e.target);
        var container = input.closest('.csv-options-injected');
        container.find('.csv-stored-url').val(input.val());
    }

    function handleLoadClick(e) {
        e.preventDefault();
        var btn = $(e.target).closest('.csv-load-options-btn');
        var container = btn.closest('.csv-options-injected');
        var repeaterItem = container.closest('.elementor-repeater-row-controls');

        var csvUrl = container.find('.csv-stored-url').val();
        var fileType = container.find('.csv-file-type-select').val();

        if (fileType === 'url') {
            csvUrl = container.find('.csv-url-input').val();
        }

        if (!csvUrl) {
            alert('Please select or enter a CSV file first.');
            return;
        }

        console.log('[CSV Options] Loading from URL:', csvUrl);

        // Show loading
        btn.prop('disabled', true).html('<i class="eicon-loading eicon-animation-spin"></i> Loading...');

        $.ajax({
            url: '{$ajax_url}',
            type: 'POST',
            data: {
                action: 'starter_csv_parse',
                nonce: '{$nonce}',
                csv_url: csvUrl
            },
            success: function(response) {
                btn.prop('disabled', false).html('<i class="eicon-sync"></i> Load Options from CSV');

                if (response.success && response.data.options) {
                    console.log('[CSV Options] Loaded options:', response.data.options);

                    // Format for Elementor
                    var formatted = response.data.options.map(function(opt) {
                        var line = opt.label;
                        if (opt.value && opt.value !== opt.label) {
                            line += '|' + opt.value;
                        }
                        return line;
                    }).join('\\n');

                    // Update field_options textarea
                    var textarea = repeaterItem.find('[data-setting="field_options"]');
                    if (textarea.length) {
                        textarea.val(formatted).trigger('input');

                        // Show success
                        btn.html('<i class="eicon-check"></i> Loaded ' + response.data.options.length + ' options');
                        setTimeout(function() {
                            btn.html('<i class="eicon-sync"></i> Load Options from CSV');
                        }, 2000);
                    }
                } else {
                    alert('Error: ' + (response.data || 'Could not parse CSV'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="eicon-sync"></i> Load Options from CSV');
                alert('Error loading CSV: ' + error);
            }
        });
    }

    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

    // Initialize
    $(document).ready(function() {
        setTimeout(initCSVOptions, 500);
    });

    $(window).on('load', function() {
        setTimeout(initCSVOptions, 100);
    });

})(jQuery);
JS;
    }

    /**
     * AJAX: Parse CSV from URL
     */
    public function ajax_parse_csv() {
        check_ajax_referer('starter_csv_options', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied', 'starter-dashboard'));
        }

        $csv_url = isset($_POST['csv_url']) ? esc_url_raw($_POST['csv_url']) : '';

        if (empty($csv_url)) {
            wp_send_json_error(__('No CSV URL provided', 'starter-dashboard'));
        }

        // Fetch CSV
        $response = wp_remote_get($csv_url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $csv_content = wp_remote_retrieve_body($response);

        if (empty($csv_content)) {
            wp_send_json_error(__('CSV file is empty', 'starter-dashboard'));
        }

        $options = $this->parse_csv($csv_content);

        if (empty($options)) {
            wp_send_json_error(__('Could not parse CSV file', 'starter-dashboard'));
        }

        wp_send_json_success([
            'options' => $options,
            'count' => count($options),
        ]);
    }

    /**
     * Parse CSV content into options array
     */
    private function parse_csv($content) {
        $options = [];

        // Handle BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header row if it looks like one
            if (stripos($line, 'label') === 0 || stripos($line, 'name') === 0) {
                continue;
            }

            // Parse CSV line
            $parts = str_getcsv($line);

            if (count($parts) >= 1 && !empty(trim($parts[0]))) {
                $label = trim($parts[0]);
                $value = isset($parts[1]) && !empty(trim($parts[1])) ? trim($parts[1]) : $label;
                $selected = isset($parts[2]) && in_array(strtolower(trim($parts[2])), ['true', '1', 'yes', 'selected']);

                $options[] = [
                    'label' => $label,
                    'value' => $value,
                    'selected' => $selected,
                ];
            }
        }

        return $options;
    }
}

// Initialize
Starter_Addon_Elementor_CSV_Options::instance();
