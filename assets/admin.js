/* global jQuery, wpApiSettings */

(function ($) {
    'use strict';

    // -----------------------------------------------------------------------
    // Test Connection
    // -----------------------------------------------------------------------

    const $testBtn    = $('#easysql-test-btn');
    const $testResult = $('#easysql-test-result');

    if ($testBtn.length) {
        $testBtn.on('click', function () {
            $testBtn.prop('disabled', true);
            $testResult.html('<span style="color:#666">Testing…</span>');

            $.ajax({
                url:  wpApiSettings.root + 'easysql/v1/test-connection',
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
                },
                success: function (resp) {
                    $testResult.html('<span class="success">' + resp.message + '</span>');
                },
                error: function (xhr) {
                    var msg = 'Connection failed.';
                    try {
                        var body = xhr.responseJSON;
                        if (body && body.message) {
                            msg = body.message;
                        } else if (xhr.status) {
                            msg = 'HTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '');
                        }
                    } catch (_) { /* ignore */ }
                    $testResult.html('<span class="error">' + msg + '</span>');
                },
                complete: function () {
                    $testBtn.prop('disabled', false);
                },
            });
        });
    }

    // -----------------------------------------------------------------------
    // Connector Status
    // -----------------------------------------------------------------------

    var $connectorStatus = $('#easysql-connector-status');
    var $syncBtn         = $('#easysql-sync-btn');

    function loadConnector() {
        if (! $connectorStatus.length) {
            return;
        }

        $connectorStatus.html(
            '<span class="spinner" style="float:none;margin-top:0;visibility:visible;"></span> Loading…'
        );

        $.ajax({
            url:  wpApiSettings.root + 'easysql/v1/connector',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                var html = '<table class="widefat striped" style="max-width:500px">';
                html += '<tr><td><strong>ID</strong></td><td><code>' + resp.id + '</code></td></tr>';
                html += '<tr><td><strong>Name</strong></td><td>' + resp.name + '</td></tr>';
                html += '<tr><td><strong>Type</strong></td><td>' + (resp.type || 'mysql') + '</td></tr>';
                html += '<tr><td><strong>Last Sync</strong></td><td>' + (resp.last_sync_at || 'Never') + '</td></tr>';
                html += '</table>';
                $connectorStatus.html(html);
                $syncBtn.prop('disabled', false);
            },
            error: function (xhr) {
                var msg = 'Could not load connector.';
                try {
                    var body = xhr.responseJSON;
                    if (body && body.error) {
                        msg = body.error;
                    }
                } catch (_) { /* ignore */ }
                $connectorStatus.html('<span class="error">' + msg + '</span>');
                $syncBtn.prop('disabled', true);
            },
        });
    }

    if ($connectorStatus.length) {
        loadConnector();
    }

    $syncBtn.on('click', function () {
        $syncBtn.prop('disabled', true);
        $connectorStatus.html(
            '<span class="spinner" style="float:none;margin-top:0;visibility:visible;"></span> Syncing…'
        );

        $.ajax({
            url:  wpApiSettings.root + 'easysql/v1/connector/sync',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function () {
                loadConnector();
            },
            error: function (xhr) {
                var msg = 'Sync failed.';
                try {
                    var body = xhr.responseJSON;
                    if (body && body.message) {
                        msg = body.message;
                    }
                } catch (_) { /* ignore */ }
                $connectorStatus.html('<span class="error">' + msg + '</span>');
                $syncBtn.prop('disabled', false);
            },
        });
    });
}(jQuery));
