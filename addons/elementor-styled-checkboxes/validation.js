/**
 * Client-side validation for required checkbox fields
 */
(function($) {
    'use strict';

    // Debug flag - set to true to see console logs
    var DEBUG = true;

    function log(message, data) {
        if (DEBUG && window.console) {
            console.log('[Checkbox Validation] ' + message, data || '');
        }
    }

    /**
     * Check if a checkbox field has at least one checked option
     */
    function isCheckboxFieldValid($field) {
        var $checkboxes = $field.find('input[type="checkbox"]');
        var hasChecked = false;

        $checkboxes.each(function() {
            if ($(this).prop('checked')) {
                hasChecked = true;
                return false; // break loop
            }
        });

        log('Field validation check', {field: $field.attr('class'), hasChecked: hasChecked, checkboxCount: $checkboxes.length});
        return hasChecked;
    }

    /**
     * Show error message for checkbox field
     */
    function showCheckboxError($field, message, fieldLabel) {
        log('Showing error for field', {label: fieldLabel});

        // Remove existing error if any
        removeCheckboxError($field);

        // Make message more specific with field label
        var errorMessage = message;
        if (fieldLabel) {
            errorMessage = fieldLabel + ': ' + message;
        }

        // Create error element
        var $error = $('<div>')
            .addClass('elementor-message elementor-message-danger elementor-help-inline')
            .attr('role', 'alert')
            .text(errorMessage);

        // Insert error after the checkbox subgroup
        var $subgroup = $field.find('.elementor-field-subgroup');
        if ($subgroup.length) {
            $subgroup.after($error);
        } else {
            $field.append($error);
        }

        // Add error class to field
        $field.addClass('elementor-error');
        log('Error class added to field');
    }

    /**
     * Remove error message from checkbox field
     */
    function removeCheckboxError($field) {
        $field.find('.elementor-message-danger').remove();
        $field.removeClass('elementor-error');
    }

    /**
     * Validate all required checkbox fields in a form
     */
    function validateRequiredCheckboxes($form, formSettings) {
        log('Starting validation', {hasSettings: !!formSettings});
        var hasError = false;

        if (!formSettings || !formSettings.form_fields) {
            log('No form settings or form_fields found');
            return hasError;
        }

        log('Form has ' + formSettings.form_fields.length + ' fields');

        formSettings.form_fields.forEach(function(field, index) {
            if (field.field_type === 'checkbox' && field.field_required_checkbox === 'yes') {
                log('Found required checkbox field at index ' + index, field);

                var $field = $form.find('.elementor-field-type-checkbox').eq(index);

                if ($field.length && !isCheckboxFieldValid($field)) {
                    var fieldLabel = field.field_label || '';
                    showCheckboxError($field, starterCheckboxValidation.errorMessage, fieldLabel);
                    hasError = true;
                } else if ($field.length) {
                    removeCheckboxError($field);
                } else {
                    log('Field not found in DOM at index ' + index);
                }
            }
        });

        log('Validation complete', {hasError: hasError});
        return hasError;
    }

    /**
     * Initialize validation for a single form
     */
    function initFormValidation($form) {
        log('Initializing form validation', {formId: $form.attr('id')});

        var formSettings = $form.data('settings');

        if (!formSettings) {
            log('No form settings found on form');
            return;
        }

        log('Form settings loaded', {fieldCount: formSettings.form_fields ? formSettings.form_fields.length : 0});

        // Validate on submit
        $form.on('submit', function(e) {
            log('Form submit event triggered');
            var hasError = validateRequiredCheckboxes($form, formSettings);

            if (hasError) {
                log('Validation failed, preventing submit');
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
            log('Validation passed');
        });

        // Clear error when user checks a checkbox
        $form.on('change', 'input[type="checkbox"]', function() {
            log('Checkbox changed');
            var $field = $(this).closest('.elementor-field-type-checkbox');
            var fieldIndex = $form.find('.elementor-field-type-checkbox').index($field);

            if (formSettings && formSettings.form_fields && formSettings.form_fields[fieldIndex]) {
                var field = formSettings.form_fields[fieldIndex];
                if (field.field_type === 'checkbox' && field.field_required_checkbox === 'yes') {
                    if (isCheckboxFieldValid($field)) {
                        log('Clearing error after checkbox change');
                        removeCheckboxError($field);
                    }
                }
            }
        });

        // Clear all errors on successful submission
        $form.on('submit_success', function() {
            log('Form submission successful, clearing errors');
            $form.find('.elementor-field-type-checkbox').each(function() {
                removeCheckboxError($(this));
            });
        });

        log('Form validation initialized successfully');
    }

    /**
     * Initialize validation for all forms
     */
    function initValidation() {
        log('Starting checkbox validation initialization');

        // Check if Elementor frontend is available
        if (typeof elementorFrontend === 'undefined') {
            log('ERROR: elementorFrontend not found, cannot initialize');
            return;
        }

        log('elementorFrontend found, setting up hooks');

        // Hook into each form widget
        elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope) {
            log('Form widget ready, initializing validation');
            var $form = $scope.find('.elementor-form');

            if (!$form.length) {
                log('No form found in scope');
                return;
            }

            initFormValidation($form);
        });

        log('Validation hooks registered');
    }

    // Wait for Elementor to be ready
    $(window).on('elementor/frontend/init', function() {
        log('elementor/frontend/init event fired');
        initValidation();
    });

    // Fallback: if Elementor is already initialized
    $(document).ready(function() {
        if (typeof elementorFrontend !== 'undefined' && elementorFrontend.isEditMode && !elementorFrontend.isEditMode()) {
            log('Document ready, Elementor already initialized');
            setTimeout(initValidation, 100); // Small delay to ensure everything is ready
        }
    });

    log('Checkbox validation script loaded');

})(jQuery);
