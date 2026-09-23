# Fee tracker checks

```sh
CARRY_FEE_DATA_PATH=/absolute/path/to/carryaware-data/nj_carry_fee_relief.json node --test website/fee-tracker-tests/tracker.test.mjs
php website/fee-tracker-tests/plugin-test.php
php website/tests/site-plugin-test.php
```

`browser.test.cjs` requires Playwright 1.62.1 and a loopback preview at port 8765 with `/api/nj_carry_fee_relief.json` and `/alert/`, using the actual tracker assets. It tests desktop and mobile rendering, filters, sorting, distinct municipality names, expandable instructions, no-cache outages, dated cached fallback, and the draft alert's three buttons/credit. Screenshots go to `CFT_OUTPUT_DIR` (default `output`). Use `CFT_BROWSER_PATH` for a locally installed Chromium/Chrome, or install Playwright's Chromium. It starts a separate headless browser profile and never signs in or sends a notification. The task handoff contains the loopback preview server.

```sh
cd website/fee-tracker-tests
npm install
CFT_BROWSER_PATH='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' node browser.test.cjs
```

These are local rendering tests and WordPress API stub tests, not a claim that the plugin has been installed on the live WordPress site. Check the signed-in draft page with the active theme and cache configuration before publication.
