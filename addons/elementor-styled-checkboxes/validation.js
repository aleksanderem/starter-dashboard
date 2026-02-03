/**
 * Client-side validation for required checkbox fields
 * Simplified approach: uses data-required-checkbox attribute added by PHP
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
    function validateRequiredCheckboxes($form) {
        var hasError = false;
        var $requiredFields = $form.find('.elementor-field-type-checkbox[data-required-checkbox="true"]');

        $requiredFields.each(function() {
            var $field = $(this);

            if (!isCheckboxFieldValid($field)) {
                var fieldLabel = $field.data('field-label') || '';
                showCheckboxError($field, starterCheckboxValidation.errorMessage, fieldLabel);
                hasError = true;
            } else {
                removeCheckboxError($field);
            }
        });

        return hasError;
    }

    /**
     * Add required attributes to checkbox fields
     */
    function markRequiredCheckboxes($form) {
        var formId = $form.closest('[data-element_type="form.default"]').data('id');
        var requiredFields = window.starterRequiredCheckboxes && window.starterRequiredCheckboxes['form-' + formId];

        if (!requiredFields) {
            return;
        }

        requiredFields.forEach(function(field) {
            var $field = $form.find('.elementor-field-group-' + field.id);
            if ($field.length) {
                $field.attr('data-required-checkbox', 'true');
                $field.attr('data-field-label', field.label);
            }
        });
    }

    /**
     * Initialize validation for a single form
     */
    function initFormValidation($form) {
        // Mark required checkboxes with data attributes
        markRequiredCheckboxes($form);

        // Validate on submit
        $form.on('submit', function(e) {
            var hasError = validateRequiredCheckboxes($form);

            if (hasError) {
                e.preventDefault();
                e.stopImmediatePropagation();
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

        // Clear all errors on successful submission
        $form.on('submit_success', function() {
            $form.find('.elementor-field-type-checkbox').each(function() {
                removeCheckboxError($(this));
            });
        });
    }

    /**
     * Initialize validation for all forms
     */
    function initValidation() {
        // Check if Elementor frontend is available
        if (typeof elementorFrontend === 'undefined') {
            return;
        }

        // Hook into each form widget
        elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function($scope) {
            var $form = $scope.find('.elementor-form');

            if (!$form.length) {
                return;
            }

            initFormValidation($form);
        });
    }

    // Wait for Elementor to be ready
    $(window).on('elementor/frontend/init', function() {
        initValidation();
    });

    // Fallback: if Elementor is already initialized
    $(document).ready(function() {
        if (typeof elementorFrontend !== 'undefined' && elementorFrontend.isEditMode && !elementorFrontend.isEditMode()) {
            setTimeout(initValidation, 100);
        }
    });

})(jQuery);
