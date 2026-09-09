jQuery(document).ready(function ($) {
    'use strict';

    var isSyncInProgress = false;
    var autoReloadInterval = null;
    var progressInterval = null;
    var countdownInterval = null;

    // ─── Toast Notifications ───
    function showToast(message, type) {
        type = type || 'info';
        var icons = { success: 'yes-alt', error: 'warning', info: 'info', warning: 'flag' };
        var toast = $('<div class="blk-toast blk-toast-' + type + '">' +
            '<span class="dashicons dashicons-' + (icons[type] || 'info') + '"></span>' +
            '<span>' + message + '</span>' +
            '</div>');

        $('.blk-wrap').prepend(toast);
        setTimeout(function () {
            toast.fadeOut(300, function () { toast.remove(); });
        }, 6000);
    }

    // ─── Sync Status Banner with Progress Bar ───
    function showSyncBanner() {
        if ($('.blk-sync-banner').length) return;
        var banner = $(
            '<div class="blk-sync-banner">' +
                '<div class="blk-sync-banner-content">' +
                    '<div class="blk-spinner"></div>' +
                    '<div class="blk-sync-info">' +
                        '<span class="blk-sync-text">Starting synchronization...</span>' +
                        '<div class="blk-progress" style="margin: 6px 0 0;">' +
                            '<div class="blk-progress-bar green" style="width: 0%;" id="blk-progress-bar"></div>' +
                        '</div>' +
                        '<span class="blk-sync-detail" style="font-size: 12px; color: #64748b;"></span>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $('.blk-tabs').after(banner);
        startProgressPolling();
    }

    function hideSyncBanner() {
        stopProgressPolling();
        $('.blk-sync-banner').fadeOut(300, function () { $(this).remove(); });
    }

    // ─── Progress Bar Polling ───
    function startProgressPolling() {
        if (progressInterval) return;
        progressInterval = setInterval(function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'blk_sync_progress', nonce: blkAdminPageData.nonce },
                success: function (response) {
                    if (!response.success) return;
                    var d = response.data;
                    var pct = d.percent || 0;
                    $('#blk-progress-bar').css('width', pct + '%');
                    if (d.message) {
                        $('.blk-sync-text').text(d.message);
                    }
                    if (d.step === 'done') {
                        $('#blk-progress-bar').css('width', '100%');
                    }
                }
            });
        }, 2000);
    }

    function stopProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }

    // ─── Auto-reload logs ───
    function startAutoReload() {
        if (autoReloadInterval) return;
        autoReloadInterval = setInterval(function () {
            var f = $('#blk-log-file-select').val();
            if (!f) return;
            $.get(blkAdminPageData.logFilePath + f + '?t=' + Date.now(), function (r) {
                renderLog(r);
            });
        }, 2500);
    }

    function stopAutoReload() {
        if (autoReloadInterval) {
            clearInterval(autoReloadInterval);
            autoReloadInterval = null;
        }
    }

    // ─── Render Log Content ───
    function renderLog(content) {
        var container = $('#blk-log');
        if (!content || !content.trim()) {
            container.html('<div class="blk-log-empty"><span class="dashicons dashicons-media-text" style="font-size:32px;margin-bottom:8px;display:block;"></span>No log data available</div>');
            return;
        }
        if (typeof Prism !== 'undefined' && Prism.languages.log) {
            var h = Prism.highlight(content, Prism.languages.log, 'log');
            container.html('<pre class="language-log"><code>' + h + '</code></pre>');
        } else {
            container.html('<pre><code>' + $('<span>').text(content).html() + '</code></pre>');
        }
        var pre = container.find('pre')[0];
        if (pre) pre.scrollTop = pre.scrollHeight;
    }

    function loadLogFile(path) {
        $('#blk-log-file-select').prop('disabled', true);
        $.get(path + '?t=' + Date.now(), function (r) {
            renderLog(r);
        }).fail(function () {
            $('#blk-log').html('<div class="blk-log-empty">Failed to load log file</div>');
        }).always(function () {
            $('#blk-log-file-select').prop('disabled', false);
        });
    }

    // ─── Persistent "Last Synchronization" Summary ───
    // Unlike the toast (which fades after 6s) and the banner (which
    // disappears as soon as the sync is done), this box stays visible on
    // the dashboard until the next sync starts.
    function showLastSyncSummary(message, time, stats) {
        if (!message) return;
        var $box = $('#blk-last-sync-summary');
        $box.find('.blk-last-sync-title strong').html(
            'Last synchronization<span id="blk-last-sync-time">' + (time ? ' (' + time + ')' : '') + '</span>:'
        );

        if (stats) {
            var val = function (v) { return (v === undefined || v === null) ? 0 : v; };
            var tableHtml =
                '<table class="blk-last-sync-table" id="blk-last-sync-table">' +
                    '<thead><tr><th>Duration</th><th>Added</th><th>Updated</th><th>Removed</th><th>Ignored</th></tr></thead>' +
                    '<tbody><tr>' +
                        '<td>' + (stats.duration || '—') + '</td>' +
                        '<td>' + val(stats.added) + '</td>' +
                        '<td>' + val(stats.updated) + '</td>' +
                        '<td>' + val(stats.removed) + '</td>' +
                        '<td>' + val(stats.ignored) + '</td>' +
                    '</tr></tbody>' +
                '</table>';
            var $existingTable = $box.find('#blk-last-sync-table');
            if ($existingTable.length) {
                $existingTable.replaceWith(tableHtml);
            } else {
                $box.find('#blk-last-sync-message').remove();
                $box.append(tableHtml);
            }
        } else {
            $box.find('#blk-last-sync-table').remove();
            if ($box.find('#blk-last-sync-message').length) {
                $box.find('#blk-last-sync-message').text(message);
            } else {
                $box.append('<span id="blk-last-sync-message">' + message + '</span>');
            }
        }

        $box.show();
    }

    function hideLastSyncSummary() {
        $('#blk-last-sync-summary').hide();
    }

    // ─── Check Sync Status ───
    function checkSyncStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'blk_sync_status', nonce: blkAdminPageData.nonce },
            success: function (response) {
                if (response.success && response.data.status === 'completed') {
                    stopAutoReload();
                    isSyncInProgress = false;
                    $('#blk-start-import').removeClass('disabled').prop('disabled', false);
                    showToast('Synchronization completed successfully!', 'success');

                    // Grab the final progress message before the banner
                    // disappears, and keep it visible persistently.
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { action: 'blk_sync_progress', nonce: blkAdminPageData.nonce },
                        success: function (progressResponse) {
                            if (progressResponse.success && progressResponse.data) {
                                showLastSyncSummary(progressResponse.data.message, progressResponse.data.time, progressResponse.data.stats);
                            }
                        },
                        complete: function () { hideSyncBanner(); }
                    });
                }
            }
        });
    }

    // ─── Countdown Timer ───
    function startCountdown() {
        var el = $('#blk-countdown');
        if (!el.length) return;

        var target = parseInt(el.data('timestamp'), 10);
        if (!target || isNaN(target)) {
            el.text('Not scheduled');
            return;
        }

        var refreshInFlight = false;
        var lastRefreshAt = 0;

        // The rendered data-timestamp goes stale once the event fires. Poll the
        // sync-status endpoint (throttled) for the next occurrence so the badge
        // flips back from "Running now..." to a live countdown without a reload.
        function refreshTarget() {
            var nowMs = Date.now();
            if (refreshInFlight || nowMs - lastRefreshAt < 10000) return;
            refreshInFlight = true;
            lastRefreshAt = nowMs;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'blk_sync_status', nonce: blkAdminPageData.nonce },
                success: function (response) {
                    if (!response.success || !response.data) return;
                    var next = parseInt(response.data.next_sync, 10);
                    if (next && next > Math.floor(Date.now() / 1000)) {
                        target = next;
                        el.data('timestamp', next).attr('data-timestamp', next);
                        if (response.data.next_sync_display) {
                            el.next('.blk-badge').text(response.data.next_sync_display);
                        }
                    }
                },
                complete: function () {
                    refreshInFlight = false;
                }
            });
        }

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            var diff = target - now;
            if (diff <= 0) {
                el.html('<span class="blk-badge warn">Running now...</span>');
                refreshTarget();
                return;
            }
            var h = Math.floor(diff / 3600);
            var m = Math.floor((diff % 3600) / 60);
            var s = diff % 60;
            var parts = [];
            if (h > 0) parts.push(h + 'h');
            parts.push(m + 'm');
            parts.push(s + 's');
            el.text(parts.join(' '));
        }

        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    // ─── Start Import ───
    $(document).on('click', '#blk-start-import', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('disabled')) return;

        $btn.addClass('disabled').prop('disabled', true);
        isSyncInProgress = true;
        hideLastSyncSummary();
        showSyncBanner();
        startAutoReload();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'blk_start_import', nonce: blkAdminPageData.nonce },
            success: function () { showToast('Synchronization started.', 'info'); },
            error: function () {
                showToast('Error starting synchronization.', 'error');
                $btn.removeClass('disabled').prop('disabled', false);
                hideSyncBanner();
                isSyncInProgress = false;
                stopAutoReload();
            }
        });
    });

    // ─── Stop Import ───
    $(document).on('click', '#blk-stop-import', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.addClass('disabled').prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'blk_stop_import', nonce: blkAdminPageData.nonce },
            success: function () {
                stopAutoReload();
                hideSyncBanner();
                isSyncInProgress = false;
                $('#blk-start-import').removeClass('disabled').prop('disabled', false);
                showToast('Stop signal sent.', 'warning');
            },
            complete: function () { $btn.removeClass('disabled').prop('disabled', false); }
        });
    });

    // ─── Test Connection ───
    $(document).on('click', '#blk-test-connection', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#blk-connection-result');
        $btn.addClass('disabled').prop('disabled', true);
        $result.html('<span style="color:var(--blk-text-muted);">Testing...</span>').show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'blk_test_connection', nonce: blkAdminPageData.nonce },
            success: function (response) {
                if (response.success) {
                    var d = response.data;
                    var html = '<div class="blk-toast blk-toast-success" style="margin:0;">' +
                        '<span class="dashicons dashicons-yes-alt"></span>' +
                        '<span>' + d.message + '</span></div>';

                    if (d.inventories && d.inventories.length) {
                        html += '<div style="margin-top:12px;">';
                        d.inventories.forEach(function (inv) {
                            html += '<div style="padding:8px 0;border-bottom:1px solid var(--blk-border);font-size:13px;">';
                            html += '<strong>' + inv.name + '</strong>';
                            if (inv.is_default) html += ' <span class="blk-badge on">Default</span>';
                            html += '<br><span style="color:var(--blk-text-muted);">';
                            html += 'ID: ' + inv.inventory_id;
                            html += ' | Price group: ' + inv.default_price_group;
                            html += ' | Warehouse: ' + inv.default_warehouse;
                            html += ' | Language: ' + inv.default_language;
                            if (inv.languages && inv.languages.length) {
                                html += ' | All languages: ' + inv.languages.join(', ');
                            }
                            html += '</span></div>';
                        });
                        html += '</div>';
                    }
                    $result.html(html);
                } else {
                    $result.html('<div class="blk-toast blk-toast-error" style="margin:0;">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<span>' + (response.data || 'Connection failed') + '</span></div>');
                }
            },
            error: function () {
                $result.html('<div class="blk-toast blk-toast-error" style="margin:0;">Network error</div>');
            },
            complete: function () { $btn.removeClass('disabled').prop('disabled', false); }
        });
    });

    // ─── Log File Select / Reload ───
    $(document).on('change', '#blk-log-file-select', function () {
        loadLogFile(blkAdminPageData.logFilePath + $(this).val());
    });
    $(document).on('click', '#blk-log-reload', function (e) {
        e.preventDefault();
        var f = $('#blk-log-file-select').val();
        if (f) loadLogFile(blkAdminPageData.logFilePath + f);
    });

    // ─── Status polling ───
    setInterval(function () {
        if (isSyncInProgress) checkSyncStatus();
    }, 3000);

    // ─── Initial load ───
    var currentFile = $('#blk-log-file-select').val();
    if (currentFile) loadLogFile(blkAdminPageData.logFilePath + currentFile);

    // Check if sync already running
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: 'blk_sync_status', nonce: blkAdminPageData.nonce },
        success: function (response) {
            if (response.success && response.data.status === 'in_progress') {
                isSyncInProgress = true;
                showSyncBanner();
                startAutoReload();
                $('#blk-start-import').addClass('disabled').prop('disabled', true);
            }
        }
    });

    // Start countdown timer if present
    startCountdown();
});
