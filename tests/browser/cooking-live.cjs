const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const AxeBuilder = require('@axe-core/playwright').default;
const fixture = JSON.parse(fs.readFileSync(process.env.COOKING_LIVE_MANIFEST || '/tmp/cooking-live-manifest.json'));
const timerIdentity = timers => Object.fromEntries(Object.entries(timers).map(([key, { remaining_ms, status, ...value }]) => [key, value]));
const base = process.env.COOKING_LIVE_BASE_URL || 'http://127.0.0.1:8027';

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'], ignoreDefaultArgs: ['--disable-back-forward-cache'] });
    try {
        const contexts = await Promise.all([browser.newContext({ viewport: { width: 390, height: 844 } }), browser.newContext({ viewport: { width: 1280, height: 900 } })]);
        const pages = await Promise.all(contexts.map(context => context.newPage()));
        const errors = [];
        for (const page of pages) {
            page.setDefaultTimeout(15000);
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(() => window.addEventListener('pageshow', event => { window.cookingWasPersisted = event.persisted; }));
        }
        const open = async page => {
            await page.locator('[data-cooking-start]').click();
            await page.locator('[data-cooking-current-step]').waitFor();
            await page.locator('[data-cooking-current-step] [data-step-done]').waitFor({ state: 'visible' });
        };
        const current = (page, step) => page.waitForFunction(step => document.querySelector('[data-cooking-current-step]')?.dataset.stepKey === step, step);
        const session = page => page.evaluate(async url => (await (await fetch(url, { headers: { Accept: 'application/json' } })).json()).data, '/en/recipes/' + fixture.recipe + '/cooking-session');
        const idle = page => page.waitForFunction(() => !document.querySelector('[data-cooking-current-step] [data-step-done]')?.disabled);
        const [first, second] = pages;
        for (const [index, page] of pages.entries()) {
            await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
            await page.locator('#email').fill(fixture.email);
            await page.locator('#password').fill('password');
            await Promise.all([page.waitForURL('**/en'), page.locator('.auth-submit').click()]);
            const locale = index ? 'el' : 'en';
            await page.goto(base + '/' + locale + '/recipes/' + fixture.slugs[locale], { waitUntil: 'domcontentloaded' });
            if (index === 0) {
                const created = await page.evaluate(async recipe => {
                    const response = await fetch('/en/recipes/' + recipe + '/cooking-sessions', { method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({ request_key: crypto.randomUUID() }) });
                    return response.status;
                }, fixture.recipe);
                assert.equal(created, 201);
            }
            await open(page); await idle(page);
            const tree = await page.locator('[data-cooking-overlay]').ariaSnapshot();
            assert(tree.includes('progressbar'));
            assert(tree.includes(index ? 'Ζύμη' : 'Dough'));
            const audit = await new AxeBuilder({ page }).include('[data-cooking-overlay]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
            assert.deepEqual(audit.violations.map(item => ({ id: item.id, impact: item.impact, nodes: item.nodes.map(node => node.target) })), []);
        }
        assert.equal((await session(first)).id, (await session(second)).id, 'separate browsers must resume the same active session');
        await first.locator('[data-start-timer]').click(); await idle(first);
        // The other browser still holds version 1 and must get a conflict, then authoritative state.
        await second.locator('[data-group=g1]').click(); await idle(second);
        await second.waitForFunction(() => document.querySelector('[data-cooking-status]').textContent.includes('συσκευή'));
        await current(second, 's0');
        await second.locator('[data-group=g1]').click(); await current(second, 's1'); await idle(second);
        await second.locator('[data-start-timer]').click(); await idle(second);
        await second.locator('[data-cooking-ingredients] input').first().check(); await idle(second);
        await first.keyboard.press('Escape'); await open(first); await idle(first);
        await current(first, 's1');
        assert.equal(await first.locator('[data-cooking-timers] [data-timer-key]').count(), 2);
        assert(await first.locator('[data-cooking-ingredients] input').first().isChecked());
        const persisted = await session(first);
        assert.equal(persisted.state.steps.s0.status, 'waiting');
        assert.equal(persisted.state.steps.s1.status, 'waiting');
        assert.equal(persisted.version, 5);
        const stored = await first.evaluate(() => Object.entries(sessionStorage).filter(([key]) => key.startsWith('cooking-session:') && !key.endsWith(':nonce')));
        assert.equal(stored.length, 1);
        assert.deepEqual(Object.keys(JSON.parse(stored[0][1])), ['id']);
        await first.reload({ waitUntil: 'domcontentloaded' }); await open(first); await idle(first);
        assert.equal((await session(first)).id, persisted.id);
        await first.goto(base + '/en', { waitUntil: 'domcontentloaded' });
        await first.goBack({ waitUntil: 'domcontentloaded' });
        const bfcache = await first.evaluate(() => window.cookingWasPersisted === true);
        if (!(await first.locator('[data-cooking-overlay]').isVisible())) await open(first);
        await first.locator('[data-cooking-current-step]').waitFor(); await idle(first);
        assert.equal((await session(first)).id, persisted.id);
        assert.equal(await first.locator('[data-cooking-timers] [data-timer-key]').count(), 2);
        if (fixture.component_path) {
            const parent = await session(first);
            const openChild = first.locator('[data-open-context="' + fixture.component_path + '"]');
            await openChild.locator('..').locator(':scope > summary').click();
            await openChild.click(); await idle(first);
            await first.locator('[data-cooking-context-back]').waitFor({ state: 'visible' });
            await first.locator('[data-start-timer]').click(); await idle(first);
            assert.equal(await first.locator('[data-cooking-timers] [data-timer-key]').count(), 3);
            let nested = await session(first);
            assert.equal(nested.state.active_context, fixture.component_path);
            assert.deepEqual(timerIdentity(nested.state.timers), timerIdentity(parent.state.timers));
            assert.equal(nested.state.contexts[fixture.component_path].steps.s0.status, 'waiting');
            await second.keyboard.press('Escape'); await open(second); await idle(second);
            await second.locator('[data-cooking-context-back]').waitFor({ state: 'visible' });
            for (const page of pages) {
                const audit = await new AxeBuilder({ page }).include('[data-cooking-overlay]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
                assert.deepEqual(audit.violations.map(item => item.id), []);
            }
            assert.equal(await second.locator('[data-cooking-timers] [data-timer-key]').count(), 3);
            await first.reload({ waitUntil: 'domcontentloaded' }); await open(first); await idle(first);
            await first.locator('[data-cooking-context-back]').click(); await idle(first); await current(first, 's1');
            nested = await session(first);
            assert.equal(nested.state.active_context, null);
            assert.deepEqual(timerIdentity(nested.state.timers), timerIdentity(parent.state.timers));
            assert.equal(await first.locator('[data-cooking-timers] [data-timer-key]').count(), 3);
        }
        await first.keyboard.press('Escape');
        assert(await first.locator('[data-cooking-start]').evaluate(el => el === document.activeElement));
        for (const [index, page] of pages.entries()) {
            const locale = index ? 'el' : 'en';
            await page.goto(base + '/' + locale + '/recipe-studio/create', { waitUntil: 'domcontentloaded' });
            await page.waitForFunction(() => { const root = document.querySelector('.studio-shell'); return root && window.Livewire?.find(root.getAttribute('wire:id')); });
            await page.getByRole('tab', { name: index ? '3 · Οδηγίες' : '3 · Instructions', exact: true }).click();
            const [added] = await Promise.all([page.waitForResponse(response => {
                if (!response.url().includes('/livewire') || response.request().method() !== 'POST') return false;
                return response.request().postDataJSON()?.components?.some(component => component.calls?.some(call => call.method === 'mountAction'));
            }), page.getByRole('button', { name: index ? 'Προσθήκη ομάδας' : 'Add group', exact: true }).click()]);
            assert.equal(added.status(), 200);
            const actionResponse = await added.json();
            const actionSnapshot = actionResponse.components?.map(component => JSON.parse(component.snapshot)).find(snapshot => snapshot.memo.name === 'contributor-recipe-studio');
            assert.equal(Object.keys(actionSnapshot?.data.data?.[0]?.step_groups_data?.[0] ?? {}).length, 1);
            assert.deepEqual(actionSnapshot?.memo.errors ?? [], []);
            try {
                await page.locator('input[id$=".translations.en.title"]').last().waitFor({ state: 'visible' });
            } catch (error) {
                fs.writeFileSync('/tmp/nested-live-editor-' + locale + '.html', await page.content());
                console.error('Editor diagnostics', locale, await page.locator('input[id$=".translations.en.title"]').evaluateAll(nodes => nodes.map(node => ({ id: node.id, visible: !!node.offsetParent }))));
                throw error;
            }
            await page.locator('input[id$=".translations.el.title"]').last().waitFor({ state: 'visible' });
            await page.getByRole('tab', { name: index ? '2 · Υλικά' : '2 · Ingredients', exact: true }).click();
            await Promise.all([page.waitForResponse(response => response.url().includes('/livewire') && response.request().method() === 'POST'),
                page.getByRole('button', { name: index ? 'Προσθήκη υλικού ή υποσυνταγής' : 'Add ingredient or sub-recipe', exact: true }).click()]);
            const type = page.locator('select[id$=".line_type"]').first();
            await type.selectOption('recipe');
            await page.locator('select[id$=".quantity_basis"]').first().waitFor({ state: 'visible' });
        }
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ realAuth: true, crossBrowserResume: true, conflictRecovery: true, timersAndChecklist: true,
            reload: true, actualHistoryNavigation: true, bfcachePersisted: bfcache, accessibilityTree: true, axeViolations: 0, bilingualEditorGroups: true, nestedContextPersistence: !!fixture.component_path, pageErrors: errors }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
