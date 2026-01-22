/**
 * BusPatrol Dashboard Settings Scripts
 * Handles menu visibility settings and role permissions UI
 *
 * @package BusPatrol_Custom_Dashboard
 * @version 3.2.0
 */

(function($) {
    'use strict';

    /**
     * Settings Controller
     */
    var BusPatrolSettings = {
        /**
         * Stored hidden menus per role
         */
        hiddenMenus: {},

        /**
         * Initialize
         */
        init: function() {
            this.loadHiddenMenusData();
            this.initMenuTable();
            this.initMenuOrder();
            this.initSaveButtons();
            this.initCapabilitiesAccordion();
            this.initRoleCards();
            this.initRoleEditor();
            this.initCPTTab();
            this.initAdditionalElementsTab();
            this.initVisualSettingsTab();
            this.initAddonsTab();
            this.initImportExportTab();
        },

        /**
         * Load hidden menus data from hidden input
         */
        loadHiddenMenusData: function() {
            var dataEl = document.getElementById('bp-hidden-menus-data');
            if (dataEl && dataEl.value) {
                try {
                    this.hiddenMenus = JSON.parse(dataEl.value);
                } catch (e) {
                    this.hiddenMenus = {};
                }
            }
        },

        /**
         * Initialize menu table interactions
         */
        initMenuTable: function() {
            var self = this;

            // Initialize Select2 on role selects
            if ($.fn.select2) {
                $('.bp-menu-hidden-roles').select2({
                    placeholder: 'Select roles to hide from...',
                    allowClear: true,
                    width: '100%',
                    dropdownAutoWidth: false,
                    closeOnSelect: false,
                    templateResult: function(data) {
                        if (!data.id) return data.text;
                        return $('<span><input type="checkbox" style="margin-right:8px;"' +
                            (data.selected ? ' checked' : '') + '/>' + data.text + '</span>');
                    }
                });
            }

            // Handle "Hide for all" checkbox
            $(document).on('change', '.bp-menu-hidden-all', function() {
                var slug = $(this).data('slug');
                var isHidden = $(this).is(':checked');
                var row = $(this).closest('tr');
                var rolesSelect = row.find('.bp-menu-hidden-roles');

                if (isHidden) {
                    // Select all non-admin roles
                    var allValues = rolesSelect.find('option').map(function() {
                        return $(this).val();
                    }).get();
                    rolesSelect.val(allValues).trigger('change');
                    row.addClass('bp-menu-hidden');
                } else {
                    rolesSelect.val([]).trigger('change');
                    row.removeClass('bp-menu-hidden');
                }
            });

            // Handle role selection change
            $(document).on('change', '.bp-menu-hidden-roles', function() {
                var row = $(this).closest('tr');
                var allCheckbox = row.find('.bp-menu-hidden-all');
                var totalOptions = $(this).find('option').length;
                var selectedOptions = ($(this).val() || []).length;

                // Update "Hide for all" checkbox state
                allCheckbox.prop('checked', selectedOptions === totalOptions && totalOptions > 0);
                row.toggleClass('bp-menu-hidden', selectedOptions > 0);
            });
        },

        /**
         * Initialize menu order sortable
         */
        initMenuOrder: function() {
            var self = this;
            var orderList = document.getElementById('bp-menu-order-list');

            if (!orderList) {
                return;
            }

            // Initialize Sortable
            if (typeof Sortable !== 'undefined') {
                new Sortable(orderList, {
                    animation: 150,
                    handle: '.bp-menu-order-item__handle',
                    ghostClass: 'bp-menu-order-item--ghost',
                    chosenClass: 'bp-menu-order-item--chosen',
                    onEnd: function() {
                        // Enable save button after reordering
                        $('#bp-save-menu-order').prop('disabled', false);
                    }
                });
            }

            // Save menu order
            $('#bp-save-menu-order').on('click', function() {
                var btn = $(this);
                var order = [];

                $('#bp-menu-order-list .bp-menu-order-item').each(function() {
                    order.push($(this).data('slug'));
                });

                btn.prop('disabled', true).text(
                    window.starterSettings.strings.saving || 'Saving...'
                );

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_admin_menu_order',
                        nonce: window.starterSettings.nonce,
                        menu_order: order
                    },
                    success: function(response) {
                        btn.text(
                            window.starterSettings.strings.saved || 'Saved!'
                        );

                        if (response.success) {
                            self.showToast(
                                response.data.message || 'Menu order saved',
                                'success'
                            );
                        } else {
                            btn.prop('disabled', false);
                            self.showToast(
                                response.data.message || 'Error saving menu order',
                                'error'
                            );
                        }

                        setTimeout(function() {
                            btn.text('Save Order');
                        }, 2000);
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Order');
                        self.showToast('Error saving menu order', 'error');
                    }
                });
            });

            // Reset menu order
            $('#bp-reset-menu-order').on('click', function() {
                if (!confirm('Reset menu order to default?')) {
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true);

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_reset_admin_menu_order',
                        nonce: window.starterSettings.nonce
                    },
                    success: function(response) {
                        btn.prop('disabled', false);

                        if (response.success) {
                            self.showToast('Menu order reset. Refreshing...', 'success');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            self.showToast(
                                response.data.message || 'Error resetting menu order',
                                'error'
                            );
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false);
                        self.showToast('Error resetting menu order', 'error');
                    }
                });
            });
        },

        /**
         * Initialize save buttons
         */
        initSaveButtons: function() {
            var self = this;

            // Individual item save
            $(document).on('click', '.bp-save-menu-item', function() {
                var slug = $(this).data('slug');
                var row = $(this).closest('tr');
                self.saveMenuItem(slug, row, $(this));
            });

            // Save all button
            $('#bp-save-all-menu-settings').on('click', function() {
                self.saveAllMenuSettings($(this));
            });
        },

        /**
         * Save individual menu item
         */
        saveMenuItem: function(slug, row, button) {
            var self = this;
            var selectedRoles = row.find('.bp-menu-hidden-roles').val() || [];
            var parentSlug = row.data('parent');

            // For submenus, include parent slug separated by ||
            var menuKey = parentSlug ? (parentSlug + '||' + slug) : slug;

            button.prop('disabled', true).text(
                window.starterSettings.strings.saving || 'Saving...'
            );

            // Update local data
            selectedRoles.forEach(function(role) {
                if (!self.hiddenMenus[role]) {
                    self.hiddenMenus[role] = [];
                }
                if (self.hiddenMenus[role].indexOf(menuKey) === -1) {
                    self.hiddenMenus[role].push(menuKey);
                }
            });

            // Remove from roles that are not selected
            Object.keys(self.hiddenMenus).forEach(function(role) {
                if (selectedRoles.indexOf(role) === -1) {
                    var index = self.hiddenMenus[role].indexOf(menuKey);
                    if (index !== -1) {
                        self.hiddenMenus[role].splice(index, 1);
                    }
                }
            });

            // Save via AJAX
            $.ajax({
                url: window.starterSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_menu_settings',
                    nonce: window.starterSettings.nonce,
                    hidden_menus: self.hiddenMenus
                },
                success: function(response) {
                    button.prop('disabled', false).text(
                        window.starterSettings.strings.saved || 'Saved!'
                    );

                    if (response.success) {
                        self.showToast(
                            window.starterSettings.strings.saved || 'Settings saved',
                            'success'
                        );
                        self.updateHiddenInput();
                    } else {
                        self.showToast(
                            response.data.message || window.starterSettings.strings.error,
                            'error'
                        );
                    }

                    setTimeout(function() {
                        button.text('Save');
                    }, 1500);
                },
                error: function() {
                    button.prop('disabled', false).text('Save');
                    self.showToast(
                        window.starterSettings.strings.error || 'An error occurred',
                        'error'
                    );
                }
            });
        },

        /**
         * Save all menu settings at once
         */
        saveAllMenuSettings: function(button) {
            var self = this;

            // Collect all menu keys that are visible in the table
            var visibleMenuKeys = [];
            $('.bp-menu-hidden-roles').each(function() {
                var $row = $(this).closest('tr');
                var slug = $(this).data('slug');
                var parentSlug = $row.data('parent');
                var menuKey = parentSlug ? (parentSlug + '||' + slug) : slug;
                visibleMenuKeys.push(menuKey);
            });

            // First, remove all visible menu keys from hiddenMenus (we'll re-add selected ones)
            Object.keys(self.hiddenMenus).forEach(function(role) {
                if (Array.isArray(self.hiddenMenus[role])) {
                    self.hiddenMenus[role] = self.hiddenMenus[role].filter(function(key) {
                        return visibleMenuKeys.indexOf(key) === -1;
                    });
                }
            });

            // Now add the selected roles for each visible menu item
            $('.bp-menu-hidden-roles').each(function() {
                var $row = $(this).closest('tr');
                var slug = $(this).data('slug');
                var parentSlug = $row.data('parent');
                var selectedRoles = $(this).val() || [];

                // For submenus, include parent slug separated by ||
                var menuKey = parentSlug ? (parentSlug + '||' + slug) : slug;

                selectedRoles.forEach(function(role) {
                    if (!self.hiddenMenus[role]) {
                        self.hiddenMenus[role] = [];
                    }
                    if (self.hiddenMenus[role].indexOf(menuKey) === -1) {
                        self.hiddenMenus[role].push(menuKey);
                    }
                });
            });

            // Clean up empty role arrays
            Object.keys(self.hiddenMenus).forEach(function(role) {
                if (self.hiddenMenus[role].length === 0) {
                    delete self.hiddenMenus[role];
                }
            });

            button.prop('disabled', true).text(
                window.starterSettings.strings.saving || 'Saving...'
            );

            $.ajax({
                url: window.starterSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_menu_settings',
                    nonce: window.starterSettings.nonce,
                    hidden_menus: self.hiddenMenus
                },
                success: function(response) {
                    button.prop('disabled', false).text('Save All Menu Settings');

                    if (response.success) {
                        self.showToast(
                            window.starterSettings.strings.saved || 'All settings saved',
                            'success'
                        );
                        self.updateHiddenInput();
                    } else {
                        self.showToast(
                            response.data.message || window.starterSettings.strings.error,
                            'error'
                        );
                    }
                },
                error: function() {
                    button.prop('disabled', false).text('Save All Menu Settings');
                    self.showToast(
                        window.starterSettings.strings.error || 'An error occurred',
                        'error'
                    );
                }
            });
        },

        /**
         * Update hidden input with current data
         */
        updateHiddenInput: function() {
            var dataEl = document.getElementById('bp-hidden-menus-data');
            if (dataEl) {
                dataEl.value = JSON.stringify(this.hiddenMenus);
            }
        },

        /**
         * Initialize capabilities accordion
         */
        initCapabilitiesAccordion: function() {
            $(document).on('click', '.bp-settings__caps-header', function() {
                var item = $(this).closest('.bp-settings__caps-item');
                item.toggleClass('expanded');
            });
        },

        /**
         * Initialize role cards for dashboard access
         */
        initRoleCards: function() {
            $(document).on('change', '.bp-settings__role-card input[type="checkbox"]', function() {
                var card = $(this).closest('.bp-settings__role-card');
                card.toggleClass('bp-settings__role-card--active', $(this).is(':checked'));
            });
        },

        /**
         * Initialize Role Editor functionality
         */
        initRoleEditor: function() {
            var self = this;

            // Toggle role editor sections
            $(document).on('click', '.bp-role-editor__header', function() {
                var editor = $(this).closest('.bp-role-editor');
                var content = editor.find('.bp-role-editor__content');
                var toggle = $(this).find('.bp-role-toggle');

                content.slideToggle(200);
                toggle.toggleClass('rotated');
                editor.toggleClass('expanded');
            });

            // Capability checkbox change - update background color
            $(document).on('change', '.bp-cap-checkbox', function() {
                var label = $(this).closest('label');
                label.css('background', $(this).is(':checked') ? '#e8f5e9' : '#f5f5f5');
            });

            // Add custom capability
            $(document).on('click', '.bp-add-cap-btn', function() {
                var editor = $(this).closest('.bp-role-editor');
                var input = editor.find('.bp-add-cap-input');
                var capName = input.val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');

                if (!capName) {
                    self.showToast('Please enter a capability name', 'error');
                    return;
                }

                // Check if capability already exists in this editor
                if (editor.find('.bp-cap-checkbox[data-cap="' + capName + '"]').length) {
                    self.showToast('This capability already exists', 'error');
                    return;
                }

                // Add new capability checkbox to "Other" group or first group
                var otherGroup = editor.find('.bp-cap-group:last .bp-cap-group > div:last');
                if (!otherGroup.length) {
                    otherGroup = editor.find('.bp-cap-group:last > div:last');
                }

                var newLabel = $('<label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#e8f5e9;border-radius:4px;font-size:12px;cursor:pointer;transition:background 0.2s;">' +
                    '<input type="checkbox" class="bp-cap-checkbox" data-cap="' + capName + '" checked style="margin:0;">' +
                    '<span style="font-family:monospace;">' + capName + '</span>' +
                    '</label>');

                otherGroup.append(newLabel);
                input.val('');

                self.showToast('Capability added - save to apply', 'success');
            });

            // Save role capabilities
            $(document).on('click', '.bp-save-role-btn', function() {
                var btn = $(this);
                var role = btn.data('role');
                var editor = btn.closest('.bp-role-editor');

                // Collect all checked capabilities
                var capabilities = [];
                editor.find('.bp-cap-checkbox:checked').each(function() {
                    capabilities.push($(this).data('cap'));
                });

                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_role_caps',
                        nonce: window.starterSettings.nonce,
                        role: role,
                        capabilities: capabilities
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Save Changes');

                        if (response.success) {
                            self.showToast(response.data.message, 'success');
                            // Update cap count in header
                            editor.find('.bp-role-cap-count').text(response.data.cap_count + ' caps');
                        } else {
                            self.showToast(response.data.message || 'Error saving capabilities', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Changes');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });

            // Create new role
            $('#bp-create-role-btn').on('click', function() {
                var btn = $(this);
                var roleSlug = $('#bp-new-role-slug').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
                var roleName = $('#bp-new-role-name').val().trim();
                var cloneFrom = $('#bp-new-role-clone').val();

                if (!roleSlug || !roleName) {
                    self.showToast('Please enter both role ID and display name', 'error');
                    return;
                }

                btn.prop('disabled', true).text('Creating...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_create_role',
                        nonce: window.starterSettings.nonce,
                        role_slug: roleSlug,
                        role_name: roleName,
                        clone_from: cloneFrom
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Create Role');

                        if (response.success) {
                            self.showToast(response.data.message, 'success');
                            // Reload page to show new role
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            self.showToast(response.data.message || 'Error creating role', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Create Role');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });

            // Delete role
            $(document).on('click', '.bp-delete-role-btn', function() {
                var btn = $(this);
                var role = btn.data('role');
                var editor = btn.closest('.bp-role-editor');
                var roleName = editor.find('.bp-role-editor__header strong').text();

                if (!confirm('Are you sure you want to delete the role "' + roleName + '"? This cannot be undone.')) {
                    return;
                }

                btn.prop('disabled', true).text('Deleting...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_delete_role',
                        nonce: window.starterSettings.nonce,
                        role: role
                    },
                    success: function(response) {
                        if (response.success) {
                            self.showToast(response.data.message, 'success');
                            editor.slideUp(300, function() {
                                $(this).remove();
                            });
                        } else {
                            btn.prop('disabled', false).text('Delete Role');
                            self.showToast(response.data.message || 'Error deleting role', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Delete Role');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });
        },

        /**
         * Initialize CPT Tab functionality
         */
        initCPTTab: function() {
            var self = this;

            // Toggle CPT capabilities row
            $(document).on('click', '.bp-show-cpt-caps', function() {
                var cpt = $(this).data('cpt');
                var capsRow = $('tr.bp-cpt-caps-row[data-cpt="' + cpt + '"]');
                capsRow.toggle();
                $(this).text(capsRow.is(':visible') ? 'Hide' : 'Caps');
            });

            // Toggle CPT visibility on dashboard
            $(document).on('change', '.bp-cpt-visible-toggle', function() {
                var toggle = $(this);
                var cpt = toggle.data('cpt');
                var visible = toggle.is(':checked');
                var row = toggle.closest('tr');

                toggle.prop('disabled', true);

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_cpt_visibility',
                        nonce: window.starterSettings.nonce,
                        cpt: cpt,
                        visible: visible ? 1 : 0
                    },
                    success: function(response) {
                        toggle.prop('disabled', false);

                        if (response.success) {
                            row.toggleClass('bp-cpt-visible', visible);
                            self.showToast(response.data.message, 'success');

                            // Update visible count in stats
                            var countEl = $('.bp-settings__panel div[style*="#2ABADE"] div:first-child');
                            if (countEl.length && response.data.visible_count !== undefined) {
                                countEl.text(response.data.visible_count);
                            }
                        } else {
                            // Revert toggle state
                            toggle.prop('checked', !visible);
                            self.showToast(response.data.message || 'Error saving settings', 'error');
                        }
                    },
                    error: function() {
                        toggle.prop('disabled', false);
                        toggle.prop('checked', !visible);
                        self.showToast('An error occurred', 'error');
                    }
                });
            });
        },

        /**
         * Initialize Additional Elements Tab
         */
        initAdditionalElementsTab: function() {
            var self = this;

            // Save additional elements users
            $('#bp-save-additional-users').on('click', function() {
                var btn = $(this);
                var userIds = [];

                $('.bp-additional-user-checkbox:checked').each(function() {
                    userIds.push($(this).val());
                });

                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_additional_users',
                        nonce: window.starterSettings.nonce,
                        user_ids: userIds
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Save User Access');

                        if (response.success) {
                            self.showToast(response.data.message || 'User access saved', 'success');
                        } else {
                            self.showToast(response.data.message || 'Error saving settings', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save User Access');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });
        },

        /**
         * Initialize Visual Settings Tab
         */
        initVisualSettingsTab: function() {
            var self = this;

            // Load Elementor defaults from hidden field
            var elementorDefaults = {};
            var defaultsEl = document.getElementById('bp-elementor-defaults');
            if (defaultsEl && defaultsEl.value) {
                try {
                    elementorDefaults = JSON.parse(defaultsEl.value);
                } catch (e) {}
            }

            // Sync color inputs with text fields
            $('#bp-primary-color').on('input', function() {
                $('#bp-primary-color-text').val($(this).val());
                self.updatePreview();
            });
            $('#bp-primary-color-text').on('input', function() {
                var val = $(this).val();
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    $('#bp-primary-color').val(val);
                }
                self.updatePreview();
            });

            $('#bp-secondary-color').on('input', function() {
                $('#bp-secondary-color-text').val($(this).val());
                self.updatePreview();
            });
            $('#bp-secondary-color-text').on('input', function() {
                var val = $(this).val();
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    $('#bp-secondary-color').val(val);
                }
                self.updatePreview();
            });

            $('#bp-accent-color').on('input', function() {
                $('#bp-accent-color-text').val($(this).val());
                self.updatePreview();
            });
            $('#bp-accent-color-text').on('input', function() {
                var val = $(this).val();
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    $('#bp-accent-color').val(val);
                }
                self.updatePreview();
            });

            // Update preview on name/logo change
            $('#bp-hub-name').on('input', function() {
                self.updatePreview();
            });
            $('#bp-logo-url').on('input', function() {
                self.updatePreview();
                self.updateLogoPreview();
            });

            // Media library for logo
            $('#bp-select-logo').on('click', function(e) {
                e.preventDefault();

                if (typeof wp !== 'undefined' && wp.media) {
                    var mediaFrame = wp.media({
                        title: 'Select Logo',
                        button: { text: 'Use this logo' },
                        multiple: false,
                        library: { type: 'image' }
                    });

                    mediaFrame.on('select', function() {
                        var attachment = mediaFrame.state().get('selection').first().toJSON();
                        $('#bp-logo-url').val(attachment.url);
                        self.updatePreview();
                        self.updateLogoPreview();
                    });

                    mediaFrame.open();
                } else {
                    alert('Media library not available');
                }
            });

            // Reset to Elementor defaults
            $('#bp-reset-to-elementor').on('click', function() {
                if (elementorDefaults.primary_color) {
                    $('#bp-primary-color, #bp-primary-color-text').val(elementorDefaults.primary_color);
                }
                if (elementorDefaults.secondary_color) {
                    $('#bp-secondary-color, #bp-secondary-color-text').val(elementorDefaults.secondary_color);
                }
                if (elementorDefaults.accent_color) {
                    $('#bp-accent-color, #bp-accent-color-text').val(elementorDefaults.accent_color);
                }
                if (elementorDefaults.logo_url) {
                    $('#bp-logo-url').val(elementorDefaults.logo_url);
                    self.updateLogoPreview();
                }
                $('#bp-hub-name').val('BusPatrol Hub');
                self.updatePreview();
                self.showToast('Reset to Elementor defaults', 'success');
            });

            // Save visual settings users
            $('#bp-save-visual-users').on('click', function() {
                var btn = $(this);
                var userIds = [];

                $('.bp-visual-user-checkbox:checked').each(function() {
                    userIds.push($(this).val());
                });

                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_visual_users',
                        nonce: window.starterSettings.nonce,
                        user_ids: userIds
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Save Access Settings');

                        if (response.success) {
                            self.showToast(response.data.message || 'Access saved', 'success');
                        } else {
                            self.showToast(response.data.message || 'Error', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Access Settings');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });

            // Save visual settings
            $('#bp-save-visual-settings').on('click', function() {
                var btn = $(this);

                var data = {
                    action: 'starter_save_visual_settings',
                    nonce: window.starterSettings.nonce,
                    hub_name: $('#bp-hub-name').val(),
                    logo_url: $('#bp-logo-url').val(),
                    primary_color: $('#bp-primary-color').val(),
                    secondary_color: $('#bp-secondary-color').val(),
                    accent_color: $('#bp-accent-color').val(),
                    show_stats: $('#bp-show-stats').is(':checked') ? 1 : 0
                };

                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        btn.prop('disabled', false).text('Save Visual Settings');

                        if (response.success) {
                            self.showToast(response.data.message || 'Settings saved', 'success');
                        } else {
                            self.showToast(response.data.message || 'Error', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Visual Settings');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });
        },

        /**
         * Update header preview in Visual Settings
         */
        updatePreview: function() {
            var primaryColor = $('#bp-primary-color').val();
            var secondaryColor = $('#bp-secondary-color').val();
            var accentColor = $('#bp-accent-color').val();
            var hubName = $('#bp-hub-name').val() || 'BusPatrol Hub';
            var logoUrl = $('#bp-logo-url').val();

            $('#bp-header-preview').css('background', primaryColor);
            $('#bp-preview-name').text(hubName);
            $('#bp-preview-logo').attr('src', logoUrl);

            // Update button colors in preview
            $('#bp-header-preview span:contains("Button")').css('background', secondaryColor);
            $('#bp-header-preview span:contains("Accent")').css('background', accentColor);
        },

        /**
         * Update logo preview box
         */
        updateLogoPreview: function() {
            var logoUrl = $('#bp-logo-url').val();
            var $preview = $('#bp-logo-preview');

            if (logoUrl) {
                $preview.html('<img src="' + logoUrl + '" alt="Logo preview" style="max-width:100%;max-height:100%;object-fit:contain;" />');
            } else {
                $preview.html('<span style="color:#fff;font-size:11px;text-align:center;">No logo</span>');
            }
        },

        /**
         * Initialize Addons Tab
         */
        initAddonsTab: function() {
            var self = this;
            var currentAddonId = null;

            // Toggle addon activation
            $(document).on('change', '.bp-addon-toggle', function() {
                var toggle = $(this);
                var addonId = toggle.data('addon');
                var activate = toggle.is(':checked');
                var card = toggle.closest('.bp-addon-card');
                var settingsBtn = card.find('.bp-addon-settings-btn');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_toggle_addon',
                        nonce: window.starterSettings.nonce,
                        addon_id: addonId,
                        activate: activate ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            self.showToast(response.data.message, 'success');

                            if (response.data.active) {
                                card.addClass('bp-addon-card--active');
                                settingsBtn.prop('disabled', false);
                            } else {
                                card.removeClass('bp-addon-card--active');
                                settingsBtn.prop('disabled', true);
                            }
                        } else {
                            self.showToast(response.data.message || 'Error', 'error');
                            toggle.prop('checked', !activate);
                        }
                    },
                    error: function() {
                        self.showToast('An error occurred', 'error');
                        toggle.prop('checked', !activate);
                    }
                });
            });

            // Open addon settings modal
            $(document).on('click', '.bp-addon-settings-btn', function() {
                var btn = $(this);
                var addonId = btn.data('addon');
                currentAddonId = addonId;

                var modal = $('#bp-addon-settings-modal');
                var content = $('#bp-addon-settings-content');

                content.html('<div style="text-align:center;padding:40px;"><span class="spinner is-active" style="float:none;"></span></div>');
                modal.fadeIn(200);

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_get_addon_settings',
                        nonce: window.starterSettings.nonce,
                        addon_id: addonId
                    },
                    success: function(response) {
                        if (response.success) {
                            content.html(response.data.html);

                            // Initialize media library for image fields
                            content.find('.bp-select-image').on('click', function(e) {
                                e.preventDefault();
                                var button = $(this);
                                var field = button.siblings('input[type="url"]');
                                var preview = button.closest('.bp-addon-settings__field').find('.bp-image-preview');

                                if (typeof wp !== 'undefined' && wp.media) {
                                    var frame = wp.media({
                                        title: 'Select Image',
                                        button: { text: 'Use this image' },
                                        multiple: false,
                                        library: { type: 'image' }
                                    });

                                    frame.on('select', function() {
                                        var attachment = frame.state().get('selection').first().toJSON();
                                        field.val(attachment.url);
                                        if (preview.length) {
                                            preview.html('<img src="' + attachment.url + '" style="max-width:300px;height:auto;border:1px solid #ddd;border-radius:4px;">');
                                        }
                                    });

                                    frame.open();
                                }
                            });
                        } else {
                            content.html('<p style="color:#d63638;padding:20px;">' + (response.data.message || 'Failed to load settings') + '</p>');
                        }
                    },
                    error: function() {
                        content.html('<p style="color:#d63638;padding:20px;">Error loading settings</p>');
                    }
                });
            });

            // Close modal
            $(document).on('click', '#bp-addon-settings-modal .bp-modal__close, #bp-addon-settings-modal .bp-modal__cancel, #bp-addon-settings-modal .bp-modal__backdrop', function() {
                $('#bp-addon-settings-modal').fadeOut(200);
                currentAddonId = null;
            });

            // Open README modal
            $(document).on('click', '.bp-addon-readme-btn', function() {
                var btn = $(this);
                var addonId = btn.data('addon');
                var modal = $('#bp-addon-readme-modal');

                // Show loading state
                $('#bp-readme-title').text('Loading...');
                $('#bp-readme-content').html('<p style="text-align:center;padding:40px 0;color:#999;">Loading documentation...</p>');
                modal.fadeIn(200);

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_get_addon_readme',
                        nonce: window.starterSettings.nonce,
                        addon_id: addonId
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#bp-readme-title').text(response.data.title + ' - Documentation');
                            $('#bp-readme-content').html(response.data.content);
                        } else {
                            $('#bp-readme-content').html('<p style="color:#dc3545;">' + (response.data.message || 'Error loading documentation') + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('README AJAX Error:', status, error, xhr.responseText);
                        $('#bp-readme-content').html('<p style="color:#dc3545;">Error loading documentation: ' + error + '</p>');
                    }
                });
            });

            // Close README modal
            $(document).on('click', '#bp-addon-readme-modal .bp-modal__close, #bp-addon-readme-modal .bp-modal__backdrop', function() {
                $('#bp-addon-readme-modal').fadeOut(200);
            });

            // Save addon settings
            $(document).on('click', '.bp-addon-save-settings', function() {
                if (!currentAddonId) return;

                var btn = $(this);
                var content = $('#bp-addon-settings-content');
                var settings = {};

                // Collect all form fields
                content.find('input, select, textarea').each(function() {
                    var field = $(this);
                    var name = field.attr('name');
                    if (name) {
                        if (field.attr('type') === 'checkbox') {
                            settings[name] = field.is(':checked') ? 1 : 0;
                        } else {
                            settings[name] = field.val();
                        }
                    }
                });

                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_save_addon_settings',
                        nonce: window.starterSettings.nonce,
                        addon_id: currentAddonId,
                        settings: settings
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Save Settings');

                        if (response.success) {
                            self.showToast(response.data.message || 'Settings saved', 'success');
                            $('#bp-addon-settings-modal').fadeOut(200);
                        } else {
                            self.showToast(response.data.message || 'Error saving settings', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Settings');
                        self.showToast('An error occurred', 'error');
                    }
                });
            });
        },

        /**
         * Initialize Import/Export Tab
         */
        initImportExportTab: function() {
            var self = this;
            var importFileData = null;

            // Export settings
            $('#bp-export-settings').on('click', function() {
                var btn = $(this);
                var sections = [];

                // Collect selected export options
                $('.bp-export-option:checked').each(function() {
                    sections.push($(this).val());
                });

                if (sections.length === 0) {
                    self.showToast('Please select at least one section to export', 'error');
                    return;
                }

                btn.prop('disabled', true).html(
                    '<easier-icon name="loading-01" variant="twotone" size="16" color="#fff" style="animation:rotation 1s linear infinite;"></easier-icon> ' +
                    'Exporting...'
                );

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_export_settings',
                        nonce: window.starterSettings.nonce,
                        sections: sections
                    },
                    success: function(response) {
                        btn.prop('disabled', false).html(
                            '<easier-icon name="download-01" variant="twotone" size="16" color="#fff"></easier-icon> Export Settings'
                        );

                        if (response.success) {
                            // Create and trigger download
                            var blob = new Blob([JSON.stringify(response.data.data, null, 2)], {type: 'application/json'});
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = response.data.filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);

                            self.showToast(response.data.message, 'success');
                        } else {
                            self.showToast(response.data.message || 'Export failed', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(
                            '<easier-icon name="download-01" variant="twotone" size="16" color="#fff"></easier-icon> Export Settings'
                        );
                        self.showToast('An error occurred during export', 'error');
                    }
                });
            });

            // Handle file selection for import
            $('#bp-import-file').on('change', function(e) {
                var file = e.target.files[0];
                var $preview = $('#bp-import-preview');
                var $previewContent = $('#bp-import-preview-content');
                var $importBtn = $('#bp-import-settings');

                if (!file) {
                    $preview.hide();
                    $importBtn.prop('disabled', true);
                    importFileData = null;
                    return;
                }

                if (!file.name.endsWith('.json')) {
                    self.showToast('Please select a JSON file', 'error');
                    $(this).val('');
                    $preview.hide();
                    $importBtn.prop('disabled', true);
                    importFileData = null;
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        var data = JSON.parse(e.target.result);
                        importFileData = data;

                        // Validate the file structure
                        if (!data.meta || !data.meta.plugin_version) {
                            self.showToast('Invalid export file format', 'error');
                            $preview.hide();
                            $importBtn.prop('disabled', true);
                            importFileData = null;
                            return;
                        }

                        // Build preview content
                        var previewHtml = '<ul style="margin:0;padding-left:20px;">';
                        previewHtml += '<li><strong>Export Date:</strong> ' + (data.meta.export_date || 'Unknown') + '</li>';
                        previewHtml += '<li><strong>Source Site:</strong> ' + (data.meta.site_url || 'Unknown') + '</li>';
                        previewHtml += '<li><strong>Plugin Version:</strong> ' + data.meta.plugin_version + '</li>';
                        previewHtml += '<li><strong>Sections included:</strong><ul style="margin:4px 0 0;padding-left:20px;">';

                        if (data.menu_settings) previewHtml += '<li>Menu Visibility Settings</li>';
                        if (data.menu_order) previewHtml += '<li>Menu Order</li>';
                        if (data.dashboard_access) previewHtml += '<li>Dashboard Access Roles</li>';
                        if (data.cpt_visibility) previewHtml += '<li>CPT Visibility</li>';
                        if (data.visual_settings) previewHtml += '<li>Visual Settings</li>';
                        if (data.addons) {
                            previewHtml += '<li>Addon Settings<ul style="margin:4px 0 0;padding-left:16px;font-size:12px;color:#666;">';
                            if (data.addons.active_addons && data.addons.active_addons.length) {
                                previewHtml += '<li>Active addons: ' + data.addons.active_addons.join(', ') + '</li>';
                            }
                            if (data.addons.og_settings && Object.keys(data.addons.og_settings).length) {
                                previewHtml += '<li>OG/Social Preview settings</li>';
                            }
                            if (data.addons.hubspot_portal_id) {
                                previewHtml += '<li>HubSpot Portal ID: ' + data.addons.hubspot_portal_id + '</li>';
                            }
                            if (data.addons.hubspot_access_token) {
                                previewHtml += '<li>HubSpot Access Token: ****' + data.addons.hubspot_access_token.slice(-4) + '</li>';
                            }
                            previewHtml += '</ul></li>';
                        }

                        previewHtml += '</ul></li></ul>';

                        $previewContent.html(previewHtml);
                        $preview.show();
                        $importBtn.prop('disabled', false);

                    } catch (err) {
                        self.showToast('Error parsing JSON file: ' + err.message, 'error');
                        $preview.hide();
                        $importBtn.prop('disabled', true);
                        importFileData = null;
                    }
                };
                reader.readAsText(file);
            });

            // Import settings
            $('#bp-import-settings').on('click', function() {
                var btn = $(this);

                if (!importFileData) {
                    self.showToast('Please select a file first', 'error');
                    return;
                }

                if (!confirm('Are you sure you want to import these settings? This will overwrite your current configuration.')) {
                    return;
                }

                btn.prop('disabled', true).html(
                    '<easier-icon name="loading-01" variant="twotone" size="16" color="#fff" style="animation:rotation 1s linear infinite;"></easier-icon> ' +
                    'Importing...'
                );

                $.ajax({
                    url: window.starterSettings.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'starter_import_settings',
                        nonce: window.starterSettings.nonce,
                        import_data: JSON.stringify(importFileData)
                    },
                    success: function(response) {
                        btn.prop('disabled', false).html(
                            '<easier-icon name="upload-01" variant="twotone" size="16" color="#fff"></easier-icon> Import Settings'
                        );

                        if (response.success) {
                            self.showToast(response.data.message, 'success');

                            // Optionally reload page to reflect changes
                            setTimeout(function() {
                                if (confirm('Settings imported successfully. Would you like to reload the page to see the changes?')) {
                                    location.reload();
                                }
                            }, 500);
                        } else {
                            self.showToast(response.data.message || 'Import failed', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(
                            '<easier-icon name="upload-01" variant="twotone" size="16" color="#fff"></easier-icon> Import Settings'
                        );
                        self.showToast('An error occurred during import', 'error');
                    }
                });
            });
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            // Remove existing toasts
            $('.bp-toast').remove();

            var toast = $('<div class="bp-toast"></div>')
                .text(message)
                .addClass(type === 'error' ? 'bp-toast--error' : '')
                .css({
                    position: 'fixed',
                    bottom: '30px',
                    right: '30px',
                    padding: '14px 24px',
                    background: type === 'error' ? '#EF4444' : '#10B981',
                    color: '#fff',
                    borderRadius: '8px',
                    fontSize: '14px',
                    fontWeight: '500',
                    boxShadow: '0 10px 30px rgba(0, 0, 0, 0.2)',
                    zIndex: 100000,
                    opacity: 0,
                    transform: 'translateY(20px)',
                    transition: 'all 0.3s ease'
                })
                .appendTo('body');

            // Trigger animation
            setTimeout(function() {
                toast.css({ opacity: 1, transform: 'translateY(0)' });
            }, 10);

            // Auto-hide
            setTimeout(function() {
                toast.css({ opacity: 0, transform: 'translateY(20px)' });
                setTimeout(function() {
                    toast.remove();
                }, 300);
            }, 3000);
        }
    };

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        BusPatrolSettings.init();
    });

})(jQuery);
