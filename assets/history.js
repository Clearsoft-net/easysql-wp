/* global jQuery, wpApiSettings */

(function ($) {
    'use strict';

    var $tbody   = $('#easysql-history-body');
    var $total   = $('#easysql-history-total');
    var $prev    = $('#easysql-prev-page');
    var $next    = $('#easysql-next-page');
    var $curPage = $('#easysql-current-page');
    var $totPages = $('#easysql-total-pages');
    var $pagination = $('#easysql-history-pagination');

    if (! $tbody.length) {
        return;
    }

    var connectorId = null;
    var currentPage = 1;

    // -----------------------------------------------------------------------
    // Bootstrap: load connector, then load first page
    // -----------------------------------------------------------------------

    function loadConnectorAndHistory() {
        $.ajax({
            url: wpApiSettings.root + 'easysql/v1/connector',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                connectorId = resp.id;
                loadPage(1);
            },
            error: function () {
                $tbody.html(
                    '<tr><td colspan="4"><span class="error">Could not load connector.</span></td></tr>'
                );
            },
        });
    }

    // -----------------------------------------------------------------------
    // Load a page of history
    // -----------------------------------------------------------------------

    function loadPage(page) {
        if (! connectorId) {
            return;
        }

        currentPage = page;

        $tbody.html(
            '<tr><td colspan="4"><span class="spinner" style="float:none;visibility:visible;"></span> Loading…</td></tr>'
        );

        $.ajax({
            url: wpApiSettings.root + 'easysql/v1/queries',
            method: 'GET',
            data: {
                connector_id: connectorId,
                page: page,
                per_page: 15,
            },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                renderRows(resp.items || []);
                updatePagination(resp);
            },
            error: function (xhr) {
                var msg = 'Could not load history.';
                try {
                    var body = xhr.responseJSON;
                    if (body && body.error) {
                        msg = body.error;
                    }
                } catch (_) { /* ignore */ }
                $tbody.html('<tr><td colspan="4"><span class="error">' + msg + '</span></td></tr>');
                $pagination.hide();
            },
        });
    }

    // -----------------------------------------------------------------------
    // Render rows
    // -----------------------------------------------------------------------

    function renderRows(items) {
        if (! items || items.length === 0) {
            $tbody.html(
                '<tr><td colspan="4">No queries yet. Go to <strong>EasySQL → Ask</strong> to ask your first question.</td></tr>'
            );
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var question = item.question || '(empty)';
            var answer   = truncate(item.answer || item.sql_generated || '', 80);
            var date     = item.created_at
                ? new Date(item.created_at).toLocaleString()
                : '—';
            var askUrl   = 'admin.php?page=easysql-ask&question='
                + encodeURIComponent(item.question);

            html += '<tr>';
            html += '<td>' + escapeHtml(question) + '</td>';
            html += '<td>' + escapeHtml(answer) + '</td>';
            html += '<td>' + escapeHtml(date) + '</td>';
            html += '<td><a href="' + askUrl + '" class="button button-small">Ask Again</a></td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

    // -----------------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------------

    function updatePagination(resp) {
        var total      = resp.total || 0;
        var totalPages = resp.total_pages || 1;

        $total.text(total + ' item' + (total !== 1 ? 's' : ''));
        $curPage.text(resp.page || 1);
        $totPages.text(totalPages);

        $prev.prop('disabled', currentPage <= 1);
        $next.prop('disabled', currentPage >= totalPages);

        $pagination.show();
    }

    $prev.on('click', function () {
        if (currentPage > 1) {
            loadPage(currentPage - 1);
        }
    });

    $next.on('click', function () {
        loadPage(currentPage + 1);
    });

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function truncate(str, maxLen) {
        if (! str) {
            return '';
        }
        return str.length > maxLen ? str.substring(0, maxLen) + '…' : str;
    }

    // -----------------------------------------------------------------------
    // Start
    // -----------------------------------------------------------------------

    loadConnectorAndHistory();
}(jQuery));
