/* global wpApiSettings, easysqlAskV4, marked, Chart */

(function () {
    'use strict';

    var i18n = (easysqlAskV4 && easysqlAskV4.i18n) || {};

    function t(key) {
        return i18n[key] || key;
    }

    // -----------------------------------------------------------------------
    // Element cache (all v4-specific selectors)
    // -----------------------------------------------------------------------

    var els = {
        question:       document.getElementById('easysql-ask4-question'),
        submit:         document.getElementById('easysql-ask4-submit'),
        spinner:        document.querySelector('.easysql-ask4-spinner'),
        notice:         document.getElementById('easysql-ask4-error'),
        noticeText:     document.getElementById('easysql-ask4-error-text'),
        retryBtn:       document.getElementById('easysql-ask4-retry-btn'),
        empty:          document.getElementById('easysql-ask4-empty'),
        suggestions:    document.getElementById('easysql-ask4-suggestions'),
        loading:        document.getElementById('easysql-ask4-loading'),
        loadingElapsed: document.getElementById('easysql-ask4-loading-elapsed'),
        answerBox:      document.getElementById('easysql-ask4-answer'),
        answerQuestion: document.getElementById('easysql-ask4-answer-question'),
        answerMeta:     document.getElementById('easysql-ask4-answer-meta'),
        answerBody:     document.getElementById('easysql-ask4-answer-body'),
        dataBox:        document.getElementById('easysql-ask4-data'),
        rowCount:       document.getElementById('easysql-ask4-row-count'),
        pageSize:       document.getElementById('easysql-ask4-page-size'),
        exportBtn:      document.getElementById('easysql-ask4-export'),
        table:          document.getElementById('easysql-ask4-table'),
        firstPage:      document.getElementById('easysql-ask4-first'),
        prevPage:       document.getElementById('easysql-ask4-prev'),
        nextPage:       document.getElementById('easysql-ask4-next'),
        lastPage:       document.getElementById('easysql-ask4-last'),
        currentPage:    document.getElementById('easysql-ask4-current-page'),
        totalPages:     document.getElementById('easysql-ask4-total-pages'),
        pagination:     document.getElementById('easysql-ask4-pagination'),
        chartBox:       document.getElementById('easysql-ask4-chart'),
        chartCanvas:    document.getElementById('easysql-ask4-chart-canvas'),
        chartPieCanvas: document.getElementById('easysql-ask4-chart-pie-canvas'),
        chartTabs:      document.querySelectorAll('.easysql-ask4-chart-tab'),
        sqlBox:         document.getElementById('easysql-ask4-sql'),
        sqlPre:         document.getElementById('easysql-ask4-sql-pre'),
        sqlCopy:        document.getElementById('easysql-ask4-sql-copy'),
        sqlCopyLabel:   document.getElementById('easysql-ask4-sql-copy-label'),
        recentList:     document.getElementById('easysql-ask4-recent-list')
    };

    if (! els.submit || ! els.question) {
        return;
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    var state = {
        connectorId: easysqlAskV4.connector_id || null,
        isSubmitting: false,
        reqId: 0,
        result: null,
        tablePage: 1,
        tablePageSize: 50,
        sortColumn: null,
        sortDirection: 'asc',
        sqlRaw: '',
        chartType: 'bar',
        chart: null,
        pieChart: null,
        loadingTimer: null,
        lastQuestion: ''
    };

    // -----------------------------------------------------------------------
    // Pure helpers
    // -----------------------------------------------------------------------

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        return str.replace(/%(\d+)\$s/g, function (match, index) {
            return args[Number(index) - 1] !== undefined ? String(args[Number(index) - 1]) : match;
        });
    }

    function slugify(str) {
        return String(str).slice(0, 40).replace(/[^a-zA-Z0-9]/g, '_');
    }

    function formatRelative(value) {
        if (! value) {
            return '';
        }
        var raw = String(value);
        // Datetimes without an explicit timezone come from the backend as
        // naive UTC; treat them as UTC instead of the browser's local zone.
        var d;
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)) {
            d = new Date(raw + 'Z');
        } else {
            d = new Date(raw);
        }
        if (isNaN(d.getTime())) {
            return '';
        }
        var seconds = Math.round((Date.now() - d.getTime()) / 1000);
        if (seconds < 60) {
            return seconds + 's ago';
        }
        var minutes = Math.round(seconds / 60);
        if (minutes < 60) {
            return minutes + 'm ago';
        }
        var hours = Math.round(minutes / 60);
        if (hours < 24) {
            return hours + 'h ago';
        }
        var days = Math.round(hours / 24);
        return days + 'd ago';
    }

    function show(el) {
        if (el) {
            el.hidden = false;
        }
    }

    function hide(el) {
        if (el) {
            el.hidden = true;
        }
    }

    function setSpinner(active) {
        if (! els.spinner) {
            return;
        }
        if (active) {
            els.spinner.classList.add('is-active');
        } else {
            els.spinner.classList.remove('is-active');
        }
    }

    // -----------------------------------------------------------------------
    // SQL highlighter
    // -----------------------------------------------------------------------

    var SQL_KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER',
        'CROSS', 'FULL', 'NATURAL', 'ON', 'AND', 'OR', 'NOT', 'IN', 'LIKE',
        'ILIKE', 'BETWEEN', 'EXISTS', 'IS', 'NULL', 'AS', 'BY', 'GROUP',
        'ORDER', 'LIMIT', 'OFFSET', 'HAVING', 'INSERT', 'INTO', 'VALUES',
        'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'ALTER', 'DROP',
        'INDEX', 'VIEW', 'TRUNCATE', 'DISTINCT', 'CASE', 'WHEN', 'THEN',
        'ELSE', 'END', 'UNION', 'ALL', 'INTERSECT', 'EXCEPT', 'ASC', 'DESC',
        'WITH', 'RECURSIVE', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES',
        'CONSTRAINT', 'TRUE', 'FALSE', 'TOP', 'IF', 'WHILE', 'DO', 'FOR',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'RETURNING', 'OVER', 'PARTITION',
        'WINDOW', 'FILTER'
    ];

    var SQL_FUNCTIONS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE', 'NULLIF',
        'DATE_FORMAT', 'DATE_TRUNC', 'DATE_PART', 'EXTRACT', 'NOW',
        'CURRENT_DATE', 'CURRENT_TIMESTAMP', 'CURRENT_TIME', 'DATE_SUB',
        'DATE_ADD', 'DATEDIFF', 'DATE_DIFF', 'UPPER', 'LOWER', 'LENGTH',
        'CHAR_LENGTH', 'SUBSTRING', 'SUBSTR', 'TRIM', 'CONCAT', 'CONCAT_WS',
        'REPLACE', 'CAST', 'CONVERT', 'ROUND', 'FLOOR', 'CEIL', 'CEILING',
        'ABS', 'POWER', 'SQRT', 'MOD', 'STRING_AGG', 'GROUP_CONCAT',
        'LISTAGG', 'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'LAG', 'LEAD',
        'FIRST_VALUE', 'LAST_VALUE', 'NTH_VALUE', 'NTILE', 'JSON_EXTRACT',
        'JSON_UNQUOTE', 'JSON_QUERY', 'JSON_VALUE', 'INTERVAL', 'GREATEST',
        'LEAST', 'RANDOM', 'RAND', 'SYSDATE', 'GETDATE', 'CURDATE',
        'UTC_TIMESTAMP', 'LOCALTIME', 'LOCALTIMESTAMP'
    ];

    var keywordSet = {};
    var functionSet = {};
    SQL_KEYWORDS.forEach(function (w) { keywordSet[w] = true; });
    SQL_FUNCTIONS.forEach(function (w) { functionSet[w] = true; });

    function highlightSql(raw) {
        var escaped = String(raw)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        var parts = [];
        var i = 0;

        while (i < escaped.length) {
            var remaining = escaped.substring(i);

            var cmt = /^--[^\n]*/.exec(remaining);
            if (cmt) {
                parts.push('<span class="easysql4-sql-cmt">' + cmt[0] + '</span>');
                i += cmt[0].length;
                continue;
            }

            var str = /^'(?:[^'\\]|\\.)*'/.exec(remaining);
            if (str) {
                parts.push('<span class="easysql4-sql-str">' + str[0] + '</span>');
                i += str[0].length;
                continue;
            }

            var num = /^\b\d+(?:\.\d+)?\b/.exec(remaining);
            if (num && (i === 0 || /\W/.test(escaped[i - 1]))) {
                parts.push('<span class="easysql4-sql-num">' + num[0] + '</span>');
                i += num[0].length;
                continue;
            }

            var word = /^[a-zA-Z_]\w*/.exec(remaining);
            if (word) {
                var w = word[0];
                var upper = w.toUpperCase();

                if (functionSet[upper]) {
                    var after = escaped.substring(i + w.length).trimStart();
                    if (after.startsWith('(')) {
                        parts.push('<span class="easysql4-sql-fn">' + w + '</span>');
                        i += w.length;
                        continue;
                    }
                }

                if (keywordSet[upper]) {
                    parts.push('<span class="easysql4-sql-kw">' + w + '</span>');
                    i += w.length;
                    continue;
                }

                parts.push(w);
                i += w.length;
                continue;
            }

            parts.push(escaped[i]);
            i++;
        }

        return parts.join('');
    }

    function prettyPrintSql(sql) {
        if (! sql) {
            return '';
        }
        var BREAK_BEFORE = [
            '\\bFROM\\b', '\\bWHERE\\b', '\\bGROUP BY\\b', '\\bORDER BY\\b',
            '\\bHAVING\\b', '\\bLIMIT\\b', '\\bOFFSET\\b', '\\bUNION(?: ALL)?\\b',
            '\\bLEFT JOIN\\b', '\\bRIGHT JOIN\\b', '\\bINNER JOIN\\b',
            '\\bOUTER JOIN\\b', '\\bJOIN\\b', '\\bON\\b'
        ];
        var collapsed = sql.replace(/\s+/g, ' ').trim();
        var formatted = collapsed;
        BREAK_BEFORE.forEach(function (pat) {
            formatted = formatted.replace(
                new RegExp('\\s*(' + pat + ')', 'gi'),
                '\n$1'
            );
        });
        return formatted;
    }

    // -----------------------------------------------------------------------
    // Markdown (marked) with raw-HTML escaping
    // -----------------------------------------------------------------------

    if (typeof marked !== 'undefined') {
        marked.use({
            renderer: {
                html: function (html) {
                    return escapeHtml(typeof html === 'string' ? html : '');
                }
            }
        });
    }

    // -----------------------------------------------------------------------
    // Loading / error / result rendering
    // -----------------------------------------------------------------------

    function stopLoadingTimer() {
        if (state.loadingTimer) {
            clearInterval(state.loadingTimer);
            state.loadingTimer = null;
        }
        if (els.loadingElapsed) {
            els.loadingElapsed.textContent = '';
        }
    }

    function renderLoading() {
        hide(els.empty);
        hide(els.answerBox);
        hide(els.dataBox);
        hide(els.chartBox);
        hide(els.sqlBox);
        hideNotice();
        show(els.loading);

        if (els.loadingElapsed) {
            els.loadingElapsed.textContent = '';
            var startedAt = Date.now();
            if (state.loadingTimer) {
                clearInterval(state.loadingTimer);
            }
            state.loadingTimer = setInterval(function () {
                if (! els.loading || els.loading.hidden) {
                    clearInterval(state.loadingTimer);
                    state.loadingTimer = null;
                    return;
                }
                var seconds = Math.round((Date.now() - startedAt) / 1000);
                els.loadingElapsed.textContent = seconds + 's';
            }, 500);
        }
    }

    function showNotice(message) {
        if (els.noticeText) {
            els.noticeText.textContent = message;
        }
        show(els.notice);
    }

    function hideNotice() {
        hide(els.notice);
    }

    function renderError(message, withRetry) {
        stopLoadingTimer();
        hide(els.loading);
        hide(els.answerBox);
        hide(els.dataBox);
        hide(els.chartBox);
        hide(els.sqlBox);
        showNotice(message);
        if (els.retryBtn) {
            els.retryBtn.hidden = ! withRetry;
        }
    }

    function renderResults(resp) {
        state.result = resp;
        state.tablePage = 1;
        state.sortColumn = null;
        state.sortDirection = 'asc';
        state.pieChart = null; // new result → rebuild the pie chart too.
        stopLoadingTimer();
        hideNotice();

        hide(els.loading);
        hide(els.empty);

        if (els.answerQuestion) {
            els.answerQuestion.textContent = resp.question || '';
        }
        if (els.answerMeta) {
            var parts = [];
            if (resp.connectorName) {
                parts.push(resp.connectorName);
            }
            var when = formatRelative(resp.date);
            if (when) {
                parts.push(when);
            }
            els.answerMeta.textContent = parts.join(' · ');
        }

        renderAnswer(resp);
        renderTable();
        renderSql();
        renderChart();

        show(els.answerBox);

        loadRecentQueries();
    }

    // -----------------------------------------------------------------------
    // Answer (markdown)
    // -----------------------------------------------------------------------

    function renderAnswer(resp) {
        var answerMarkdown = resp.answer || '';
        if (typeof marked !== 'undefined') {
            els.answerBody.innerHTML = marked.parse(answerMarkdown, { breaks: true });
        } else {
            els.answerBody.textContent = answerMarkdown;
        }
    }

    // -----------------------------------------------------------------------
    // Table (sort, pagination, CSV)
    // -----------------------------------------------------------------------

    function tableColumns(rows) {
        var keys = [];
        rows.forEach(function (row) {
            Object.keys(row).forEach(function (k) {
                if (keys.indexOf(k) === -1) {
                    keys.push(k);
                }
            });
        });
        return keys;
    }

    function compareCell(a, b) {
        if (a === null || a === undefined) {
            return b === null || b === undefined ? 0 : 1;
        }
        if (b === null || b === undefined) {
            return -1;
        }
        if (typeof a === 'number' && typeof b === 'number') {
            return a - b;
        }
        var an = Number(a);
        var bn = Number(b);
        if (!isNaN(an) && !isNaN(bn)) {
            return an - bn;
        }
        return String(a).localeCompare(
            String(b),
            document.documentElement.lang || 'en',
            { numeric: true }
        );
    }

    function sortedRows(rows) {
        if (! state.sortColumn) {
            return rows;
        }
        var col = state.sortColumn;
        var dir = state.sortDirection === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            return compareCell(a[col], b[col]) * dir;
        });
    }

    function formatCell(value) {
        if (value === null || value === undefined) {
            return '';
        }
        if (typeof value === 'object') {
            try {
                return JSON.stringify(value);
            } catch (_) {
                return String(value);
            }
        }
        return String(value);
    }

    function renderTable() {
        var rows = (state.result && state.result.result_data) || [];
        var total = rows.length;

        if (total === 0) {
            hide(els.dataBox);
            return;
        }
        show(els.dataBox);

        var columns = tableColumns(rows);
        var pageSize = state.tablePageSize;
        var sorted = sortedRows(rows);
        var totalPages = Math.max(1, Math.ceil(sorted.length / pageSize));
        var page = Math.min(Math.max(1, state.tablePage), totalPages);
        state.tablePage = page;

        var start = (page - 1) * pageSize;
        var end = Math.min(start + pageSize, sorted.length);

        var headHtml = '<tr>';
        columns.forEach(function (col) {
            var isSorted = state.sortColumn === col;
            var sortClass = isSorted ? ' is-sorted-' + state.sortDirection : '';
            var indicator = isSorted
                ? (state.sortDirection === 'asc' ? '\u25b2' : '\u25bc')
                : '\u21f5';
            headHtml += '<th scope="col" class="is-sortable' + sortClass + '"'
                + ' data-col="' + escapeHtml(col) + '"'
                + ' tabindex="0" role="button">'
                + escapeHtml(col)
                + ' <span class="easysql-ask4-sort-indicator" aria-hidden="true">' + indicator + '</span>'
                + '</th>';
        });
        headHtml += '</tr>';
        els.table.querySelector('thead').innerHTML = headHtml;

        Array.prototype.forEach.call(
            els.table.querySelectorAll('thead th.is-sortable'),
            function (th) {
                th.addEventListener('click', function () {
                    toggleSort(th.getAttribute('data-col'));
                });
                th.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleSort(th.getAttribute('data-col'));
                    }
                });
            }
        );

        var bodyHtml = '';
        for (var r = start; r < end; r++) {
            bodyHtml += '<tr>';
            columns.forEach(function (col) {
                bodyHtml += '<td>' + escapeHtml(formatCell(sorted[r][col])) + '</td>';
            });
            bodyHtml += '</tr>';
        }
        els.table.querySelector('tbody').innerHTML = bodyHtml;

        els.rowCount.textContent = sprintf(t('showing_rows'), start + 1, end, sorted.length);

        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        if (els.currentPage) {
            els.currentPage.value = String(state.tablePage);
        }
        if (els.totalPages) {
            els.totalPages.textContent = String(totalPages);
        }
        if (totalPages > 1) {
            show(els.pagination);
            if (els.firstPage) els.firstPage.disabled = state.tablePage <= 1;
            if (els.prevPage)  els.prevPage.disabled  = state.tablePage <= 1;
            if (els.nextPage)  els.nextPage.disabled  = state.tablePage >= totalPages;
            if (els.lastPage)  els.lastPage.disabled  = state.tablePage >= totalPages;
        } else {
            hide(els.pagination);
        }
    }

    function toggleSort(col) {
        if (state.sortColumn === col) {
            state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortColumn = col;
            state.sortDirection = 'asc';
        }
        state.tablePage = 1;
        renderTable();
    }

    function gotoPage(p) {
        var totalPages = (els.totalPages && parseInt(els.totalPages.textContent, 10)) || 1;
        if (isNaN(p) || p < 1) {
            p = 1;
        }
        if (p > totalPages) {
            p = totalPages;
        }
        state.tablePage = p;
        renderTable();
    }

    if (els.firstPage) {
        els.firstPage.addEventListener('click', function () { gotoPage(1); });
    }
    if (els.prevPage) {
        els.prevPage.addEventListener('click', function () { gotoPage(state.tablePage - 1); });
    }
    if (els.nextPage) {
        els.nextPage.addEventListener('click', function () { gotoPage(state.tablePage + 1); });
    }
    if (els.lastPage) {
        els.lastPage.addEventListener('click', function () {
            var totalPages = (els.totalPages && parseInt(els.totalPages.textContent, 10)) || 1;
            gotoPage(totalPages);
        });
    }
    if (els.currentPage) {
        els.currentPage.addEventListener('change', function () {
            var p = parseInt(els.currentPage.value, 10);
            if (!isNaN(p)) {
                gotoPage(p);
            }
        });
        els.currentPage.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var p = parseInt(els.currentPage.value, 10);
                if (!isNaN(p)) {
                    gotoPage(p);
                }
            }
        });
    }

    if (els.pageSize) {
        els.pageSize.value = String(state.tablePageSize);
        els.pageSize.addEventListener('change', function () {
            var n = parseInt(els.pageSize.value, 10);
            if (!isNaN(n) && n > 0) {
                state.tablePageSize = n;
                state.tablePage = 1;
                renderTable();
            }
        });
    }

    function buildCsv() {
        var rows = (state.result && state.result.result_data) || [];
        if (rows.length === 0) {
            return '';
        }
        var keys = tableColumns(rows);
        var csvRows = [keys.join(',')];
        rows.forEach(function (row) {
            csvRows.push(keys.map(function (k) {
                var val = formatCell(row[k]);
                if (val.indexOf(',') !== -1 || val.indexOf('"') !== -1 || val.indexOf('\n') !== -1) {
                    return '"' + val.replace(/"/g, '""') + '"';
                }
                return val;
            }).join(','));
        });
        return csvRows.join('\n');
    }

    if (els.exportBtn) {
        els.exportBtn.addEventListener('click', function () {
            var csv = buildCsv();
            if (! csv) {
                return;
            }
            var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = slugify(state.result.question) + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        });
    }

    // -----------------------------------------------------------------------
    // SQL postbox
    // -----------------------------------------------------------------------

    function renderSql() {
        var sqlText = (state.result && state.result.sql_generated) || '';
        if (! sqlText) {
            state.sqlRaw = '';
            hide(els.sqlBox);
            return;
        }
        state.sqlRaw = sqlText;
        els.sqlPre.innerHTML = highlightSql(prettyPrintSql(state.sqlRaw));
        show(els.sqlBox);
    }

    if (els.sqlCopy) {
        els.sqlCopy.addEventListener('click', function () {
            var sqlText = state.sqlRaw;
            if (! sqlText) {
                return;
            }
            function copied() {
                var original = els.sqlCopyLabel.textContent;
                els.sqlCopyLabel.textContent = t('sql_copied');
                setTimeout(function () {
                    els.sqlCopyLabel.textContent = original;
                }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(sqlText).then(copied, function () {
                    fallbackCopy(sqlText, copied);
                });
            } else {
                fallbackCopy(sqlText, copied);
            }
        });
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (_) { /* ignore */ }
        document.body.removeChild(ta);
    }

    // -----------------------------------------------------------------------
    // Chart postbox
    // -----------------------------------------------------------------------

    function chartIsUsable(config) {
        if (! config || typeof Chart === 'undefined') {
            return false;
        }
        if (config.labels && config.labels.length > 0 && config.datasets && config.datasets.length > 0) {
            return true;
        }
        return !!(config.x && config.y);
    }

    function chartDataFromRows(config, rows) {
        if (config.labels && config.datasets) {
            return { labels: config.labels, datasets: config.datasets };
        }
        if (! config.x || ! config.y || ! rows || rows.length === 0) {
            return null;
        }
        var labels = rows.map(function (row) {
            var v = row[config.x];
            return String(v !== null && v !== undefined ? v : '');
        });
        var values = rows.map(function (row) {
            var v = row[config.y];
            return v === null || v === undefined ? 0 : Number(v);
        });
        return {
            labels: labels,
            datasets: [{ label: String(config.y), data: values }]
        };
    }

    function renderChart() {
        var config = state.result && state.result.chart_config;
        if (! chartIsUsable(config)) {
            hide(els.chartBox);
            return;
        }
        show(els.chartBox);

        var built = chartDataFromRows(config, (state.result && state.result.result_data) || []);
        if (! built || built.labels.length === 0 || built.datasets.length === 0) {
            return;
        }

        var defaultColours = [
            '#4e79a7', '#f28e2b', '#e15759', '#76b7b2',
            '#59a14f', '#edc948', '#b07aa1', '#ff9da7'
        ];

        var safeDatasets = JSON.parse(JSON.stringify(built.datasets));
        safeDatasets.forEach(function (ds, i) {
            if (! ds.backgroundColor) {
                ds.backgroundColor = defaultColours[i % defaultColours.length];
            }
            if (! ds.borderColor) {
                ds.borderColor = ds.backgroundColor;
            }
        });

        // Main chart: bar/line toggle.
        var mainType = state.chartType || 'bar';
        if (state.chart) {
            state.chart.destroy();
            state.chart = null;
        }
        var ctx = els.chartCanvas.getContext('2d');
        state.chart = new Chart(ctx, {
            type: mainType,
            data: {
                labels: built.labels,
                datasets: safeDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: !! config.title, text: config.title || '' },
                    legend: { display: safeDatasets.length > 1 }
                }
            }
        });

        // Side pie chart: only (re)built when the result changes.
        if (! state.pieChart) {
            renderPieChart(built, defaultColours);
        }
    }

    function renderPieChart(built, defaultColours) {
        var pieDatasets = JSON.parse(JSON.stringify(built.datasets.slice(0, 1)));
        pieDatasets.forEach(function (ds) {
            if (Array.isArray(ds.backgroundColor)) {
                return;
            }
            ds.backgroundColor = ds.data.map(function (_, i) {
                return defaultColours[i % defaultColours.length];
            });
            ds.borderColor = '#ffffff';
            ds.borderWidth = 1;
        });
        if (state.pieChart) {
            state.pieChart.destroy();
            state.pieChart = null;
        }
        var pieCtx = els.chartPieCanvas.getContext('2d');
        state.pieChart = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: built.labels,
                datasets: pieDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: true, position: 'bottom' }
                }
            }
        });
    }

    Array.prototype.forEach.call(els.chartTabs, function (tab) {
        tab.addEventListener('click', function () {
            var type = tab.getAttribute('data-chart-type');
            if (! type || type === state.chartType) {
                return;
            }
            state.chartType = type;
            Array.prototype.forEach.call(els.chartTabs, function (other) {
                other.classList.toggle('is-active', other === tab);
            });
            if (els.chartBox && ! els.chartBox.hidden) {
                renderChart();
            }
        });
    });

    // -----------------------------------------------------------------------
    // Recent queries (side column)
    // -----------------------------------------------------------------------

    function loadRecentQueries() {
        if (! state.connectorId) {
            return;
        }
        fetch(wpApiSettings.root + 'easysql/v1/queries?connector_id='
            + encodeURIComponent(state.connectorId) + '&page=1&per_page=10', {
            headers: { 'X-WP-Nonce': wpApiSettings.nonce }
        })
            .then(function (resp) {
                return resp.json().then(function (data) {
                    return { ok: resp.ok, data: data };
                });
            })
            .then(function (res) {
                if (! res.ok || ! els.recentList) {
                    return;
                }
                var items = res.data.items || [];
                renderRecent(items);
            })
            .catch(function () { /* history is optional */ });
    }

    function renderRecent(items) {
        if (! els.recentList) {
            return;
        }
        if (items.length === 0) {
            return;
        }

        // Group repeated questions, keeping the most recent occurrence's date.
        var groups = {};
        items.forEach(function (item) {
            var q = item.question || '';
            if (! q) {
                return;
            }
            if (! groups[q]) {
                groups[q] = { question: q, created_at: item.created_at, count: 0 };
            }
            groups[q].count += 1;
            if (! groups[q].created_at) {
                groups[q].created_at = item.created_at;
            }
        });

        var html = '';
        Object.keys(groups).forEach(function (q) {
            var g = groups[q];
            var when = formatRelative(g.created_at);
            var countLabel = g.count > 1 ? ' · ' + g.count + 'x' : '';
            html += '<li><button type="button" class="easysql-ask4-recent-btn" data-question="'
                + escapeHtml(g.question) + '">'
                + '<span class="easysql-ask4-recent-question">' + escapeHtml(g.question) + '</span>'
                + (when || countLabel ? '<span class="easysql-ask4-recent-meta">'
                    + escapeHtml(when + countLabel) + '</span>' : '')
                + '</button></li>';
        });
        els.recentList.innerHTML = html;

        Array.prototype.forEach.call(
            els.recentList.querySelectorAll('.easysql-ask4-recent-btn'),
            function (btn) {
                btn.addEventListener('click', function () {
                    var q = btn.getAttribute('data-question');
                    if (q) {
                        els.question.value = q;
                        els.question.focus();
                        submitQuestion(q);
                    }
                });
            }
        );
    }

    // -----------------------------------------------------------------------
    // Suggestions (fill + submit)
    // -----------------------------------------------------------------------

    if (els.suggestions) {
        els.suggestions.addEventListener('click', function (e) {
            var btn = e.target.closest('.easysql-ask4-suggestion');
            if (! btn) {
                return;
            }
            var q = btn.getAttribute('data-question');
            if (q) {
                els.question.value = q;
                els.question.focus();
                submitQuestion(q);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Submit
    // -----------------------------------------------------------------------

    function submitQuestion(pendingQuestion) {
        var question = pendingQuestion || els.question.value.trim();
        if (! question) {
            renderError(t('empty_question'), false);
            return;
        }
        if (! state.connectorId) {
            renderError(t('no_connector'), false);
            return;
        }
        if (state.isSubmitting) {
            return;
        }

        state.isSubmitting = true;
        state.lastQuestion = question;
        var reqId = ++state.reqId;

        els.submit.disabled = true;
        setSpinner(true);
        renderLoading();

        fetch(wpApiSettings.root + 'easysql/v1/query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce
            },
            body: JSON.stringify({
                connector_id: state.connectorId,
                question: question
            })
        })
            .then(function (resp) {
                return resp.json().then(function (data) {
                    return { ok: resp.ok, data: data };
                });
            })
            .then(function (res) {
                if (reqId !== state.reqId) {
                    return;
                }
                if (! res.ok) {
                    renderError(res.data.error || t('request_failed'), true);
                    return;
                }
                renderResults(res.data);
            })
            .catch(function () {
                if (reqId !== state.reqId) {
                    return;
                }
                renderError(t('request_failed'), true);
            })
            .finally(function () {
                if (reqId !== state.reqId) {
                    return;
                }
                state.isSubmitting = false;
                els.submit.disabled = false;
                setSpinner(false);
                els.question.focus();
            });
    }

    if (els.retryBtn) {
        els.retryBtn.addEventListener('click', function () {
            hideNotice();
            submitQuestion(state.lastQuestion);
        });
    }

    els.submit.addEventListener('click', function () {
        submitQuestion();
    });

    els.question.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && ! e.shiftKey) {
            e.preventDefault();
            submitQuestion();
        }
    });

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------

    if (state.connectorId) {
        loadRecentQueries();
    }
}());
