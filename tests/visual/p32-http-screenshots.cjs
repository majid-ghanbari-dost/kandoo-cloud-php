'use strict';
// Browser capture of the actual admin service using only synthetic CI data.
const fs = require('fs');
const { chromium } = require('playwright-core');
(async () => {
  const target = process.argv[2]; fs.mkdirSync(target, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.P36_CHROME, args:['--no-sandbox'], headless:true });
  try {
    const context = await browser.newContext({ viewport:{width:390,height:844}, deviceScaleFactor:1,
      httpCredentials:{username:'admin',password:'TEST-visual-admin'} });
    const page = await context.newPage();
    for (const state of ['normal','empty']) {
      const response = await page.goto('http://127.0.0.1:18084/admin?state='+state, {waitUntil:'networkidle'});
      if (!response || response.status() !== 200 || !(await page.locator('h1').innerText()).includes('مدیریت فاکتور دیجیتال کندو')) throw new Error('Admin HTTP state failed: '+state);
      if (state === 'empty' && !(await page.locator('body').innerText()).includes('هنوز فروشگاهی ثبت نشده است.')) throw new Error('Empty state missing');
      await page.evaluate(() => document.fonts.ready);
      if (await page.evaluate(() => document.documentElement.scrollWidth)>390) throw new Error('Admin viewport overflow');
      await page.screenshot({path:target+'/'+state+'.png',fullPage:true});
      console.log('Synthetic admin HTTP screenshot:',state);
    }
    await context.close();
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
