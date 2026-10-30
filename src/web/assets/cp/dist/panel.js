/**
 * Links panel in an entry’s sidebar (src/templates/_panel/panel.twig): collapses and remembers
 * that in the browser, for every entry, and reloads its list after the editor saves a draft (an autosave), or after a column
 * is saved in a slideout.
 */
(function($) {
    const KEY = 'linkChecker.panel.collapsed';

    $(() => {
        const panel = document.getElementById('lc-panel');
        if (!panel) {
            return;
        }
        const toggle = panel.querySelector('.lc-panel__toggle');
        const body = panel.querySelector('.lc-panel__body');
        const summary = panel.querySelector('[data-panel-summary]');

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
        updateSummary();

        toggle.addEventListener('click', () => {
            const collapsed = panel.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            try {
                localStorage.setItem(KEY, collapsed ? '1' : '0');
            } catch (e) {}
        });

        // The element editor is created after this script runs: listen on the class, for the editor of this panel's form
        const editorOf = () => $(panel).closest('form').data('elementEditor');

        const refresh = (elementId) => {
            const editor = editorOf();
            Craft.sendActionRequest('POST', 'link-checker/panel/list', {
                data: {elementId: elementId ?? editor?.settings.elementId, siteId: panel.dataset.siteId},
            }).then(({data}) => {
                body.innerHTML = data.html;
                updateSummary();
            }).catch(() => {});
        };

        // Ignore a link everywhere (Utilities → Link Checker lists the ignored links)
        body.addEventListener('click', (ev) => {
            const button = ev.target.closest('[data-ignore]');
            if (!button) {
                return;
            }
            button.disabled = true;
            Craft.sendActionRequest('POST', 'link-checker/utility/ignore', {
                data: {url: button.dataset.ignore, pageId: button.dataset.pageId},
            }).then(() => refresh()).catch(() => {
                button.disabled = false;
            });
        });

        Garnish.on(Craft.ElementEditor, 'afterSaveDraft', (ev) => {
            if (ev.target === editorOf()) {
                refresh(ev.response?.data?.elementId ?? ev.response?.elementId);
            }
        });
        if (Craft.CpScreenSlideout) {
            Garnish.on(Craft.CpScreenSlideout, 'submit', () => setTimeout(() => refresh(), 500));
        }
    });
})(jQuery);
