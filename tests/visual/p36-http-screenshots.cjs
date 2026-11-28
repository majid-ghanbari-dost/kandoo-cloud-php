'use strict';
// Synthetic CI-only screenshots of actual HTTP responses, not mock HTML or real customer data.
const fs = require('fs');
const { chromium } = require('playwright-core');
(async () => {
  const [fixturePath, target] = process.argv.slice(2);
  const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
  const base = 'http://127.0.0.1:18083';
  fs.mkdirSync(target, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.P36_CHROME, args: ['--no-sandbox'], headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    async function capture(name, path, status, selector, text) {
      const response = await page.goto(base + path, { waitUntil: 'networkidle' });
      if (!response || response.status() !== status || !(await page.locator(selector).count()) || !(await page.locator('body').innerText()).includes(text)) {
        throw new Error('Unexpected synthetic HTTP state: ' + name);
      }
      await page.evaluate(() => document.fonts.ready);
      if (await page.evaluate(() => document.documentElement.scrollWidth) > 390) throw new Error('Viewport overflow: ' + name);
      await page.screenshot({ path: target + '/' + name + '.png', fullPage: true });
      console.log('Synthetic HTTP screenshot:', name, status);
    }
    await capture('normal', '/i/' + fixture.public_token, 200, '.brand-header', 'TEST customer');
    if (await page.locator('table.lines tbody tr').first().evaluate(el => getComputedStyle(el).display) !== 'grid') throw new Error('Mobile receipt lines not grouped');
    await page.emulateMedia({ media: 'print' });
    if (await page.locator('table.lines').evaluate(el => getComputedStyle(el).display) !== 'table') throw new Error('Print must retain the full table');
    await page.screenshot({ path: target + '/print.png', fullPage: true });
    await page.emulateMedia({ media: 'screen' });
    await context.addCookies([{name:'kandoo_session', value:fixture.session, url:base}]);
    await capture('empty', '/account/invoices', 200, '.empty-state', 'هنوز فاکتوری ندارید');
    // This route renders the same 429 support state returned by the actual OTP lock flow.
    await capture('locked', '/account/telegram', 429, '.locked-state', 'پشتیبانی تلگرام');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
