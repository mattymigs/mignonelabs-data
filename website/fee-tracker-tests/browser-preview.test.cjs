// Local asset integration checks. This server does not emulate WordPress authentication.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const assets = path.resolve(__dirname, '../plugins/carryaware-fee-tracker/assets');
if (!process.env.CARRY_FEE_DATA_PATH) throw new Error('Set CARRY_FEE_DATA_PATH to the canonical review dataset.');
const data = JSON.parse(fs.readFileSync(process.env.CARRY_FEE_DATA_PATH, 'utf8'));
const output = path.resolve(process.env.CFT_OUTPUT_DIR || path.join(require('node:os').tmpdir(), 'carryaware-fee-preview-checks'));
fs.mkdirSync(output, {recursive:true});
const markup = fs.readFileSync(path.join(assets, 'tracker.html'), 'utf8');
const safeJSON = value => JSON.stringify(value).replace(/[<>&]/g, c => ({'<':'\\u003c','>':'\\u003e','&':'\\u0026'}[c]));

function pageHTML(mode) {
  const isPreview = ['private', 'invalid', 'hostile'].includes(mode);
  const fixture = structuredClone(data);
  if (mode === 'hostile') fixture.municipalities[0].notes = '</script><script>window.fixtureEscaped=true</script>';
  const script = isPreview || mode === 'public-with-fixture'
    ? `<script type="application/json" data-cft-preview-data>${mode === 'invalid' ? '{broken JSON' : safeJSON(fixture)}</script>` : '';
  const source = mode === 'unavailable' ? '/missing.json' : '/feed.json';
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>CarryAwareNJ — local asset review</title><link rel="stylesheet" href="/assets/tracker.css"><style>body{margin:0}.review-note{padding:10px 18px;background:#e9edf9;color:#223859;font:12px/1.5 system-ui;text-align:center}</style></head><body><div class="review-note">LOCAL ASSET PREVIEW · WordPress theme and authentication still require testing</div><div data-carryaware-fee-tracker data-feed-url="${source}" ${isPreview ? 'data-preview-mode="private-snapshot"' : ''}>${script}${markup}</div><script type="module" src="/assets/tracker.mjs"></script></body></html>`;
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Robots-Tag', 'noindex, nofollow');
  if (url.pathname === '/feed.json') {
    res.writeHead(200, {'Content-Type':'application/json'}); res.end(JSON.stringify(data)); return;
  }
  if (['/assets/tracker.css', '/assets/tracker.mjs'].includes(url.pathname)) {
    const file = path.join(assets, path.basename(url.pathname));
    res.writeHead(200, {'Content-Type':url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'});
    res.end(fs.readFileSync(file)); return;
  }
  const modes = {'/':'private', '/invalid/':'invalid', '/hostile/':'hostile', '/public/':'public', '/public-with-fixture/':'public-with-fixture', '/unavailable/':'unavailable'};
  if (Object.hasOwn(modes, url.pathname)) {
    res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); res.end(pageHTML(modes[url.pathname])); return;
  }
  res.writeHead(404, {'Content-Type':'text/plain'}); res.end('Not found');
});

async function main() {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  if (process.argv.includes('--serve')) { console.log(`Local asset review: ${base}`); return; }
  const browser = await chromium.launch({headless:true, ...(process.env.CFT_BROWSER_PATH ? {executablePath:process.env.CFT_BROWSER_PATH} : {})});
  const results = [];
  let context;
  try {
    context = await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:1});
    const external = [];
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== base) { external.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
    const page = await context.newPage();
    const errors = [], feedRequests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if(request.url().endsWith('.json')) feedRequests.push(request.url()); });
    await page.addInitScript(() => {
      window.storageTouches = 0;
      for (const key of ['getItem','setItem','removeItem']) {
        const original = Storage.prototype[key];
        Storage.prototype[key] = function(...args) { window.storageTouches++; return original.apply(this,args); };
      }
    });
    const check = (name, condition) => { assert.ok(condition, name); results.push({name,result:'passed'}); };
    await page.goto(base);
    await page.waitForFunction(() => document.querySelector('[data-stat="total"]').textContent === '564');
    check('private snapshot lists 564 municipalities with only 20 rows rendered', await page.locator('tbody tr').count() === 20);
    check('private preview is visibly labeled', /private preview/i.test(await page.locator('body').innerText()));
    check('private snapshot performs no feed requests', feedRequests.length === 0);
    check('private snapshot does not read or write browser storage', await page.evaluate(() => window.storageTouches === 0 && sessionStorage.length === 0));
    check('directory coverage and research progress are distinct', await page.locator('[data-stat="coveragePercent"]').innerText() === '100.00%' && /20 of 564.*3.55%/.test(await page.locator('[data-research-summary]').innerText()));
    check('policy groups show 2 confirmed, 17 reported, 1 pending, 544 unverified', (await Promise.all(['confirmed','reported','pending','unverified'].map(key => page.locator(`[data-stat="${key}"]`).innerText()))).join(',') === '2,17,1,544');
    check('mobile has no horizontal page overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const previews = path.join(output, 'previews');
    fs.mkdirSync(previews,{recursive:true});
    await page.screenshot({path:path.join(previews,'mobile-top-390.png')});
    await page.screenshot({path:path.join(previews,'mobile-full-390.png'),fullPage:true});
    await page.locator('.cft-directory').evaluate(el => el.scrollIntoView({block:'start'}));
    await page.screenshot({path:path.join(previews,'mobile-directory-390.png')});
    await page.locator('[name="municipality"]').fill('Berkeley Township');
    check('municipality filter selects Berkeley', await page.locator('tbody tr').count() === 1 && (await page.locator('tbody').innerText()).includes('Berkeley Township'));
    await page.locator('tbody summary').click();
    check('Berkeley instructions expand with refund amount', await page.locator('tbody details').evaluate(el => el.open) && (await page.locator('tbody').innerText()).includes('$100'));
    await page.locator('tbody').evaluate(el => el.scrollIntoView({block:'start'}));
    await page.screenshot({path:path.join(previews,'mobile-berkeley-390.png')});
    await page.locator('.cft-reset').click();
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 20);
    check('county filter exposes every NJ county', await page.locator('[name="county"] option').count() === 22);
    await page.locator('[data-page="next"]').first().click();
    check('pagination advances without rendering the full directory', /21–40 of 564/.test(await page.locator('[data-result-count]').innerText()) && await page.locator('tbody tr').count() === 20);
    await page.locator('[name="municipality"]').fill('Point Pleasant');
    check('search resets pagination and keeps Borough and Beach distinct', await page.locator('tbody tr').count() === 2 && await page.locator('tr[data-code="1525"]').count() === 1 && await page.locator('tr[data-code="1526"]').count() === 1 && await page.locator('[data-pagination]').first().isHidden());
    const unknown = await page.locator('tr[data-code="1526"]').innerText();
    check('unverified municipality has no implied zero fee or policy review date', /Policy not yet verified/.test(unknown) && /No policy evidence collected/.test(unknown) && /not yet researched/.test(unknown) && !/\$0|Sep 24, 2026|No refund/i.test(unknown));
    await page.locator('[name="municipality"]').fill('Washington');
    check('duplicate municipality names remain distinguishable', await page.locator('tbody tr').count() > 1 && (await page.locator('tbody').innerText()).includes('NJ code'));
    await page.locator('.cft-reset').click();
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 20);
    await page.locator('[name="status"]').selectOption('policy_not_yet_verified');
    check('unverified filter covers 544 entries with bounded rows', /of 544 matching/.test(await page.locator('[data-result-count]').innerText()) && await page.locator('tbody tr').count() === 20);
    await page.locator('[name="municipality"]').fill('not a municipality');
    check('empty filters display an explicit empty state', await page.locator('[data-empty]').isVisible() && await page.locator('tbody tr').count() === 0 && await page.locator('[data-pagination]').first().isHidden());
    await page.locator('.cft-reset').click();
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 20);
    await page.locator('[name="county"]').selectOption('Ocean');
    await page.locator('[name="status"]').selectOption('announced_pending_documents');
    check('county/status filters compose and retain Point Pleasant pending', await page.locator('tbody tr').count() === 1 && (await page.locator('tbody').innerText()).includes('Point Pleasant Borough'));
    await page.locator('[name="status"]').selectOption('confirmed_partial');
    check('confirmed partial policy is distinct from pending and reported', await page.locator('tr[data-code="1506"]').count() === 1 && /Confirmed partial relief/.test(await page.locator('tbody').innerText()));
    check('policy source links and roster links are HTTPS', await page.locator('.cft-source a, [data-roster-source] a').evaluateAll(links => links.length >= 4 && links.every(link => link.protocol === 'https:')));
    check('roster and policy dates are separately labeled', /Municipality roster checked Sep 24, 2026/.test(await page.locator('[data-roster-source]').innerText()) && /Policy evidence last reviewed: Sep 23, 2026/.test(await page.locator('[data-last-verified]').innerText()));
    await page.goto(base+'/invalid/');
    await page.waitForFunction(() => document.querySelector('[data-result-count]').textContent === 'Private preview data is unavailable.');
    check('invalid private fixture fails closed with no rows', await page.locator('tbody tr').count() === 0 && await page.locator('[data-stat="total"]').innerText() === '—');
    check('invalid private fixture does not fall back to feed or storage', feedRequests.length === 0 && await page.evaluate(() => window.storageTouches === 0));
    await page.goto(base+'/hostile/');
    await page.waitForFunction(() => document.querySelector('[data-stat="total"]').textContent === '564');
    check('script-like fixture text is not executed', await page.evaluate(() => window.fixtureEscaped === undefined));
    await page.goto(base+'/public-with-fixture/');
    await page.waitForFunction(() => document.querySelector('[data-stat="total"]').textContent === '564');
    check('public mode loads the live feed despite a dormant fixture element', feedRequests.length === 1 && !/private preview/i.test(await page.locator('body').innerText()));
    await page.evaluate(() => sessionStorage.clear());
    await page.goto(base+'/unavailable/');
    await page.waitForFunction(() => document.querySelector('[data-feed-message]').hidden === false);
    check('unavailable public feed shows no false zero count', await page.locator('[data-stat="total"]').innerText() === '—' && (await page.locator('[data-result-count]').innerText()).includes('unavailable'));
    await page.goto(base);
    await page.waitForFunction(() => document.querySelector('[data-stat="total"]').textContent === '564');
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('[data-sort="refund_amount"]').click();
    check('refund amounts sort numerically with known values first', await page.locator('tbody tr').first().getAttribute('data-code') === '1506');
    await page.locator('[data-sort="refund_amount"]').click();
    check('descending refund sort keeps unknown values out of first position', await page.locator('tbody tr').first().getAttribute('data-code') === '1352');
    await page.locator('.cft-reset').click();
    await page.waitForFunction(() => document.querySelector('[name="sort"]').value === 'municipality');
    check('desktop has no horizontal page overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.evaluate(() => scrollTo(0,0));
    await page.screenshot({path:path.join(previews,'desktop-top-1440.png')});
    await page.screenshot({path:path.join(previews,'desktop-full-1440.png'),fullPage:true});
    check('no uncaught browser errors', errors.length === 0);
    check('no external requests or notifications', external.length === 0);
    const report = {scope:'Local plugin assets only; not a live WordPress/authentication or iOS test',browser:await browser.version(),checks:results,errors,externalRequests:external};
    fs.writeFileSync(path.join(output,'browser-results.json'), JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify(report,null,2));
  } finally { if(context) await context.close(); await browser.close(); await new Promise(resolve=>server.close(resolve)); }
}
main().catch(error=>{console.error(error);server.close();process.exitCode=1;});
