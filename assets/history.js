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

    var connectionId = null;
    var currentPage = 1;
    var cursors = [null];

    // -----------------------------------------------------------------------
    // Bootstrap: load connector, then load first page
    // -----------------------------------------------------------------------

    function loadConnectorAndHistory() {
        $.ajax({
            url: wpApiSettings.root + 'easysql/v1/connection',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                connectionId = resp.id;
                loadPage(1);
            },
            error: function () {
                $tbody.html(
                    '<tr><td colspan="4"><span class="error">Could not load WordPress connection.</span></td></tr>'
                );
            },
        });
    }

    // -----------------------------------------------------------------------
    // Load a page of history
    // -----------------------------------------------------------------------

    function loadPage(page) {
        if (! connectionId) {
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
                connection_id: connectionId,
                cursor: cursors[page - 1],
                limit: 15,
            },

            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                renderRows(resp.items || []);
                nextCursor = resp.next_cursor || null;
                if (nextCursor && cursors[page] === undefined) {
                    cursors[page] = nextCursor;
                }
                updatePagination(resp);
            },
            error: function (xhr) {
                var msg = 'Could not load history.';
                var body = xhr.responseJSON;
                if (body && body.error) {
                    msg = body.error;
                }
                $tbody.html('<tr><td colspan="4"><span class="error">' + escapeHtml(msg) + '</span></td></tr>');
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
        var items = resp.items || [];
        var hasNext = !! resp.next_cursor;

        $total.text(items.length + ' items');
        $curPage.text(currentPage);
        $totPages.text(hasNext ? currentPage + '+' : currentPage);

        $prev.prop('disabled', currentPage <= 1);
        $next.prop('disabled', ! hasNext);

        $pagination.show();
    }

    $prev.on('click', function () {
        if (currentPage > 1) {
            loadPage(currentPage - 1);
        }
    });

    $next.on('click', function () {
        if (nextCursor) {
            loadPage(currentPage + 1);
        }
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
