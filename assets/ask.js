/* global jQuery, wpApiSettings, Chart, marked, easysqlAsk */

(function ($) {
    'use strict';

    var $question   = $('#easysql-question');
    var $askBtn     = $('#easysql-ask-btn');
    var $askStatus  = $('#easysql-ask-status');
    var $result     = $('#easysql-ask-result');
    var $answer     = $('#easysql-answer');
    var $sql        = $('#easysql-sql');
    var $toggleSql  = $('#easysql-toggle-sql');
    var $resultTbl  = $('#easysql-result-table');
    var $chartCtnr  = $('#easysql-chart-container');
    var $chartCanvas = $('#easysql-chart');

    if (! $askBtn.length) {
        return;
    }

    // -----------------------------------------------------------------------
    // Toggle SQL block
    // -----------------------------------------------------------------------

    $toggleSql.on('click', function () {
        var $pre = $('#easysql-sql');
        if ($pre.is(':visible')) {
            $pre.hide();
            $toggleSql.text('Show');
        } else {
            $pre.show();
            $toggleSql.text('Hide');
        }
    });

    // -----------------------------------------------------------------------
    // Ask
    // -----------------------------------------------------------------------

    function submitQuestion() {
        var question = $question.val().trim();
        if (! question) {
            $askStatus.html('<span class="error">Please enter a question.</span>');
            return;
        }

        var connectorId = easysqlAsk.connector_id;
        if (! connectorId) {
            $askStatus.html(
                '<span class="error">Connector not available. Go to Settings → EasySQL first.</span>'
            );
            return;
        }

        $askBtn.prop('disabled', true);
        $askStatus.html('<span style="color:#666">Thinking…</span>');
        $result.hide();

        $.ajax({
            url:  wpApiSettings.root + 'easysql/v1/query',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                connector_id: connectorId,
                question: question,
            }),
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function (resp) {
                renderResults(resp);
                $askStatus.html('');
            },
            error: function (xhr) {
                var msg = 'Request failed.';
                try {
                    var body = xhr.responseJSON;
                    if (body && body.error) {
                        msg = body.error;
                    }
                } catch (_) { /* ignore */ }
                $askStatus.html('<span class="error">' + msg + '</span>');
            },
            complete: function () {
                $askBtn.prop('disabled', false);
            },
        });
    }

    $askBtn.on('click', submitQuestion);

    // ENTER submits the question; Shift+ENTER inserts a new line.
    $question.on('keydown', function (e) {
        if (e.key === 'Enter' && ! e.shiftKey) {
            e.preventDefault();
            submitQuestion();
        }
    });

    // Escape any raw HTML in the markdown (the marked library does not
    // sanitize by default). The answer comes from the LLM, so we must not
    // let it inject markup into the admin page.
    if (typeof marked !== 'undefined') {
        var escapeHtml = function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        };
        var renderer = new marked.Renderer();
        var defaultHtml = renderer.html.bind(renderer);
        renderer.html = function (html) {
            return escapeHtml(typeof html === 'string' ? html : '');
        };
        marked.setOptions({ renderer: renderer });
    }

    // -----------------------------------------------------------------------
    // Render results
    // -----------------------------------------------------------------------

    function renderResults(resp) {
        // Answer — render the markdown returned by the API (bold, lists, etc.),
        // escaping any raw HTML so the LLM output can't inject markup.
        var answerMarkdown = resp.answer || '(empty)';
        if (typeof marked !== 'undefined') {
            $answer.html(marked.parse(answerMarkdown, { breaks: true }));
        } else {
            $answer.text(answerMarkdown);
        }

        // SQL
        if (resp.sql_generated) {
            $sql.text(resp.sql_generated);
            $toggleSql.show().text('Show');
            $sql.hide();
        } else {
            $sql.text('');
            $toggleSql.hide();
        }

        // Result table
        if (resp.result_data && resp.result_data.length > 0) {
            var columns = Object.keys(resp.result_data[0]);
            var html = '<table class="widefat striped easysql-result-table"><thead><tr>';
            columns.forEach(function (col) {
                html += '<th>' + $('<span>').text(col).html() + '</th>';
            });
            html += '</tr></thead><tbody>';
            resp.result_data.forEach(function (row) {
                html += '<tr>';
                columns.forEach(function (col) {
                    var val = row[col] !== null && row[col] !== undefined ? String(row[col]) : '';
                    html += '<td>' + $('<span>').text(val).html() + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table>';
            $resultTbl.html(html);
        } else {
            $resultTbl.html('<p>' + (resp.answer || 'No results.') + '</p>');
        }

        // Chart
        if (resp.chart_config && typeof Chart !== 'undefined') {
            renderChart(resp.chart_config);
        } else {
            $chartCtnr.hide();
        }

        $result.show();
    }

    // -----------------------------------------------------------------------
    // Chart rendering
    // -----------------------------------------------------------------------

    function renderChart(config) {
        var type = config.type || 'bar';
        var labels = config.labels || [];
        var datasets = config.datasets || [];
        var title = config.title || '';

        if (labels.length === 0 || datasets.length === 0) {
            $chartCtnr.hide();
            return;
        }

        // Destroy previous chart if it exists
        if (renderChart._chart) {
            renderChart._chart.destroy();
        }

        // Default colours for datasets that don't specify one
        var defaultColours = [
            '#4e79a7', '#f28e2b', '#e15759', '#76b7b2',
            '#59a14f', '#edc948', '#b07aa1', '#ff9da7',
        ];

        datasets.forEach(function (ds, i) {
            if (! ds.backgroundColor) {
                ds.backgroundColor = defaultColours[i % defaultColours.length];
            }
            if (! ds.borderColor) {
                ds.borderColor = ds.backgroundColor;
            }
        });

        var ctx = $chartCanvas[0].getContext('2d');
        renderChart._chart = new Chart(ctx, {
            type: type,
            data: {
                labels: labels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: !! title,
                        text: title,
                    },
                    legend: {
                        display: datasets.length > 1,
                    },
                },
            },
        });

        $chartCtnr.show();
    }
}(jQuery));
