// Fixtures are exported by CookingModeIntegrationTest inside rolled-back database transactions.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const fixtures = process.env.COOKING_BROWSER_FIXTURES_DIR || '/tmp/cooking-stage7-fixtures';
const root = path.resolve(__dirname, '../..');

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    try {
        for (const locale of ['en', 'el']) {
            for (const width of [390, 1280]) {
                const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: 'reduce' });
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                const fixture = JSON.parse(fs.readFileSync(path.join(fixtures, locale + '.json')));
                let revoked = false;
                await page.route('**/*', async route => {
                    const url = new URL(route.request().url());
                    if (url.pathname.includes('/cooking-content')) {
                        return route.fulfill({ status: revoked ? 404 : 200, json: revoked ? {} : { data: { ...fixture.data, server_time_ms: Date.now() } } });
                    }
                    if (url.pathname.startsWith('/build/')) {
                        const file = path.join(root, 'public', url.pathname);
                        return route.fulfill({ path: file, contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript' });
                    }
                    if (url.pathname.includes('/livewire') && url.pathname.endsWith('.js')) {
                        return route.fulfill({ path: path.join(root, 'vendor/livewire/livewire/dist/livewire.min.js'), contentType: 'text/javascript' });
                    }
                    if (url.pathname === '/' + locale + '/fixture') {
                        return route.fulfill({ path: path.join(fixtures, locale + '.html'), contentType: 'text/html' });
                    }
                    return route.fulfill({ status: 204, body: '' });
                });
                const open = async () => {
                    await page.locator('[data-cooking-start]').click();
                    await page.locator('[data-cooking-current-step]').waitFor();
                };
                const active = key => page.waitForFunction(key => document.querySelector('[data-cooking-current-step]')?.dataset.stepKey === key, key);
                const read = () => page.evaluate(id => JSON.parse(localStorage.getItem('cooking-guest:' + id)), fixture.data.recipe_id);
                await page.goto('http://cooking.test/' + locale + '/fixture');
                await open();
                assert(await page.locator('[data-cooking-heading]').evaluate(el => el === document.activeElement));
                assert.equal(await page.locator('[data-cooking-group-list] button').count(), 2);
                assert.equal(await page.locator('[data-cooking-current-step] img').getAttribute('alt'), locale === 'en' ? 'Dough photo' : 'Φωτογραφία ζύμης');
                const video = page.locator('[data-cooking-current-step] iframe');
                assert.equal(await video.getAttribute('title'), locale === 'en' ? 'Dough technique' : 'Τεχνική ζύμης');
                assert(!(await video.getAttribute('src')).includes('autoplay'));
                assert.equal(await page.locator('[data-cooking-sound]').getAttribute('aria-pressed'), 'false');
                await page.locator('[data-cooking-sound]').click();
                assert.equal(await page.locator('[data-cooking-sound]').getAttribute('aria-pressed'), 'true');
                await page.locator('[data-cooking-sound]').click();
                await page.screenshot({ path: path.join(fixtures, locale + '-' + width + '.png') });
                assert.equal(await page.locator('[data-cooking-overlay]').evaluate(el => el.scrollWidth <= el.clientWidth + 1), true, 'overlay overflows horizontally');
                await page.locator('[data-start-timer]').click();
                await page.locator('[data-cooking-group-list] [data-group=g1]').click();
                await active('s1');
                await page.locator('[data-start-timer]').click();
                assert.equal(await page.locator('[data-cooking-timers] [data-timer-key]').count(), 2);
                await page.locator('[data-pause-timer]').first().click();
                let state = await read();
                assert.equal(state.state.timers.s0.status, 'paused');
                await page.locator('[data-cooking-ingredients] input').first().check();
                await page.locator('[data-cooking-group-list] [data-group=g0]').click();
                await active('s0');
                await page.locator('[data-cooking-next]').click();
                await active('s2');
                await page.locator('[data-cooking-group-list] [data-group=g1]').click();
                await active('s1');
                await page.locator('[data-cooking-group-list] [data-group=g0]').click();
                await active('s2');
                await page.locator('[data-step-done]').click();
                assert.equal(await page.locator('[data-cooking-bar]').getAttribute('aria-valuenow'), '1');
                await page.locator('[data-step-done]').click();
                assert.equal(await page.locator('[data-cooking-bar]').getAttribute('aria-valuenow'), '0');
                await page.reload();
                await open();
                await active('s2');
                state = await read();
                assert.equal(state.state.timers.s0.status, 'paused');
                assert.equal(Object.keys(state.state.timers).length, 2);
                assert.equal(state.state.checked_ingredients.i0, true);
                // A timer expiry must be announced without completing its waiting step.
                await page.evaluate(id => {
                    const key = 'cooking-guest:' + id;
                    const held = JSON.parse(localStorage.getItem(key));
                    held.state.timers.s1.deadline_ms = Date.now() - 1000;
                    localStorage.setItem(key, JSON.stringify(held));
                }, fixture.data.recipe_id);
                await page.reload(); await open();
                await page.waitForFunction(() => document.querySelector('[data-cooking-alert]').textContent.length > 0);
                state = await read(); assert.equal(state.state.steps.s1.status, 'waiting');
                // All repeated Tab presses stay within the modal; Escape restores its opener.
                for (let i = 0; i < 32; i++) {
                    await page.keyboard.press(i % 2 ? 'Shift+Tab' : 'Tab');
                    assert(await page.evaluate(() => !!document.activeElement.closest('[data-cooking-overlay]')));
                }
                await page.keyboard.press('Escape');
                assert(await page.locator('[data-cooking-start]').evaluate(el => el === document.activeElement));
                await open(); revoked = true;
                await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
                await page.locator('[data-cooking-current-step]').waitFor({ state: 'detached' });
                assert.equal(await page.locator('[data-cooking-timers]').textContent(), '');
                assert.deepEqual(errors, []);
                console.log(locale + ' ' + width + ': groups, timers, refresh, checklist, keyboard, reduced motion, access recheck passed');
                await context.close();
            }
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
