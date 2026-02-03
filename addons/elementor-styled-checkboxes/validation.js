/**
 * Client-side validation for required checkbox fields
 */
(function($) {
    'use strict';

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

        return hasChecked;
    }

    /**
     * Show error message for checkbox field
     */
    function showCheckboxError($field, message, fieldLabel) {
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
        var hasError = false;

        if (!formSettings || !formSettings.form_fields) {
            return hasError;
        }

        formSettings.form_fields.forEach(function(field, index) {
            if (field.field_type === 'checkbox' && field.field_required_checkbox === 'yes') {
                var $field = $form.find('.elementor-field-type-checkbox').eq(index);

                if ($field.length && !isCheckboxFieldValid($field)) {
                    // Get field label
                    var fieldLabel = field.field_label || '';

                    showCheckboxError($field, starterCheckboxValidation.errorMessage, fieldLabel);
                    hasError = true;
                } else if ($field.length) {
                    removeCheckboxError($field);
                }
            }
        });

        return hasError;
    }

    /**
     * Initialize validation for Elementor forms
     */
    function initValidation() {
        // Wait for Elementor to be ready
        $(window).on('elementor/frontend/init', function() {
            // Hook into each form widget
            elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope) {
                var $form = $scope.find('.elementor-form');

                if (!$form.length) {
                    return;
                }

                var formSettings = $form.data('settings');

                // Hook into Elementor's validation system
                $form.on('form/validate', function(event, field) {
                    // Validate all checkbox fields
                    var hasError = validateRequiredCheckboxes($form, formSettings);

                    if (hasError) {
                        // Prevent form submission by adding an error
                        event.preventDefault();
                    }
                });

                // Also validate on submit button click (backup)
                $form.on('submit', function(e) {
                    var hasError = validateRequiredCheckboxes($form, formSettings);

                    if (hasError) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }
                });

                // Clear error when user checks a checkbox
                $form.on('change', 'input[type="checkbox"]', function() {
                    var $field = $(this).closest('.elementor-field-type-checkbox');
                    var fieldIndex = $form.find('.elementor-field-type-checkbox').index($field);

                    if (formSettings && formSettings.form_fields && formSettings.form_fields[fieldIndex]) {
                        var field = formSettings.form_fields[fieldIndex];
                        if (field.field_type === 'checkbox' && field.field_required_checkbox === 'yes') {
                            if (isCheckboxFieldValid($field)) {
                                removeCheckboxError($field);
                            }
                        }
                    }
                });

                // Clear all errors on successful submission
                $form.on('submit_success', function() {
                    $form.find('.elementor-field-type-checkbox').each(function() {
                        removeCheckboxError($(this));
                    });
                });
            });
        });
    }

    // Initialize when document is ready
    $(document).ready(initValidation);

})(jQuery);
