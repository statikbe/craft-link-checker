/**
 * Links panel in an entry’s sidebar (src/templates/_panel/panel.twig): loads its list after the page, collapses and
 * remembers that in the browser, for every entry, and reloads its list after the editor saves a draft (an autosave) or a
 * column is saved in a slideout. Reloads wait until the editor pauses (10 seconds; a minute while the panel is collapsed),
 * a newer reload cancels one that's still running, and expanding a panel with changes reloads it at once. Every panel is set
 * up on its own, also one in a slideout next to the page's.
 */
(function($) {
    const KEY = 'linkChecker.panel.collapsed';
    const DELAY = 10000;
    const DELAY_COLLAPSED = 60000;

    const init = (id) => {
        const panel = document.querySelector(`[data-lc-panel="${id}"]`);
        if (!panel || panel.dataset.lcReady) {
            return;
        }
        panel.dataset.lcReady = '1';
        const toggle = panel.querySelector('.lc-panel__toggle');
        const body = panel.querySelector('.lc-panel__body');
        const summary = panel.querySelector('[data-panel-summary]');
        let elementId = panel.dataset.elementId;
        let timer = null;
        let controller = null;
        let stale = false;

        // "2 broken links · 1 warning" next to the title, so it's visible while the panel is collapsed
        const updateSummary = () => {
            const counts = body.querySelector('[data-panel-counts]');
            const broken = parseInt(counts?.dataset.broken ?? '0', 10);
            const warnings = parseInt(counts?.dataset.warnings ?? '0', 10);
            const parts = [];
            if (broken) {
                parts.push(Craft.t('link-checker', '{count, plural, =1{# broken link} other{# broken links}}', {count: broken}));
            }
            if (warnings) {
                parts.push(Craft.t('link-checker', '{count, plural, =1{# warning} other{# warnings}}', {count: warnings}));
            }
            summary.textContent = parts.join(' · ');
            summary.classList.toggle('lc-panel__summary--broken', broken > 0);
        };

        const collapsed = () => panel.classList.contains('is-collapsed');

        // The element editor is created after this script runs: listen on the class, for the editor of this panel's form
        const editorOf = () => $(panel).closest('form').data('elementEditor');

        const load = () => {
            clearTimeout(timer);
            timer = null;
            if (!document.body.contains(panel)) {
                return;
            }
            // A newer list is asked for: the one still loading is outdated
            controller?.abort();
            const request = controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            stale = false;
            Craft.sendActionRequest('POST', 'link-checker/panel/list', {
                data: {elementId: elementId ?? editorOf()?.settings.elementId, siteId: panel.dataset.siteId},
                signal: request?.signal,
            }).then(({data}) => {
                body.innerHTML = data.html;
                updateSummary();
            }).catch(() => {}).finally(() => {
                if (controller === request) {
                    controller = null;
                }
            });
        };

        // After a change: once the editor pauses
        const schedule = () => {
            stale = true;
            clearTimeout(timer);
            timer = setTimeout(load, collapsed() ? DELAY_COLLAPSED : DELAY);
        };

        toggle.addEventListener('click', () => {
            const isCollapsed = panel.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            try {
                localStorage.setItem(KEY, isCollapsed ? '1' : '0');
            } catch (e) {}
            if (!isCollapsed && stale) {
                load();
            }
        });

        // Ignore a link everywhere (Utilities → Link Checker lists the ignored links)
        body.addEventListener('click', (ev) => {
            const button = ev.target.closest('[data-ignore]');
            if (!button) {
                return;
            }
            button.disabled = true;
            Craft.sendActionRequest('POST', 'link-checker/utility/ignore', {
                data: {url: button.dataset.ignore, elementId: button.dataset.elementId, siteId: button.dataset.siteId},
            }).then(() => load()).catch(() => {
                button.disabled = false;
            });
        });

        Garnish.on(Craft.ElementEditor, 'afterSaveDraft', (ev) => {
            if (ev.target === editorOf()) {
                elementId = ev.response?.data?.elementId ?? ev.response?.elementId ?? ev.target.settings?.elementId ?? elementId;
                schedule();
            }
        });
        if (Craft.CpScreenSlideout) {
            Garnish.on(Craft.CpScreenSlideout, 'submit', () => schedule());
        }

        // The first list, once the page has loaded
        if (document.readyState === 'complete') {
            setTimeout(load, 0);
        } else {
            window.addEventListener('load', () => load(), {once: true});
        }
    };

    window.LinkCheckerPanel = {init};
    $(() => {
        (window.LinkCheckerPanels || []).forEach(init);
        window.LinkCheckerPanels = [];
    });
})(jQuery);
