/**
 * Elementor Video Controls JavaScript
 */

(function($) {
    'use strict';

    class VideoControls {
        constructor(element) {
            this.$element = $(element);
            this.targetSelector = this.$element.data('target');
            this.$muteBtn = this.$element.find('.evc-mute-btn');
            this.$playBtn = this.$element.find('.evc-play-btn');
            this.$progress = this.$element.find('.evc-progress');
            this.$progressBar = this.$element.find('.evc-progress-bar');
            this.showIcons = this.$element.data('show-icons') === true || this.$element.data('show-icons') === 'true';
            this.debug = this.$element.data('debug') === true || this.$element.data('debug') === 'true';

            this.texts = {
                mute: this.$element.data('mute-text') || 'Mute',
                unmute: this.$element.data('unmute-text') || 'Unmute',
                play: this.$element.data('play-text') || 'Play',
                pause: this.$element.data('pause-text') || 'Pause'
            };

            this.$video = null;
            this.init();
        }

        log(...args) {
            if (this.debug) {
                console.log('[Elementor Video Controls]', ...args);
            }
        }

        init() {
            this.log('Initializing video controls');
            this.log('Target selector:', this.targetSelector || 'auto-detect');

            // Find the video element
            this.findVideo();

            if (!this.$video || this.$video.length === 0) {
                console.warn('Elementor Video Controls: No video found');
                this.log('Search methods tried:',
                    'target selector:', this.targetSelector,
                    'parent containers:', this.$element.closest('.elementor-element, .elementor-container, .elementor-section').length,
                    'section:', this.$element.closest('section, .elementor-top-section').length
                );
                // Still bind events, user might add video later
                this.bindEvents();
                return;
            }

            this.log('Video found:', this.$video[0]);

            // Set initial states
            this.updateMuteButton(!this.$video[0].muted);
            this.updatePlayButton(!this.$video[0].paused);

            // Bind events
            this.bindEvents();

            // Monitor video state changes
            this.monitorVideoState();
        }

        findVideo() {
            this.log('Starting video search...');

            // 1. If target specified, use that
            if (this.targetSelector) {
                this.log('Trying target selector:', this.targetSelector);
                const $target = $(this.targetSelector);
                if ($target.length) {
                    this.$video = $target.find('video').first();
                    if (this.$video.length) {
                        this.log('✓ Video found via target selector');
                        return;
                    }
                }
            }

            // 2. MAIN METHOD: Look in closest parent Elementor element
            // This is the most common case: shortcode inside container with background video
            this.log('Searching in parent Elementor elements...');

            // Try different parent levels
            const parentSelectors = [
                '.elementor-element',      // Most specific - usually the container with video
                '.elementor-widget-wrap',  // Column content wrap
                '.elementor-column',       // Column
                '.elementor-section',      // Section
                '.elementor-container'     // Container
            ];

            for (let selector of parentSelectors) {
                const $parent = this.$element.closest(selector);
                if ($parent.length) {
                    this.log('Checking parent:', selector, $parent[0]);
                    // Look for video that is direct child or in background
                    this.$video = $parent.find('video').first();
                    if (this.$video.length) {
                        this.log('✓ Video found in parent:', selector);
                        return;
                    }
                }
            }

            // 3. Fallback: search in any parent up the tree
            this.log('Trying broader parent search...');
            this.$video = this.$element.parents().find('video').first();
            if (this.$video.length) {
                this.log('✓ Video found in ancestor element');
                return;
            }

            // 4. Last resort: any video on page
            this.log('Trying page-wide search...');
            this.$video = $('video').first();
            if (this.$video.length) {
                this.log('⚠ Video found on page (may not be the right one)');
                return;
            }

            this.log('✗ No video found anywhere');
        }

        bindEvents() {
            // Mute/Unmute button
            this.$muteBtn.on('click', (e) => {
                e.preventDefault();
                this.toggleMute();
            });

            // Play/Pause button
            this.$playBtn.on('click', (e) => {
                e.preventDefault();
                this.togglePlay();
            });

            // Progress bar click to seek
            this.$progress.on('click', (e) => {
                if (!this.$video || this.$video.length === 0) return;
                const video = this.$video[0];
                if (!video.duration) return;
                const rect = this.$progress[0].getBoundingClientRect();
                const pct = (e.clientX - rect.left) / rect.width;
                video.currentTime = pct * video.duration;
            });
        }

        monitorVideoState() {
            // Listen to video events to keep button states in sync
            this.$video.on('play playing', () => {
                this.updatePlayButton(true);
            });

            this.$video.on('pause ended', () => {
                this.updatePlayButton(false);
            });

            this.$video.on('volumechange', () => {
                this.updateMuteButton(!this.$video[0].muted);
            });

            // Update progress bar
            this.$video.on('timeupdate', () => {
                const video = this.$video[0];
                if (video.duration) {
                    const pct = (video.currentTime / video.duration) * 100;
                    this.$progressBar.css('width', pct + '%');
                }
            });
        }

        toggleMute() {
            if (!this.$video || this.$video.length === 0) return;

            const video = this.$video[0];
            video.muted = !video.muted;
            this.updateMuteButton(!video.muted);
        }

        togglePlay() {
            if (!this.$video || this.$video.length === 0) return;

            const video = this.$video[0];
            if (video.paused) {
                video.play().catch(err => {
                    console.error('Elementor Video Controls: Error playing video', err);
                });
            } else {
                video.pause();
            }
        }

        updateMuteButton(isUnmuted) {
            if (this.showIcons) {
                // Toggle icon visibility
                this.$muteBtn.find('.evc-icon-unmuted').toggle(isUnmuted);
                this.$muteBtn.find('.evc-icon-muted').toggle(!isUnmuted);
            } else {
                // Toggle text visibility
                this.$muteBtn.find('.evc-text-unmuted').toggle(isUnmuted);
                this.$muteBtn.find('.evc-text-muted').toggle(!isUnmuted);
            }

            // Update aria-label
            const label = isUnmuted ? this.texts.mute : this.texts.unmute;
            this.$muteBtn.attr('aria-label', label);
        }

        updatePlayButton(isPlaying) {
            if (this.showIcons) {
                // Toggle icon visibility
                this.$playBtn.find('.evc-icon-playing').toggle(isPlaying);
                this.$playBtn.find('.evc-icon-paused').toggle(!isPlaying);
            } else {
                // Toggle text visibility
                this.$playBtn.find('.evc-text-playing').toggle(isPlaying);
                this.$playBtn.find('.evc-text-paused').toggle(!isPlaying);
            }

            // Update aria-label
            const label = isPlaying ? this.texts.pause : this.texts.play;
            this.$playBtn.attr('aria-label', label);
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        initVideoControls();
    });

    // Re-initialize after Elementor preview updates
    $(window).on('elementor/frontend/init', function() {
        setTimeout(initVideoControls, 1000);
    });

    // Initialize video controls
    function initVideoControls() {
        $('.elementor-video-controls').each(function() {
            // Check if already initialized
            if (!$(this).data('evc-initialized')) {
                new VideoControls(this);
                $(this).data('evc-initialized', true);
            }
        });
    }

})(jQuery);
