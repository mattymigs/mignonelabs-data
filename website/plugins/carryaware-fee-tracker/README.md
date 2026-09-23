# CarryAwareNJ Municipal Fee Tracker

Review-only package. Not installed on mignonelabs.com. It adds its own shortcode and assets without replacing the active Mignone Labs Site plugin, theme, or Core snippets.

The production shortcode reads `https://mattymigs.github.io/carryaware-data/nj_carry_fee_relief.json`, the same feed used by the iOS tracker. No municipality dataset is copied here. The website displays the feed's review dates; a failed request can use a clearly labeled browser-session snapshot. With no valid snapshot it shows an unavailable message and no misleading zero totals.

After explicit publication approval: install/activate this plugin (defaults to preview only), create a **draft** page titled “New Jersey Municipal Carry-Permit Fee Tracker” with slug `nj-carry-permit-fee-refunds`, use the existing `page-no-title` template, and insert `[carryaware_fee_tracker]` in a Shortcode block. Inspect the signed-in WordPress preview after the shared data feed has been approved. Tools → CarryAwareNJ Fee Tracker controls public mode. Publish the page only after final approval. A WordPress preview is private by WordPress authentication; noindex is not an access control.

The local preview harness renders these exact assets against the review checkout's JSON. It is bound to 127.0.0.1 and never writes to WordPress. Browser rendering tests are independent of WordPress integration; a signed-in preview on the live theme is still a release check.

Rollback: change the page back to draft, disable public mode or deactivate the plugin, and purge the page cache. Deactivation resets public mode. No legal alert feed or Firebase notification is changed by this plugin.
