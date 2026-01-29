/**
 * Bunny.net Offload by Gelform - Admin JavaScript
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var BNOGAdmin = {
        /**
         * Initialize the admin interface.
         */
        init: function() {
            this.bindEvents();
            this.initRangeInputs();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Connect button
            $('#bnog-connect').on('click', this.handleConnect);

            // Disconnect button
            $('#bnog-disconnect').on('click', this.handleDisconnect);

            // Setup form
            $('#bnog-setup-form').on('submit', this.handleSetup);

            // Settings form
            $('#bnog-settings-form').on('submit', this.handleSaveSettings);

            // Sync button
            $('#bnog-sync-btn').on('click', this.handleSync);

            // Purge cache button
            $('#bnog-purge-btn').on('click', this.handlePurgeCache);

            // Tab switching
            $('.bnog-tab-btn').on('click', this.handleTabSwitch);
        },

        /**
         * Initialize range inputs with value display.
         */
        initRangeInputs: function() {
            $('.bnog-range-input').on('input', function() {
                var $input = $(this);
                var $value = $('#' + $input.attr('id') + '-value');
                var inputId = $input.attr('id');

                // PNG compression doesn't use percentage
                if (inputId === 'bnog-png-compression') {
                    $value.text($input.val());
                } else {
                    $value.text($input.val() + '%');
                }
            });
        },

        /**
         * Handle tab switching.
         *
         * @param {Event} e Click event.
         */
        handleTabSwitch: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var tab = $btn.data('tab');

            // Update button states
            $('.bnog-tab-btn').removeClass('active');
            $btn.addClass('active');

            // Update tab content
            $('.bnog-tab-content').removeClass('active');
            $('#bnog-tab-' + tab).addClass('active');
        },

        /**
         * Handle connect button click.
         * Now using an anchor tag, so we just let default link behavior work.
         *
         * @param {Event} e Click event.
         */
        handleConnect: function(e) {
            // The connect button is now an anchor tag with the auth URL as href.
            // Just let the default link behavior work - no need to prevent default.
            // This allows the browser to navigate to Bunny.net authorization.
        },

        /**
         * Handle disconnect button click.
         *
         * @param {Event} e Click event.
         */
        handleDisconnect: function(e) {
            e.preventDefault();

            if (!confirm(bnogAdmin.strings.confirmDelete)) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_disconnect',
                    nonce: bnogAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data.message || bnogAdmin.strings.error);
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert(bnogAdmin.strings.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * Handle CDN setup form submission.
         *
         * @param {Event} e Submit event.
         */
        handleSetup: function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#bnog-setup-btn');
            var $spinner = $form.find('.spinner');
            var $message = $form.find('.bnog-status-message');

            $form.addClass('bnog-loading');
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.text(bnogAdmin.strings.settingUp).removeClass('success error');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_setup_cdn',
                    nonce: bnogAdmin.nonce,
                    region: $('#bnog-region').val()
                },
                success: function(response) {
                    $form.removeClass('bnog-loading');
                    $spinner.removeClass('is-active');

                    if (response.success) {
                        $message.text(response.data.message).addClass('success');
                        // Reload after short delay to show success message
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        $message.text(response.data.message || bnogAdmin.strings.error).addClass('error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    $form.removeClass('bnog-loading');
                    $spinner.removeClass('is-active');
                    $message.text(bnogAdmin.strings.error).addClass('error');
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * Handle settings form submission.
         *
         * @param {Event} e Submit event.
         */
        handleSaveSettings: function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#bnog-save-btn');
            var $spinner = $form.find('.spinner');
            var $message = $form.find('.bnog-status-message');

            $form.addClass('bnog-loading');
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.text(bnogAdmin.strings.saving).removeClass('success error');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_save_settings',
                    nonce: bnogAdmin.nonce,
                    max_width: $('#bnog-max-width').val(),
                    jpeg_quality: $('#bnog-jpeg-quality').val(),
                    png_compression: $('#bnog-png-compression').val(),
                    webp_quality: $('#bnog-webp-quality').val(),
                    keep_local_files: $('#bnog-keep-local').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    $form.removeClass('bnog-loading');
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);

                    if (response.success) {
                        $message.text(bnogAdmin.strings.saved).addClass('success');
                        setTimeout(function() {
                            $message.text('');
                        }, 3000);
                    } else {
                        $message.text(response.data.message || bnogAdmin.strings.error).addClass('error');
                    }
                },
                error: function() {
                    $form.removeClass('bnog-loading');
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.text(bnogAdmin.strings.error).addClass('error');
                }
            });
        },

        /**
         * Handle sync button click.
         *
         * @param {Event} e Click event.
         */
        handleSync: function(e) {
            e.preventDefault();

            var $syncSection = $('.bnog-sync-section');
            var resizeBeforeSync = $('#bnog-resize-before-sync').is(':checked') ? 1 : 0;

            // Immediately replace form with syncing UI
            $syncSection.html(
                '<div class="bnog-sync-in-progress">' +
                    '<div class="bnog-sync-stats">' +
                        '<span class="bnog-stat bnog-stat-pending">' +
                            '<strong class="bnog-sync-remaining">...</strong> ' + bnogAdmin.strings.syncing +
                        '</span>' +
                    '</div>' +
                    '<div class="bnog-sync-running-notice">' +
                        '<span class="spinner is-active"></span>' +
                        '<span class="bnog-sync-running-text">' + bnogAdmin.strings.syncing + '</span>' +
                    '</div>' +
                    '<p class="bnog-sync-notice">' + bnogAdmin.strings.syncBackground + '</p>' +
                '</div>'
            );

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_sync_media',
                    nonce: bnogAdmin.nonce,
                    resize_before_sync: resizeBeforeSync
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.total > 0) {
                            $('.bnog-sync-remaining').text(response.data.total);
                            // Start polling for progress
                            BNOGAdmin.pollSyncProgress();
                        } else {
                            // No images to sync
                            $syncSection.html('<p>' + response.data.message + '</p>');
                        }
                    } else {
                        $syncSection.html('<p class="bnog-status-message error">' + (response.data.message || bnogAdmin.strings.error) + '</p>');
                    }
                },
                error: function() {
                    $syncSection.html('<p class="bnog-status-message error">' + bnogAdmin.strings.error + '</p>');
                }
            });
        },

        /**
         * Poll for sync progress (simplified version).
         */
        pollSyncProgress: function() {
            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_get_sync_status',
                    nonce: bnogAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;

                        $('.bnog-sync-remaining').text(data.remaining);
                        $('.bnog-sync-running-text').text(data.processed + ' / ' + data.total + ' (' + percent + '%)');

                        if (data.running && data.remaining > 0) {
                            // Continue polling
                            setTimeout(function() {
                                BNOGAdmin.pollSyncProgress();
                            }, 2000);
                        } else {
                            // Done - reload to show updated UI
                            window.location.reload();
                        }
                    }
                },
                error: function() {
                    // Continue polling on error (might be temporary)
                    setTimeout(function() {
                        BNOGAdmin.pollSyncProgress();
                    }, 5000);
                }
            });
        },

        /**
         * Handle purge cache button click.
         *
         * @param {Event} e Click event.
         */
        handlePurgeCache: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $section = $btn.closest('.bnog-cache-section');
            var $spinner = $section.find('.spinner');
            var $message = $section.find('.bnog-status-message');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.text(bnogAdmin.strings.purging).removeClass('success error');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_purge_cache',
                    nonce: bnogAdmin.nonce
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);

                    if (response.success) {
                        $message.text(bnogAdmin.strings.purged).addClass('success');
                        setTimeout(function() {
                            $message.text('');
                        }, 3000);
                    } else {
                        $message.text(response.data.message || bnogAdmin.strings.error).addClass('error');
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.text(bnogAdmin.strings.error).addClass('error');
                }
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        BNOGAdmin.init();
    });

})(jQuery);
