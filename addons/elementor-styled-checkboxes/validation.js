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
    function showCheckboxError($field, message) {
        // Remove existing error if any
        removeCheckboxError($field);

        // Create error element
        var $error = $('<div>')
            .addClass('elementor-message elementor-message-danger elementor-help-inline')
            .attr('role', 'alert')
            .text(message);

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
    function validateRequiredCheckboxes($form) {
        var isValid = true;
        var $requiredCheckboxFields = $form.find('.elementor-field-type-checkbox[data-required-checkbox="true"]');

        $requiredCheckboxFields.each(function() {
            var $field = $(this);

            if (!isCheckboxFieldValid($field)) {
                showCheckboxError($field, starterCheckboxValidation.errorMessage);
                isValid = false;
            } else {
                removeCheckboxError($field);
            }
        });

        return isValid;
    }

    /**
     * Initialize validation for Elementor forms
     */
    function initValidation() {
        // Wait for Elementor to be ready
        $(document).on('elementor/frontend/init', function() {
            // Handle form submission
            elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope) {
                var $form = $scope.find('.elementor-form');

                if (!$form.length) {
                    return;
                }

                // Mark required checkbox fields with data attribute
                var formSettings = $form.data('settings');
                if (formSettings && formSettings.form_fields) {
                    formSettings.form_fields.forEach(function(field) {
                        if (field.field_type === 'checkbox' && field.field_required_checkbox === 'yes') {
                            var $field = $form.find('.elementor-field-type-checkbox[data-field-id="' + field.custom_id + '"]');
                            if (!$field.length) {
                                $field = $form.find('.elementor-field-type-checkbox').eq(field._id - 1);
                            }
                            $field.attr('data-required-checkbox', 'true');
                        }
                    });
                }

                // Hook into form validation
                $form.on('submit_success', function() {
                    // Clear errors on successful submission
                    $form.find('.elementor-field-type-checkbox').each(function() {
                        removeCheckboxError($(this));
                    });
                });

                // Validate before form submission
                $form.on('submit', function(e) {
                    if (!validateRequiredCheckboxes($form)) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // Clear error when user checks a checkbox in a required field
                $form.on('change', '.elementor-field-type-checkbox[data-required-checkbox="true"] input[type="checkbox"]', function() {
                    var $field = $(this).closest('.elementor-field-type-checkbox');
                    if (isCheckboxFieldValid($field)) {
                        removeCheckboxError($field);
                    }
                });
            });
        });
    }

    // Initialize when document is ready
    $(document).ready(initValidation);

})(jQuery);
