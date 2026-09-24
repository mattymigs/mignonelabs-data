# Fee tracker checks

Use the canonical review dataset; no duplicate is bundled in this repository:

```sh
export CARRY_FEE_DATA_PATH=/absolute/path/to/carryaware-data/nj_carry_fee_relief.json
node --test website/fee-tracker-tests/tracker.test.mjs
php website/fee-tracker-tests/plugin-test.php
php website/tests/site-plugin-test.php
node --check website/plugins/carryaware-fee-tracker/assets/tracker.mjs
```

The integrated PHP harness has 59 assertions covering preview validation, administrator/unpublished-page access, escaping, malformed snapshots, published pages ignoring snapshots, Settings API registration, idempotent sanitization, cache hooks, cleanup, consistent municipal costs, schema-2 statewide coverage, unverified-policy nulls, county identities, and snapshot autoload repair. These use stub WordPress APIs.

`browser-preview.test.cjs` starts a temporary server on loopback, blocks external browser requests, tests the actual plugin assets, and closes the server after testing. It checks private snapshots without feed requests or browser storage, invalid data without live fallback, source/status labels, all 564 municipalities, separate policy progress, county/status filters, 20-row pagination, same-name identities, unknown policy dates/amounts, expanded instructions, hostile text, public mode and outages, and mobile/desktop layout. The current suite has 15 model tests and 30 browser checks; the original public-feed suite adds 11 regression checks. `CFT_OUTPUT_DIR` controls screenshots/report output; the default is a directory under the OS temporary directory.

```sh
CFT_BROWSER_PATH='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' \
  CFT_OUTPUT_DIR=/absolute/path/to/review-output \
  node website/fee-tracker-tests/browser-preview.test.cjs
# Optional interactive local asset preview; no WordPress authentication is emulated:
node website/fee-tracker-tests/browser-preview.test.cjs --serve
```

Playwright 1.62.1 must be resolvable in Node. Use the installed workspace runtime through `NODE_PATH`, or install this directory's declared development dependency locally. `CFT_BROWSER_PATH` selects an existing Chrome/Chromium binary; no browser installation is required with that setting.

`browser.test.cjs` retains the original public-feed regression checks and now checks the Build 18 single “View Source” alert link. It requires the coordinator's loopback server at port 8765 with `/api/nj_carry_fee_relief.json` and `/alert/`. Run it with the same `CFT_BROWSER_PATH` and an existing `CFT_OUTPUT_DIR`. It covers sorting, composed filters, Borough/Beach separation, cached/unavailable feeds, phone overflow, and exact credit.

These are local rendering and WordPress stub checks. They do not prove core authentication, options.php nonce enforcement, script-module loading under the installed theme, or actual LiteSpeed/CDN caching. After authorization, verify administrator-only draft/snapshot access, anonymous/non-administrator denial, rejected settings saves, layout/links, no cache leakage after logout, and published pages using only the fixed production feed. No test here installs a plugin, mutates WordPress, changes a production feed, or sends notifications.
