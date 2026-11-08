// Run against a seeded, isolated audit site; Playwright is an external audit tool.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({
            baseURL: process.env.AUDIT_BASE_URL || 'http://127.0.0.1:8019',
            viewport: { width: 390, height: 844 },
        });
        page.setDefaultTimeout(30000);
        for (const [locale, query] of [['en', 'chick'], ['el', 'ρεβ']]) {
            await page.goto('/' + locale, { waitUntil: 'domcontentloaded' });
            const input = page.locator('#home-search-input');
            await input.fill(query);
            // Results arriving through Livewire must become visible without a second keystroke.
            await page.locator('[role=option]').first().waitFor({ state: 'visible' });
            await page.waitForFunction(() => document.querySelector('#home-search-input').getAttribute('aria-expanded') === 'true');
            await input.fill('zzzz-no-catalogue-match-zzzz');
            await page.locator('[x-ref=empty]').waitFor({ state: 'visible' });
            await input.fill(query);
            await page.locator('[role=option]').first().waitFor({ state: 'visible' });
            await input.press('ArrowDown');
            assert(await input.getAttribute('aria-activedescendant'));
            await Promise.all([
                page.waitForURL(url => url.pathname !== '/' + locale || !!url.search),
                input.press('Enter'),
            ]);
            console.log(locale + ': asynchronous suggestions are visible and keyboard navigation works');
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
