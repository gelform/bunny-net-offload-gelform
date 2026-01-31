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
            this.initTabFromUrl();
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

            // Delete local files button
            $('#bnog-delete-local-btn').on('click', this.handleDeleteLocalFiles);

            // Resize and compress files button
            $('#bnog-resize-compress-btn').on('click', this.handleResizeCompress);
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

            // Update URL with tab parameter
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        },

        /**
         * Check URL for tab parameter and switch to that tab.
         */
        initTabFromUrl: function() {
            var url = new URL(window.location.href);
            var tab = url.searchParams.get('tab');

            if (tab) {
                var $btn = $('.bnog-tab-btn[data-tab="' + tab + '"]');
                if ($btn.length) {
                    // Update button states
                    $('.bnog-tab-btn').removeClass('active');
                    $btn.addClass('active');

                    // Update tab content
                    $('.bnog-tab-content').removeClass('active');
                    $('#bnog-tab-' + tab).addClass('active');
                }
            }
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
                    region: $('#bnog-region').val(),
                    storage_name: $('#bnog-storage-name').val()
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
            var $submitSection = $btn.closest('.submit');
            var $spinner = $submitSection.find('.spinner');
            var $message = $submitSection.find('.bnog-status-message');

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
                    keep_local_files: $('#bnog-keep-local').is(':checked') ? 1 : 0,
                    sync_all_files: $('#bnog-sync-all-files').is(':checked') ? 1 : 0,
                    custom_cdn_domain: $('#bnog-custom-cdn-domain').val(),
                    auto_updates: $('#bnog-auto-updates').is(':checked') ? 1 : 0
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

            var $btn = $(this);
            var $spinner = $('.bnog-sync-actions .spinner');
            var resizeBeforeSync = $('#bnog-resize-before-sync').is(':checked') ? 1 : 0;

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            var $progress = $('.bnog-sync-progress');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_sync_media',
                    nonce: bnogAdmin.nonce,
                    resize_before_sync: resizeBeforeSync
                },
                success: function(response) {
                    if (response && response.success) {
                        // Reload page to show sync in progress UI
                        window.location.reload();
                    } else {
                        // Show error message inline
                        var message = (response && response.data && response.data.message)
                            ? response.data.message
                            : bnogAdmin.strings.error;
                        $progress.text(message).addClass('error');
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                },
                error: function() {
                    $progress.text(bnogAdmin.strings.error).addClass('error');
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
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
        },

        /**
         * Handle delete local files button click.
         *
         * @param {Event} e Click event.
         */
        handleDeleteLocalFiles: function(e) {
            e.preventDefault();

            if (!confirm(bnogAdmin.strings.confirmDeleteLocal)) {
                return;
            }

            var $btn = $(this);
            var $section = $btn.closest('.bnog-delete-local-section');
            var $spinner = $section.find('.spinner');
            var $message = $section.find('.bnog-delete-local-status');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.text(bnogAdmin.strings.deletingLocal).removeClass('success error');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_delete_local_files',
                    nonce: bnogAdmin.nonce
                },
                success: function(response) {
                    $spinner.removeClass('is-active');

                    if (response.success) {
                        $message.text(bnogAdmin.strings.deleteLocalQueued).addClass('success');
                        // Reload page to show progress UI
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        $message.text(response.data.message || bnogAdmin.strings.error).addClass('error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.text(bnogAdmin.strings.error).addClass('error');
                }
            });
        },

        /**
         * Handle resize and compress files button click.
         *
         * @param {Event} e Click event.
         */
        handleResizeCompress: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $section = $btn.closest('.bnog-resize-compress-section');
            var $spinner = $section.find('.spinner');
            var $message = $section.find('.bnog-status-message');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.text(bnogAdmin.strings.resizingCompressing).removeClass('success error');

            $.ajax({
                url: bnogAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bnog_resize_compress_files',
                    nonce: bnogAdmin.nonce
                },
                success: function(response) {
                    $spinner.removeClass('is-active');

                    if (response.success) {
                        $message.text(bnogAdmin.strings.resizeCompressQueued).addClass('success');
                        // Reload page to show sync progress UI
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        $message.text(response.data.message || bnogAdmin.strings.error).addClass('error');
                        $btn.prop('disabled', false);
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
