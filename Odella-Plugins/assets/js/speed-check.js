document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.odellaSpeedCheck || {};
    var state = {
        checks: [],
        needsAi: false,
        psi: {},
        autoWarm: '',
        running: false
    };

    var runButton = document.getElementById('odella-speed-run');
    var explainButton = document.getElementById('odella-speed-explain');
    var purgeButton = document.getElementById('odella-speed-purge');
    var rows = document.getElementById('odella-speed-check-rows');
    var banner = document.getElementById('odella-speed-banner');
    var notice = document.getElementById('odella-speed-notice');
    var progress = document.getElementById('odella-speed-progress');
    var progressFill = document.getElementById('odella-speed-progress-fill');
    var progressText = document.getElementById('odella-speed-progress-text');
    var progressBar = progress ? progress.querySelector('[role="progressbar"]') : null;
    var ai = document.getElementById('odella-speed-ai');
    var aiTabs = document.getElementById('odella-speed-ai-tabs');
    var aiPanel = document.getElementById('odella-speed-ai-panel');
    var cdn = document.getElementById('odella-speed-cdn');

    var languageNames = {
        en: 'English',
        ka: 'ქართული',
        ru: 'Русский',
        uk: 'Українська',
        tr: 'Türkçe',
        de: 'Deutsch',
        fr: 'Français',
        es: 'Español'
    };

    function setBusy(busy) {
        state.running = busy;
        [runButton, explainButton, purgeButton].forEach(function (button) {
            if (button) {
                button.disabled = busy;
            }
        });
    }

    function showNotice(message, href) {
        if (!notice) {
            return;
        }
        var paragraph = notice.querySelector('p');
        if (!paragraph) {
            return;
        }
        paragraph.textContent = '';
        paragraph.appendChild(document.createTextNode(message + ' '));
        if (href) {
            var link = document.createElement('a');
            link.href = href;
            link.textContent = 'Odella → Settings';
            paragraph.appendChild(link);
        }
        notice.hidden = false;
    }

    function hideNotice() {
        if (notice) {
            notice.hidden = true;
        }
    }

    function showBanner(text) {
        if (!banner) {
            return;
        }
        banner.textContent = text;
        banner.hidden = false;
    }

    function setProgress(done, total, label) {
        if (!progress) {
            return;
        }
        progress.hidden = false;
        var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        if (progressFill) {
            progressFill.style.width = percent + '%';
        }
        if (progressBar) {
            progressBar.setAttribute('aria-valuenow', String(percent));
        }
        if (progressText) {
            progressText.textContent = label;
        }
    }

    function hideProgress() {
        if (progress) {
            progress.hidden = true;
        }
    }

    async function postForm(action, fields, nonce) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        Object.keys(fields).forEach(function (key) {
            var value = fields[key];
            body.set(key, typeof value === 'string' ? value : JSON.stringify(value));
        });
        var response = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        });
        var data = await response.json();
        return data;
    }

    function speedPost(fields) {
        return postForm('odella_speed_check', fields, cfg.nonce || '');
    }

    function indexPost(fields) {
        return postForm('nmj_product_index', fields, cfg.indexNonce || '').then(function (data) {
            if (!data.success) {
                throw new Error((data.data && data.data.message) || 'Request failed');
            }
            return data.data;
        });
    }

    function renderChecks() {
        if (!rows) {
            return;
        }
        rows.textContent = '';
        state.checks.forEach(function (check) {
            var tr = document.createElement('tr');
            var name = document.createElement('th');
            name.scope = 'row';
            name.textContent = check.label || check.id || '';
            var status = document.createElement('td');
            status.className = 'odella-speed-status odella-speed-status-' + (check.status || 'fail');
            status.textContent = check.status || '';
            var detail = document.createElement('td');
            detail.textContent = check.detail || '';
            tr.appendChild(name);
            tr.appendChild(status);
            tr.appendChild(detail);
            rows.appendChild(tr);
        });
    }

    function checkById(id) {
        return state.checks.find(function (check) {
            return check.id === id;
        });
    }

    function cssFailed() {
        var check = checkById('elementor_css');
        return !!(check && check.status === 'fail');
    }

    async function warmCache() {
        var start = await indexPost({ step: 'warm_start' });
        var offset = 0;
        var total = start.total || 0;
        setProgress(offset, total, 'Warming ' + offset + ' / ' + total);
        while (offset < total) {
            var batch = await indexPost({ step: 'warm_batch', token: start.token });
            if (batch.offset <= offset) {
                throw new Error('Warm did not advance');
            }
            offset = batch.offset;
            total = batch.total || total;
            setProgress(offset, total, 'Warming ' + offset + ' / ' + total);
        }
        return indexPost({ step: 'warm_finish', token: start.token });
    }

    async function reindex() {
        var start = await indexPost({ step: 'start' });
        var offset = 0;
        var total = start.total || 0;
        setProgress(offset, total, 'Indexing ' + offset + ' / ' + total);
        while (offset < total) {
            var batch = await indexPost({
                step: 'batch',
                token: start.token,
                offset: String(offset)
            });
            offset = batch.offset;
            setProgress(offset, total, 'Indexing ' + offset + ' / ' + total);
        }
        return indexPost({ step: 'finish', token: start.token });
    }

    async function recheckCache() {
        var data = await speedPost({ substep: 'recheck_cache' });
        if (!data.success || !data.data || !data.data.check) {
            showNotice((data.data && data.data.message) || 'Cache recheck failed.');
            return;
        }
        var updated = data.data.check;
        var replaced = false;
        state.checks = state.checks.map(function (check) {
            if (check.id === updated.id) {
                replaced = true;
                return updated;
            }
            return check;
        });
        if (!replaced) {
            state.checks.unshift(updated);
        }
        renderChecks();
    }

    function renderPsi(wave, payload) {
        var container = document.getElementById(wave === 'desktop' ? 'odella-speed-psi-desktop' : 'odella-speed-psi-mobile');
        if (!container) {
            return;
        }
        container.textContent = '';
        var heading = document.createElement('h2');
        heading.textContent = wave === 'desktop' ? 'PageSpeed desktop' : 'PageSpeed mobile';
        container.appendChild(heading);
        var results = payload && payload.results ? payload.results : [];
        if (!results.length) {
            var empty = document.createElement('p');
            empty.textContent = 'No PageSpeed results.';
            container.appendChild(empty);
            return;
        }
        var grid = document.createElement('div');
        grid.className = 'odella-speed-psi-grid';
        results.forEach(function (result) {
            var card = document.createElement('article');
            card.className = 'odella-speed-card';
            var title = document.createElement('h3');
            title.textContent = result.label || result.url || 'Page';
            card.appendChild(title);
            if (result.error) {
                var error = document.createElement('p');
                error.textContent = result.error;
                card.appendChild(error);
            } else {
                var list = document.createElement('dl');
                [
                    ['Score', result.score],
                    ['FCP', result.fcp],
                    ['LCP', result.lcp],
                    ['TBT', result.tbt],
                    ['CLS', result.cls],
                    ['TTFB', result.ttfb]
                ].forEach(function (pair) {
                    var dt = document.createElement('dt');
                    dt.textContent = pair[0];
                    var dd = document.createElement('dd');
                    dd.textContent = pair[1] === null || typeof pair[1] === 'undefined' ? '—' : String(pair[1]);
                    list.appendChild(dt);
                    list.appendChild(dd);
                });
                card.appendChild(list);
                if (result.audits && result.audits.length) {
                    var audits = document.createElement('ul');
                    result.audits.forEach(function (audit) {
                        var item = document.createElement('li');
                        var value = audit.displayValue ? ' (' + audit.displayValue + ')' : '';
                        item.textContent = (audit.title || '') + value;
                        audits.appendChild(item);
                    });
                    card.appendChild(audits);
                }
            }
            grid.appendChild(card);
        });
        container.appendChild(grid);
    }

    function settingsHref(data) {
        if (data && data.data && data.data.settings_url) {
            return data.data.settings_url;
        }
        return cfg.settingsUrl || '';
    }

    function keyMissing(data) {
        var message = data && data.data && data.data.message ? String(data.data.message) : '';
        return /key is not set/i.test(message);
    }

    async function runPsi() {
        var mobile = await speedPost({ substep: 'psi_wave', wave: 'mobile' });
        if (!mobile.success) {
            showNotice((mobile.data && mobile.data.message) || 'PageSpeed mobile request failed.', keyMissing(mobile) ? settingsHref(mobile) : '');
            if (keyMissing(mobile)) {
                return;
            }
        } else {
            state.psi.mobile = mobile.data;
            if (mobile.data && mobile.data.needs_ai) {
                state.needsAi = true;
            }
            renderPsi('mobile', mobile.data);
        }
        var desktop = await speedPost({ substep: 'psi_wave', wave: 'desktop' });
        if (!desktop.success) {
            showNotice((desktop.data && desktop.data.message) || 'PageSpeed desktop request failed.', keyMissing(desktop) ? settingsHref(desktop) : '');
            return;
        }
        state.psi.desktop = desktop.data;
        if (desktop.data && desktop.data.needs_ai) {
            state.needsAi = true;
        }
        renderPsi('desktop', desktop.data);
    }

    function renderReports(reports) {
        if (!ai || !aiTabs || !aiPanel || !reports) {
            return;
        }
        ai.hidden = false;
        aiTabs.textContent = '';
        aiPanel.textContent = '';
        var codes = Object.keys(reports);
        if (!codes.length) {
            aiPanel.textContent = 'The report did not include a language.';
            return;
        }
        function show(code) {
            aiPanel.textContent = '';
            var report = reports[code] || {};
            var headline = document.createElement('h2');
            headline.textContent = report.headline || '';
            var summary = document.createElement('p');
            summary.textContent = report.summary || '';
            aiPanel.appendChild(headline);
            aiPanel.appendChild(summary);
            ['root_causes', 'actions'].forEach(function (field) {
                var items = Array.isArray(report[field]) ? report[field] : [];
                if (!items.length) {
                    return;
                }
                var list = document.createElement('ul');
                items.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    list.appendChild(li);
                });
                aiPanel.appendChild(list);
            });
            Array.prototype.forEach.call(aiTabs.querySelectorAll('button'), function (button) {
                var active = button.getAttribute('data-lang') === code;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
        codes.forEach(function (code, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button odella-speed-tab';
            button.setAttribute('role', 'tab');
            button.setAttribute('data-lang', code);
            button.textContent = languageNames[code] || code;
            button.addEventListener('click', function () {
                show(code);
            });
            aiTabs.appendChild(button);
            if (index === 0) {
                show(code);
            }
        });
    }

    async function explain() {
        var data = await speedPost({
            substep: 'ai_explain',
            telemetry: {
                checks: state.checks,
                psi: state.psi,
                auto_warm_result: state.autoWarm
            }
        });
        if (!data.success) {
            showNotice((data.data && data.data.message) || 'Gemini request failed.', keyMissing(data) ? settingsHref(data) : '');
            return;
        }
        renderReports(data.data && data.data.reports);
    }

    async function maybeWarm(action) {
        if ((action !== 'warm_cache' && action !== 'reindex_and_warm') || cssFailed()) {
            return;
        }
        showBanner('Culprit found: Cold cache after recent changes. Rebuilding index and warming cache automatically…');
        if (action === 'reindex_and_warm') {
            state.autoWarm = 'reindex_and_warm';
            await reindex();
        } else {
            state.autoWarm = 'warm_cache';
        }
        await warmCache();
        await recheckCache();
    }

    async function runAll() {
        if (state.running) {
            return;
        }
        setBusy(true);
        hideNotice();
        if (banner) {
            banner.hidden = true;
        }
        if (cdn) {
            cdn.hidden = true;
        }
        try {
            var data = await speedPost({ substep: 'deterministic' });
            if (!data.success || !data.data) {
                showNotice((data.data && data.data.message) || 'Speed Check failed.');
                return;
            }
            state.checks = Array.isArray(data.data.checks) ? data.data.checks : [];
            state.needsAi = !!data.data.needs_ai;
            state.psi = {};
            state.autoWarm = '';
            renderChecks();
            try {
                await maybeWarm(data.data.recommended_action || 'none');
            } catch (error) {
                showNotice(error && error.message ? error.message : 'Cache warm failed.');
            }
            try {
                await runPsi();
            } catch (error) {
                showNotice(error && error.message ? error.message : 'PageSpeed request failed.');
            }
            if (state.needsAi) {
                try {
                    await explain();
                } catch (error) {
                    showNotice(error && error.message ? error.message : 'Gemini request failed.');
                }
            }
        } catch (error) {
            showNotice(error && error.message ? error.message : 'Speed Check failed.');
        } finally {
            hideProgress();
            setBusy(false);
        }
    }

    async function purgeAndRebuild() {
        if (state.running) {
            return;
        }
        var confirmed = window.confirm('Purging clears all cached pages across the site and takes ~5–8 minutes to re-warm. Continue?');
        if (!confirmed) {
            return;
        }
        setBusy(true);
        hideNotice();
        try {
            var purged = await speedPost({ substep: 'purge_only' });
            if (!purged.success) {
                showNotice((purged.data && purged.data.message) || 'Purge failed.');
                return;
            }
            if (cdn) {
                var cdnResult = purged.data && purged.data.cdn ? purged.data.cdn : null;
                if (cdnResult && cdnResult.attempted && cdnResult.ok) {
                    cdn.textContent = cdnResult.message || 'Hostinger Edge CDN purged.';
                } else if (cdnResult && cdnResult.attempted && !cdnResult.ok) {
                    cdn.textContent = (cdnResult.message || 'Hostinger CDN purge failed.') + ' Manual fallback: hPanel → Websites → odella.ge → Performance → CDN → Purge all';
                } else {
                    cdn.textContent = 'hPanel → Websites → odella.ge → Performance → CDN → Purge all';
                }
                cdn.hidden = false;
            }
            state.autoWarm = 'reindex_and_warm';
            await reindex();
            await warmCache();
        } catch (error) {
            showNotice(error && error.message ? error.message : 'Purge and rebuild failed.');
        } finally {
            hideProgress();
            setBusy(false);
        }
    }

    if (runButton) {
        runButton.addEventListener('click', function () {
            runAll();
        });
    }
    if (explainButton) {
        explainButton.addEventListener('click', function () {
            if (state.running) {
                return;
            }
            setBusy(true);
            explain().catch(function (error) {
                showNotice(error && error.message ? error.message : 'Gemini request failed.');
            }).finally(function () {
                setBusy(false);
            });
        });
    }
    if (purgeButton) {
        purgeButton.addEventListener('click', function () {
            purgeAndRebuild();
        });
    }
    if (cfg.autorun === true || cfg.autorun === 1 || cfg.autorun === '1') {
        runAll();
    }
});
