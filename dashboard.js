/**
 * BusPatrol Custom Dashboard Scripts
 * Handles tabs, drag-and-drop tile reordering, and AJAX persistence.
 *
 * @package BusPatrol_Custom_Dashboard
 * @version 2.0.0
 */

(function($) {
    'use strict';

    /**
     * BusPatrol Dashboard Controller
     */
    var BusPatrolDashboard = {
        /**
         * Sortable instances per section
         */
        sortables: [],

        /**
         * Modal element reference
         */
        modal: null,

        /**
         * Elementor iframe modal reference
         */
        iframeModal: null,

        /**
         * Initialize the dashboard functionality
         */
        init: function() {
            this.initTabs();
            this.initSortables();
            this.initModal();
            this.initIframeModal();
            CustomActionModal.init();
        },

        /**
         * Current active tab's post types
         */
        currentPostTypes: [],

        /**
         * Initialize tab switching
         */
        initTabs: function() {
            var self = this;

            // Store initial post types from active tab
            var $activeTab = $('.bp-dashboard__tab--active');
            if ($activeTab.length) {
                var postTypesStr = $activeTab.data('post-types') || '';
                this.currentPostTypes = postTypesStr ? postTypesStr.split(',') : [];
            }

            // Check URL hash on init and switch to that tab if it exists
            this.handleHashOnLoad();

            $(document).on('click', '.bp-dashboard__tab', function(e) {
                e.preventDefault();
                var $tab = $(this);
                var tabId = $tab.data('tab');
                var postTypesStr = $tab.data('post-types') || '';
                var postTypes = postTypesStr ? postTypesStr.split(',') : [];

                self.switchTab(tabId, postTypes, true);
            });

            // Handle browser back/forward navigation
            $(window).on('popstate', function(e) {
                self.handleHashOnLoad(false);
            });

            // Initialize tabs overflow detection
            this.initTabsOverflow();

            // Hamburger toggle click
            $(document).on('click', '.bp-dashboard__tabs-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $tabs = $(this).closest('.bp-dashboard__tabs');
                $tabs.toggleClass('bp-dashboard__tabs--open');
                $(this).toggleClass('bp-dashboard__tabs-toggle--active');
                $(this).attr('aria-expanded', $tabs.hasClass('bp-dashboard__tabs--open'));
            });

            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.bp-dashboard__tabs').length) {
                    self.closeTabsDropdown();
                }
            });
        },

        /**
         * Initialize tabs overflow detection
         */
        initTabsOverflow: function() {
            var self = this;
            var $tabs = $('.bp-dashboard__tabs');
            if (!$tabs.length) return;

            // Check overflow on load and resize
            var checkOverflow = function() {
                var $tabsList = $tabs.find('.bp-dashboard__tabs-list');
                var $tabButtons = $tabsList.find('.bp-dashboard__tab');

                // Temporarily show as flex to measure
                $tabs.removeClass('bp-dashboard__tabs--has-overflow bp-dashboard__tabs--open');
                $tabsList.css('display', 'flex').css('flex-wrap', 'nowrap');

                // Calculate total width needed
                var totalWidth = 0;
                $tabButtons.each(function() {
                    totalWidth += $(this).outerWidth(true);
                });

                // Get available width (container width minus padding)
                var availableWidth = $tabs.width() - 40; // 40px for padding

                // Reset styles
                $tabsList.css('display', '').css('flex-wrap', '');

                // Add overflow class if needed
                if (totalWidth > availableWidth) {
                    $tabs.addClass('bp-dashboard__tabs--has-overflow');
                    self.updateActiveTabIndicator();
                }
            };

            checkOverflow();
            $(window).on('resize', debounce(checkOverflow, 150));

            // Debounce helper
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
        },

        /**
         * Update active tab indicator in collapsed state
         */
        updateActiveTabIndicator: function() {
            var $activeTab = $('.bp-dashboard__tab--active');
            var $indicator = $('.bp-dashboard__tabs-active');
            if ($activeTab.length && $indicator.length) {
                var icon = $activeTab.find('easier-icon').clone();
                var text = $activeTab.text().trim();
                $indicator.empty().append(icon).append($('<span>').text(text));
            }
        },

        /**
         * Close tabs dropdown
         */
        closeTabsDropdown: function() {
            var $tabs = $('.bp-dashboard__tabs');
            $tabs.removeClass('bp-dashboard__tabs--open');
            $tabs.find('.bp-dashboard__tabs-toggle')
                .removeClass('bp-dashboard__tabs-toggle--active')
                .attr('aria-expanded', 'false');
        },

        /**
         * Handle URL hash on page load or popstate
         *
         * @param {boolean} updateHash - Whether to update hash if tab not found
         */
        handleHashOnLoad: function(updateHash) {
            var hash = window.location.hash.replace('#', '');
            if (!hash) return;

            var $tab = $('.bp-dashboard__tab[data-tab="' + hash + '"]');
            if ($tab.length) {
                var postTypesStr = $tab.data('post-types') || '';
                var postTypes = postTypesStr ? postTypesStr.split(',') : [];
                this.switchTab(hash, postTypes, false);
            }
        },

        /**
         * Switch to a specific tab
         *
         * @param {string} tabId - The tab identifier
         * @param {Array} postTypes - Array of post type names for this tab
         * @param {boolean} updateHash - Whether to update the URL hash (default: true)
         */
        switchTab: function(tabId, postTypes, updateHash) {
            // Update tab buttons
            $('.bp-dashboard__tab').removeClass('bp-dashboard__tab--active');
            $('.bp-dashboard__tab[data-tab="' + tabId + '"]').addClass('bp-dashboard__tab--active');

            // Update tab content
            $('.bp-dashboard__tab-content').removeClass('bp-dashboard__tab-content--active');
            $('.bp-dashboard__tab-content[data-tab="' + tabId + '"]').addClass('bp-dashboard__tab-content--active');

            // Update active tab indicator and close dropdown
            this.updateActiveTabIndicator();
            this.closeTabsDropdown();

            // Update URL hash (use pushState to avoid page jump)
            if (updateHash !== false && window.history && window.history.pushState) {
                var newUrl = window.location.pathname + window.location.search + '#' + tabId;
                window.history.pushState({ tabId: tabId }, '', newUrl);
            }

            // Update Recent Activity if viewing content tab and post types changed
            var isAddonTab = tabId.indexOf('addon-') === 0;
            if (!isAddonTab && postTypes !== undefined) {
                this.currentPostTypes = postTypes;
                this.loadRecentActivity(postTypes);
            }
        },

        /**
         * Load Recent Activity for specific post types via AJAX
         *
         * @param {Array} postTypes - Array of post type names
         */
        loadRecentActivity: function(postTypes) {
            var self = this;
            var $section = $('#bp-recent-activity');
            var $content = $('#bp-recent-content');
            var $spinner = $section.find('.spinner');

            if (!$content.length) {
                return;
            }

            // Don't load for Additional Elements tab (no post types)
            if (!postTypes || postTypes.length === 0) {
                $content.html('<p class="bp-dashboard__empty">No recent posts for this section.</p>');
                return;
            }

            // Show loading state
            $spinner.css('display', 'inline-block').addClass('is-active');

            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_get_recent_activity',
                    nonce: window.starterDashboard.nonce,
                    post_types: postTypes
                },
                success: function(response) {
                    $spinner.css('display', 'none').removeClass('is-active');

                    if (response.success && response.data.html) {
                        $content.html(response.data.html);
                    } else {
                        $content.html('<p class="bp-dashboard__empty">No recent posts.</p>');
                    }
                },
                error: function() {
                    $spinner.css('display', 'none').removeClass('is-active');
                    $content.html('<p class="bp-dashboard__empty">Error loading recent activity.</p>');
                }
            });
        },

        /**
         * Initialize SortableJS on all grid containers
         */
        initSortables: function() {
            var self = this;

            // Content grids (post type tiles)
            var grids = document.querySelectorAll('.bp-dashboard__grid--content');
            grids.forEach(function(grid) {
                var sortable = new Sortable(grid, {
                    animation: 150,
                    easing: 'cubic-bezier(0.4, 0, 0.2, 1)',
                    handle: '.bp-card__header',
                    ghostClass: 'bp-card-ghost',
                    chosenClass: 'bp-card-chosen',
                    dragClass: 'bp-card-drag',
                    forceFallback: false,
                    group: 'dashboard-cards',

                    /**
                     * Callback when drag ends
                     */
                    onEnd: function(evt) {
                        self.saveTileOrder();
                    }
                });

                self.sortables.push(sortable);
            });

            // Additional Elements grid
            var additionalGrid = document.querySelector('.bp-dashboard__grid--additional');
            if (additionalGrid) {
                var additionalSortable = new Sortable(additionalGrid, {
                    animation: 150,
                    easing: 'cubic-bezier(0.4, 0, 0.2, 1)',
                    handle: '.bp-card__header',
                    ghostClass: 'bp-card-ghost',
                    chosenClass: 'bp-card-chosen',
                    dragClass: 'bp-card-drag',
                    forceFallback: false,

                    /**
                     * Callback when drag ends
                     */
                    onEnd: function(evt) {
                        self.saveAdditionalElementsOrder();
                    }
                });

                self.sortables.push(additionalSortable);
            }
        },

        /**
         * Extract current tile order from all grids
         *
         * @return {Array} Array of post type slugs in current order
         */
        getTileOrder: function() {
            var order = [];
            var cards = document.querySelectorAll('.bp-card[data-post-type]');

            cards.forEach(function(card) {
                var postType = card.getAttribute('data-post-type');
                if (postType && order.indexOf(postType) === -1) {
                    order.push(postType);
                }
            });

            return order;
        },

        /**
         * Save tile order via AJAX
         */
        saveTileOrder: function() {
            var self = this;
            var order = this.getTileOrder();

            if (!window.starterDashboard || !window.starterDashboard.ajaxUrl) {
                self.showNotification('Configuration error', 'error');
                return;
            }

            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_tile_order',
                    nonce: window.starterDashboard.nonce,
                    order: order
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotification(
                            window.starterDashboard.strings.orderSaved || 'Order saved',
                            'success'
                        );
                    } else {
                        self.showNotification(
                            response.data.message || window.starterDashboard.strings.error || 'Error',
                            'error'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    self.showNotification(
                        window.starterDashboard.strings.error || 'Connection error',
                        'error'
                    );
                }
            });
        },

        /**
         * Extract current additional elements order
         *
         * @return {Array} Array of menu slugs in current order
         */
        getAdditionalElementsOrder: function() {
            var order = [];
            var cards = document.querySelectorAll('.bp-dashboard__grid--additional .bp-card--additional[data-slug]');

            cards.forEach(function(card) {
                var slug = card.getAttribute('data-slug');
                if (slug && order.indexOf(slug) === -1) {
                    order.push(slug);
                }
            });

            return order;
        },

        /**
         * Save additional elements order via AJAX
         */
        saveAdditionalElementsOrder: function() {
            var self = this;
            var order = this.getAdditionalElementsOrder();

            if (!window.starterDashboard || !window.starterDashboard.ajaxUrl) {
                self.showNotification('Configuration error', 'error');
                return;
            }

            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_additional_elements_order',
                    nonce: window.starterDashboard.nonce,
                    order: order
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotification(
                            window.starterDashboard.strings.orderSaved || 'Order saved',
                            'success'
                        );
                    } else {
                        self.showNotification(
                            response.data.message || window.starterDashboard.strings.error || 'Error',
                            'error'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    self.showNotification(
                        window.starterDashboard.strings.error || 'Connection error',
                        'error'
                    );
                }
            });
        },

        /**
         * Show toast notification
         *
         * @param {string} message - Message to display
         * @param {string} type - Notification type: 'success' or 'error'
         */
        showNotification: function(message, type) {
            // Remove any existing notifications
            $('.bp-notification').remove();

            var notification = document.createElement('div');
            notification.className = 'bp-notification bp-notification-' + type;
            notification.setAttribute('role', 'alert');
            notification.setAttribute('aria-live', 'polite');
            notification.textContent = message;

            document.body.appendChild(notification);

            // Trigger reflow to enable CSS transition
            notification.offsetHeight;
            notification.classList.add('bp-notification-visible');

            // Auto-remove after 3 seconds
            setTimeout(function() {
                notification.classList.remove('bp-notification-visible');

                setTimeout(function() {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        },

        /**
         * Initialize modal functionality
         */
        initModal: function() {
            var self = this;
            this.modal = $('#bp-items-modal');

            if (!this.modal.length) {
                return;
            }

            // Handle link clicks that should open modal
            $(document).on('click', '.bp-card__link--modal', function(e) {
                e.preventDefault();
                var $link = $(this);

                var data = {
                    itemType: $link.data('modal-type'),
                    sourceType: $link.data('source-type') || '',
                    templateType: $link.data('template-type') || '',
                    url: $link.attr('href')
                };

                self.openModal(data);
            });

            // Close modal on backdrop click
            this.modal.on('click', '.bp-modal__backdrop', function() {
                self.closeModal();
            });

            // Close modal on close button click
            this.modal.on('click', '.bp-modal__close', function() {
                self.closeModal();
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.modal.attr('aria-hidden') === 'false') {
                    self.closeModal();
                }
            });
        },

        /**
         * Open modal and load items
         *
         * @param {Object} data - Modal data (itemType, sourceType, templateType, url)
         */
        openModal: function(data) {
            var self = this;

            // Set loading state
            this.modal.removeClass('bp-modal--empty')
                      .addClass('bp-modal--loading')
                      .attr('aria-hidden', 'false');

            // Set full page link
            this.modal.find('.bp-modal__full-link').attr('href', data.url);

            // Clear previous list
            this.modal.find('.bp-modal__list').empty();

            // Fetch items via AJAX
            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_get_modal_items',
                    nonce: window.starterDashboard.nonce,
                    item_type: data.itemType,
                    source_type: data.sourceType,
                    template_type: data.templateType
                },
                success: function(response) {
                    self.modal.removeClass('bp-modal--loading');

                    if (response.success) {
                        self.renderModalItems(response.data);
                    } else {
                        self.modal.addClass('bp-modal--empty');
                        self.modal.find('.bp-modal__title').text('Error');
                    }
                },
                error: function() {
                    self.modal.removeClass('bp-modal--loading').addClass('bp-modal--empty');
                    self.modal.find('.bp-modal__title').text('Error');
                }
            });
        },

        /**
         * Render items in modal list
         *
         * @param {Object} data - Response data with items, title, full_url
         */
        renderModalItems: function(data) {
            var self = this;
            var $list = this.modal.find('.bp-modal__list');

            // Set title
            this.modal.find('.bp-modal__title').text(data.title + ' (' + data.count + ')');

            // Set full URL
            this.modal.find('.bp-modal__full-link').attr('href', data.full_url);

            // Check if data is grouped (Elementor templates)
            if (data.items && data.items.grouped === true && data.items.groups) {
                var groups = data.items.groups;
                // Make sure groups is an object with keys (not empty array)
                if (typeof groups === 'object' && !Array.isArray(groups) && Object.keys(groups).length > 0) {
                    this.renderGroupedItems(groups, $list);
                    return;
                }
                // If groups is empty or array, show empty
                this.modal.addClass('bp-modal--empty');
                return;
            }

            // Flat list (taxonomies, ACF)
            if (!data.items || data.items.length === 0) {
                this.modal.addClass('bp-modal--empty');
                return;
            }

            // Build list items
            data.items.forEach(function(item) {
                $list.append(self.createListItem(item));
            });
        },

        /**
         * Render grouped items (for Elementor templates)
         */
        renderGroupedItems: function(groups, $list) {
            var self = this;
            var hasItems = false;

            Object.keys(groups).forEach(function(groupKey) {
                var group = groups[groupKey];
                if (!group.items || group.items.length === 0) {
                    return;
                }

                hasItems = true;

                // Group header
                var $groupHeader = $('<li class="bp-modal__group-header">' + group.label + ' <span class="bp-modal__group-count">(' + group.items.length + ')</span></li>');
                $list.append($groupHeader);

                // Group items
                group.items.forEach(function(item) {
                    $list.append(self.createListItem(item, true));
                });
            });

            if (!hasItems) {
                this.modal.addClass('bp-modal--empty');
            }
        },

        /**
         * Create a single list item element
         */
        createListItem: function(item, showLocation) {
            var $li = $('<li class="bp-modal__list-item"></li>');

            // Title link
            var $title = $('<a class="bp-modal__item-title" href="' + (item.url || '#') + '">' + (item.title || '(no title)') + '</a>');
            $li.append($title);

            // Meta info
            var $meta = $('<div class="bp-modal__item-meta"></div>');

            // Location (for Elementor templates)
            if (showLocation && item.location) {
                var locationHtml = '<span class="bp-modal__item-location">' + item.location + '</span>';
                if (item.location_url) {
                    locationHtml = '<a href="' + item.location_url + '" target="_blank" class="bp-modal__item-location bp-modal__item-location--link">' + item.location + ' <easier-icon name="share-08" variant="twotone" size="14" color="currentColor" style="vertical-align:middle;"></easier-icon></a>';
                }
                $meta.append(locationHtml);
            }

            // Count (for taxonomy terms)
            if (item.count !== undefined) {
                $meta.append('<span class="bp-modal__item-count">' + item.count + '</span>');
            }

            // Field count (for ACF)
            if (item.field_count !== undefined) {
                $meta.append('<span class="bp-modal__item-count">' + item.field_count + ' fields</span>');
            }

            // Status (for Elementor templates)
            if (item.status) {
                $meta.append('<span class="bp-modal__item-status bp-modal__item-status--' + item.status + '">' + item.status + '</span>');
            }

            // ID (to distinguish items with same name)
            if (item.id) {
                $meta.append('<span class="bp-modal__item-id">ID: ' + item.id + '</span>');
            }

            $li.append($meta);

            // Actions - Elementor edit button
            if (item.edit_elementor) {
                var $actions = $('<div class="bp-modal__item-actions"></div>');
                $actions.append('<a href="' + item.edit_elementor + '" class="bp-modal__item-action bp-modal__item-action--elementor" title="Edit with Elementor"><easier-icon name="pencil-edit-02" variant="twotone" size="14" color="currentColor" style="vertical-align:middle;"></easier-icon> Edit</a>');
                $li.append($actions);
            }

            return $li;
        },

        /**
         * Close modal
         */
        closeModal: function() {
            this.modal.attr('aria-hidden', 'true')
                      .removeClass('bp-modal--loading bp-modal--empty');
        },

        /**
         * Initialize Elementor iframe modal
         */
        initIframeModal: function() {
            var self = this;
            this.iframeModal = $('#bp-elementor-modal');

            if (!this.iframeModal.length) {
                return;
            }

            // Handle Elementor edit button clicks
            $(document).on('click', '.bp-modal__item-action--elementor', function(e) {
                e.preventDefault();
                var url = $(this).attr('href');
                var title = $(this).closest('.bp-modal__list-item').find('.bp-modal__item-title').text();
                self.openIframeModal(url, title, '#92003B'); // Elementor magenta
            });

            // Handle Quick Action iframe clicks (Media Library, Add Media)
            $(document).on('click', '.bp-action--iframe', function(e) {
                e.preventDefault();
                var url = $(this).attr('href');
                var title = $(this).data('iframe-title') || 'Loading...';
                var color = $(this).css('--action-color') || getComputedStyle(this).getPropertyValue('--action-color') || '#2ABADE';
                self.openIframeModal(url, title, color.trim());
            });

            // Handle Recent Activity preview button clicks
            $(document).on('click', '.bp-recent-item__action--preview', function(e) {
                e.preventDefault();
                var url = $(this).data('url');
                var title = $(this).data('title') || 'Preview';
                self.openIframeModal(url, title, '#2ABADE');
            });

            // Close on backdrop click
            this.iframeModal.on('click', '.bp-iframe-modal__backdrop', function() {
                self.closeIframeModal();
            });

            // Close on close button click
            this.iframeModal.on('click', '.bp-iframe-modal__close', function() {
                self.closeIframeModal();
            });

            // Close on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.iframeModal.attr('aria-hidden') === 'false') {
                    self.closeIframeModal();
                }
            });

            // Handle iframe load
            this.iframeModal.find('.bp-iframe-modal__iframe').on('load', function() {
                self.iframeModal.addClass('bp-iframe-modal--loaded');
            });
        },

        /**
         * Add bp_iframe parameter to URL for iframe mode
         *
         * @param {string} url - Original URL
         * @return {string} URL with bp_iframe=1 parameter
         */
        addIframeParam: function(url) {
            if (!url || url === '#' || url === 'about:blank') {
                return url;
            }
            var separator = url.indexOf('?') !== -1 ? '&' : '?';
            return url + separator + 'bp_iframe=1';
        },

        /**
         * Open iframe modal
         *
         * @param {string} url - URL to load
         * @param {string} title - Modal title
         * @param {string} color - Header background color (optional)
         */
        openIframeModal: function(url, title, color) {
            var $iframe = this.iframeModal.find('.bp-iframe-modal__iframe');
            var $openTab = this.iframeModal.find('.bp-iframe-modal__open-tab');
            var $title = this.iframeModal.find('.bp-iframe-modal__title-text');
            var $header = this.iframeModal.find('.bp-iframe-modal__header');

            // Set title
            $title.text(title);

            // Set header color
            if (color) {
                $header.css('background', color);
            }

            // Set open in new tab link (without iframe param - full view)
            $openTab.attr('href', url);

            // Add iframe parameter for sidebar-hidden mode
            var iframeUrl = this.addIframeParam(url);

            // Reset loaded state and set iframe src
            this.iframeModal.removeClass('bp-iframe-modal--loaded');
            $iframe.attr('src', iframeUrl);

            // Show modal
            this.iframeModal.attr('aria-hidden', 'false');

            // Close the items modal if open
            if (this.modal.attr('aria-hidden') === 'false') {
                this.closeModal();
            }
        },

        /**
         * Close Elementor iframe modal
         */
        closeIframeModal: function() {
            var $iframe = this.iframeModal.find('.bp-iframe-modal__iframe');

            this.iframeModal.attr('aria-hidden', 'true')
                           .removeClass('bp-iframe-modal--loaded');

            // Clear iframe to stop any ongoing processes
            $iframe.attr('src', 'about:blank');
        }
    };

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        BusPatrolDashboard.init();

        // Handle addon settings save from dashboard tabs
        $(document).on('click', '.bp-addon-dashboard-save', function() {
            var btn = $(this);
            var addonId = btn.data('addon');
            var panel = btn.closest('.bp-addon-settings-panel');
            var settings = {};

            // Collect all form fields from panel content
            panel.find('.bp-addon-settings-panel__content input, .bp-addon-settings-panel__content select, .bp-addon-settings-panel__content textarea').each(function() {
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
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_addon_settings',
                    nonce: window.starterDashboard.nonce,
                    addon_id: addonId,
                    settings: settings
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Save Settings');

                    if (response.success) {
                        BusPatrolDashboard.showToast(response.data.message || 'Settings saved', 'success');
                    } else {
                        BusPatrolDashboard.showToast(response.data.message || 'Error saving settings', 'error');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Save Settings');
                    BusPatrolDashboard.showToast('An error occurred', 'error');
                }
            });
        });

        // Handle media library for addon image fields in dashboard
        $(document).on('click', '.bp-addon-settings-panel .bp-select-image', function(e) {
            e.preventDefault();
            var button = $(this);
            var field = button.siblings('input[type="url"]');
            var previewContainer = button.closest('.bp-addon-settings__field').find('.bp-image-preview');

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
                    if (previewContainer.length) {
                        previewContainer.html('<img src="' + attachment.url + '" style="max-width:300px;height:auto;border:1px solid #ddd;border-radius:4px;">');
                    }
                });

                frame.open();
            }
        });
    });

    /**
     * Custom Action Modal Controller
     */
    var CustomActionModal = {
        modal: null,
        selectedItem: null,
        iconList: [
            // Navigation & Home
            'home-01', 'home-02', 'home-03', 'home-04', 'home-05',
            'dashboard-square-01', 'dashboard-square-02', 'dashboard-circle',
            'menu-01', 'menu-02', 'menu-square',
            // Files & Folders
            'file-01', 'file-02', 'file-add', 'file-edit', 'file-search', 'file-download', 'file-upload',
            'folder-01', 'folder-02', 'folder-add', 'folder-open', 'folder-shared-01',
            // Media
            'image-01', 'image-02', 'image-add-01', 'image-upload',
            'video-01', 'video-02', 'camera-01', 'camera-02', 'camera-video',
            'music-note-01', 'music-note-02', 'play', 'play-circle',
            // Users
            'user', 'user-add-01', 'user-add-02', 'user-check-01', 'user-edit-01',
            'user-group', 'user-settings-01', 'add-team',
            // Communication
            'mail-01', 'mail-02', 'mail-add-01', 'mail-open',
            'comment-01', 'comment-02', 'comment-add-01',
            'notification-01', 'notification-02', 'notification-03',
            // Settings
            'settings-01', 'settings-02', 'settings-03', 'dashboard-circle-settings',
            // Data & Analytics
            'database', 'database-01', 'database-02', 'database-add',
            'analytics-01', 'analytics-02', 'analytics-up',
            // E-commerce
            'shopping-cart-01', 'shopping-cart-02', 'shopping-bag-01',
            'store-01', 'store-02', 'credit-card', 'credit-card-add',
            'wallet-01', 'wallet-02', 'dollar-01', 'dollar-02', 'tag-01', 'tag-02',
            // Security
            'shield-01', 'shield-02', 'key-01', 'key-02',
            'login-01', 'login-02', 'logout-01', 'logout-02',
            // Calendar & Time
            'calendar-01', 'calendar-02', 'calendar-03', 'calendar-add-01',
            'clock-01', 'clock-02', 'clock-03', 'clock-04', 'clock-05',
            // Actions
            'add-01', 'add-02', 'add-circle', 'add-square',
            'delete-01', 'delete-02', 'cancel-01', 'cancel-02', 'cancel-circle',
            'edit-01', 'edit-02', 'pencil-edit-01', 'pencil-edit-02',
            'download-01', 'download-02', 'upload-01', 'upload-02',
            'checkmark-circle-01', 'checkmark-square-01',
            // Misc
            'star', 'star-circle', 'star-square',
            'bookmark-01', 'bookmark-02', 'bookmark-add-01',
            'link-01', 'link-02', 'link-square-01',
            'share-01', 'share-02', 'share-03', 'share-08',
            'search-01', 'search-02',
            'globe-02', 'location-01', 'location-02',
            'code', 'code-circle', 'code-square', 'code-folder',
            'cloud', 'cloud-server', 'cloud-upload', 'cloud-download',
            'laptop', 'laptop-add',
            'alert-01', 'alert-02', 'alert-circle',
            'help-circle', 'help-square',
            'door-01', 'door-02',
            'plug-01', 'plug-02', 'fire', 'rocket-01', 'rocket-02'
        ],

        init: function() {
            var self = this;
            this.modal = $('#bp-custom-action-modal');

            if (!this.modal.length) {
                return;
            }

            // Open modal on add button click
            $(document).on('click', '#bp-add-custom-action', function() {
                self.openModal();
            });

            // Close modal
            this.modal.on('click', '.bp-modal__backdrop, .bp-modal__close', function() {
                self.closeModal();
            });

            // Search filtering for menu items
            $(document).on('input', '#bp-custom-action-search', function() {
                self.filterItems($(this).val());
            });

            // Search filtering for icons
            $(document).on('input', '#bp-icon-search', function() {
                self.filterIcons($(this).val());
            });

            // Item selection
            $(document).on('click', '.bp-custom-action__item', function() {
                var $item = $(this);
                self.selectedItem = {
                    label: $item.data('label'),
                    url: $item.data('url'),
                    icon: $item.data('icon')
                };
                self.showIconPicker();
            });

            // Back to menu list
            $(document).on('click', '#bp-icon-picker-back', function() {
                self.hideIconPicker();
            });

            // Icon selection - save action
            $(document).on('click', '.bp-custom-action__icon-option', function() {
                var icon = $(this).data('icon');
                self.saveAction(icon);
            });

            // Remove custom action
            $(document).on('click', '.bp-action__remove', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var actionId = $(this).data('action-id');
                self.removeAction(actionId, $(this).closest('.bp-action__wrapper'));
            });

            // Close on Escape
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.modal.attr('aria-hidden') === 'false') {
                    self.closeModal();
                }
            });

            // Populate icon grid
            this.populateIcons();
        },

        openModal: function() {
            this.selectedItem = null;
            this.hideIconPicker();
            $('#bp-custom-action-search').val('');
            this.filterItems('');
            this.modal.attr('aria-hidden', 'false');
        },

        closeModal: function() {
            this.modal.attr('aria-hidden', 'true');
        },

        filterItems: function(query) {
            query = query.toLowerCase().trim();
            $('.bp-custom-action__item').each(function() {
                var $item = $(this);
                var label = ($item.data('label') || '').toLowerCase();
                if (query === '' || label.indexOf(query) !== -1) {
                    $item.removeClass('bp-custom-action__item--hidden');
                } else {
                    $item.addClass('bp-custom-action__item--hidden');
                }
            });
        },

        filterIcons: function(query) {
            query = query.toLowerCase().trim();
            var queryWords = query.split(/\s+/).filter(function(w) { return w.length > 0; });

            $('.bp-custom-action__icon-option').each(function() {
                var $icon = $(this);
                var iconName = ($icon.data('icon') || '').toLowerCase();
                var nameWithSpaces = iconName.replace(/-/g, ' ').replace(/\d+/g, '').trim();

                if (query === '') {
                    $icon.removeClass('bp-custom-action__icon-option--hidden');
                    return;
                }

                // Check if all query words match
                var matches = queryWords.every(function(word) {
                    return iconName.indexOf(word) !== -1 || nameWithSpaces.indexOf(word) !== -1;
                });

                if (matches) {
                    $icon.removeClass('bp-custom-action__icon-option--hidden');
                } else {
                    $icon.addClass('bp-custom-action__icon-option--hidden');
                }
            });
        },

        showIconPicker: function() {
            $('#bp-custom-action-list').hide();
            $('#bp-custom-action-search').closest('.bp-custom-action__search').hide();
            $('.bp-custom-action__selected-label').text(this.selectedItem.label);
            $('#bp-icon-picker').show();
            $('#bp-icon-search').val('').focus();
            this.filterIcons('');

            // Pre-select current icon
            $('.bp-custom-action__icon-option').removeClass('bp-custom-action__icon-option--selected');
            $('.bp-custom-action__icon-option[data-icon="' + this.selectedItem.icon + '"]')
                .addClass('bp-custom-action__icon-option--selected');
        },

        hideIconPicker: function() {
            $('#bp-icon-picker').hide();
            $('#bp-custom-action-list').show();
            $('#bp-custom-action-search').closest('.bp-custom-action__search').show();
        },

        populateIcons: function() {
            var self = this;
            var $grid = $('#bp-icon-grid');
            if (!$grid.length) return;

            $grid.empty();
            this.iconList.forEach(function(icon) {
                // Format name for tooltip: "chart-line-data-01" -> "Chart Line Data"
                var tooltipName = icon.replace(/-\d+$/, '').replace(/-/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                var $option = $('<div class="bp-custom-action__icon-option" data-icon="' + icon + '" title="' + tooltipName + '">' +
                    '<easier-icon name="' + icon + '" variant="twotone" size="20" color="currentColor"></easier-icon>' +
                    '</div>');
                $grid.append($option);
            });
        },

        saveAction: function(icon) {
            var self = this;

            if (!this.selectedItem) {
                return;
            }

            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_save_custom_action',
                    nonce: window.starterDashboard.nonce,
                    label: this.selectedItem.label,
                    url: this.selectedItem.url,
                    icon: icon,
                    color: '#1C3C8B'
                },
                success: function(response) {
                    if (response.success) {
                        self.closeModal();
                        BusPatrolDashboard.showNotification(response.data.message, 'success');
                        self.addActionToUI(response.data.action);
                    } else {
                        BusPatrolDashboard.showNotification(response.data.message || 'Error', 'error');
                    }
                },
                error: function() {
                    BusPatrolDashboard.showNotification('Connection error', 'error');
                }
            });
        },

        addActionToUI: function(action) {
            var html = '<div class="bp-action__wrapper bp-action__wrapper--custom">' +
                '<a href="' + action.url + '" class="bp-action bp-action--custom" style="--action-color: ' + action.color + '">' +
                    '<span class="bp-action__icon">' +
                        '<easier-icon name="' + action.icon + '" variant="twotone" size="20" color="currentColor"></easier-icon>' +
                    '</span>' +
                    '<span class="bp-action__label">' + action.label + '</span>' +
                '</a>' +
                '<button type="button" class="bp-action__remove" data-action-id="' + action.id + '" title="Remove">' +
                    '<easier-icon name="cancel-01" variant="twotone" size="14" color="currentColor"></easier-icon>' +
                '</button>' +
            '</div>';

            // Insert before the Add Action button
            $('#bp-add-custom-action').before(html);
        },

        removeAction: function(actionId, $wrapper) {
            var self = this;

            $.ajax({
                url: window.starterDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'starter_remove_custom_action',
                    nonce: window.starterDashboard.nonce,
                    action_id: actionId
                },
                success: function(response) {
                    if (response.success) {
                        $wrapper.fadeOut(200, function() {
                            $(this).remove();
                        });
                        BusPatrolDashboard.showNotification(response.data.message, 'success');
                    } else {
                        BusPatrolDashboard.showNotification(response.data.message || 'Error', 'error');
                    }
                },
                error: function() {
                    BusPatrolDashboard.showNotification('Connection error', 'error');
                }
            });
        }
    };

    /**
     * Add showToast method to controller
     */
    BusPatrolDashboard.showToast = function(message, type) {
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

        setTimeout(function() {
            toast.css({ opacity: 1, transform: 'translateY(0)' });
        }, 10);

        setTimeout(function() {
            toast.css({ opacity: 0, transform: 'translateY(20px)' });
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    };

})(jQuery);
