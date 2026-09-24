<?php
/** Standalone plugin decision tests. Run from anywhere with PHP 8.0+: php website/fee-tracker-tests/plugin-test.php
 * Stubs do not test WordPress authentication, options.php nonce enforcement, theme rendering, or cache servers.
 */
define('ABSPATH', __DIR__ . '/');
$test_options = array('cft_public_enabled' => false, 'cft_preview_json' => '');
$test_actions = array();
$test_filters = array();
$test_registered = array();
$test_autoload = array();
$test_errors = array();
$test_calls = array();
$test_admin = false;
$test_preview = false;
$test_page = true;
$test_loop_id = 12;
class WP_Post {
    public $ID = 12;
    public $post_status = 'draft';
    public $post_name = 'review-different-slug';
    public $post_content = '[carryaware_fee_tracker]';
}
$test_post = new WP_Post();
function get_option($key, $default = false) { global $test_options; return $test_options[$key] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = true) { global $test_options, $test_autoload; $test_autoload[$key] = $autoload; if (!array_key_exists($key, $test_options)) { $test_options[$key] = $value; } }
function delete_option($key) { global $test_options; unset($test_options[$key]); }
function current_user_can($capability) { global $test_admin; return $capability === 'manage_options' && $test_admin; }
function get_the_ID() { global $test_loop_id; return $test_loop_id; }
function get_queried_object_id() { global $test_post; return $test_post->ID; }
function get_queried_object() { global $test_post; return $test_post; }
function get_post_status($post = null) { global $test_post; return $post instanceof WP_Post ? $post->post_status : $test_post->post_status; }
function is_page($slug = null) { global $test_page, $test_post; return $test_page && ($slug === null || $slug === $test_post->post_name); }
function is_preview() { global $test_preview; return $test_preview; }
function has_shortcode($text, $tag) { return strpos($text, '[' . $tag . ']') !== false; }
function add_action($name, $callback) { global $test_actions; $test_actions[$name][] = $callback; }
function add_filter($name, $callback) { global $test_filters; $test_filters[$name][] = $callback; }
function do_action($name, ...$args) { global $test_actions, $test_calls; $test_calls[] = $name; foreach ($test_actions[$name] ?? array() as $callback) { $callback(...$args); } }
function register_deactivation_hook($file, $callback) { global $test_deactivate; $test_deactivate = $callback; }
function add_shortcode($name, $callback) {}
function add_management_page(...$args) {}
function register_setting($group, $key, $args) { global $test_registered; $test_registered[$key] = array('group' => $group, 'args' => $args); }
function add_settings_error(...$args) { global $test_errors; $test_errors[] = $args; }
function settings_errors(...$args) {}
function settings_fields($group) { echo '<input type="hidden" name="_wpnonce" value="test-placeholder">'; }
function nocache_headers() { global $test_calls; $test_calls[] = 'nocache_headers'; }
function plugins_url($path, $file) { return 'https://example.invalid/plugins/' . $path; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script_module(...$args) {}
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function checked($left, $right, $echo = true) { return $left === $right ? 'checked="checked"' : ''; }
function submit_button($text) { echo '<button>' . esc_attr($text) . '</button>'; }
require __DIR__ . '/../plugins/carryaware-fee-tracker/carryaware-fee-tracker.php';
$test_count = 0;
function expect($condition, $message) {
    global $test_count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $test_count++;
}
function copy_feed($feed) { return json_decode(json_encode($feed)); }
function without_fixture($html) {
    return strpos($html, 'data-cft-preview-data') === false && strpos($html, 'private-snapshot') === false && strpos($html, 'PRIVATE_SENTINEL') === false;
}
$data_path = getenv('CARRY_FEE_DATA_PATH');
if (!$data_path) { throw new RuntimeException('Set CARRY_FEE_DATA_PATH to the canonical review dataset.'); }
$raw = file_get_contents($data_path);
$feed = cft_decode_preview($raw);
expect(is_object($feed) && count($feed->municipalities) === 20, 'unchanged supplied dataset validates');
expect(cft_decode_preview(str_repeat(' ', CFT_PREVIEW_MAX_BYTES + 1)) === null, 'oversized input rejected');
expect(cft_decode_preview('{bad') === null && cft_decode_preview('null') === null, 'malformed/root-null JSON rejected');
$bad = copy_feed($feed); $bad->municipalities[] = $bad->municipalities[0];
expect(!cft_valid_preview_feed($bad), 'duplicate municipality rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->refund_amount = '100';
expect(!cft_valid_preview_feed($bad), 'string fee rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->refund_amount = 151;
expect(!cft_valid_preview_feed($bad), 'out-of-range fee rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->refund_amount = 100; $bad->municipalities[0]->net_municipal_cost = 100;
expect(!cft_valid_preview_feed($bad), 'contradictory refund and remaining cost rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->statutory_municipal_portion = 200;
expect(!cft_valid_preview_feed($bad), 'unexpected statutory municipal portion rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->effective_date = '2026-02-30';
expect(!cft_valid_preview_feed($bad), 'impossible date rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->secondary_source_url = 'javascript:alert(1)';
expect(!cft_valid_preview_feed($bad), 'unsafe URL rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->secondary_source_url = 'https://user:password@example.com/';
expect(!cft_valid_preview_feed($bad), 'URL credentials rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->municipality_code = 1505;
expect(!cft_valid_preview_feed($bad), 'numeric municipality code rejected');
$bad = copy_feed($feed); $bad->municipalities[0]->status = 'announced_pending_documents';
expect(!cft_valid_preview_feed($bad), 'pending record cannot carry verified date');
$bad = copy_feed($feed); unset($bad->municipalities[0]->official_source_url);
expect(!cft_valid_preview_feed($bad), 'missing nullable field rejected');

$test_options['cft_preview_json'] = $raw;
expect(cft_sanitize_preview('') === $raw, 'non-admin cannot clear snapshot');
$test_admin = true;
expect(cft_sanitize_preview('') === '', 'administrator can clear snapshot');
expect(cft_sanitize_preview($raw) === $raw, 'valid snapshot preserved exactly');
expect(cft_sanitize_preview('{bad') === $raw && count($test_errors) === 1, 'invalid save preserves previous snapshot and reports error');
$expanded = copy_feed($feed); $expanded->municipalities[0]->notes = str_repeat('<', 170000);
$expanded_raw = json_encode($expanded, JSON_UNESCAPED_SLASHES);
expect(strlen($expanded_raw) < CFT_PREVIEW_MAX_BYTES && cft_sanitize_preview($expanded_raw) === $raw && count($test_errors) === 2, 'HEX-expanded snapshot exceeding inline size limit rejected before save');
do_action('admin_init');
expect($test_autoload['cft_preview_json'] === false, 'option created without autoload');
expect($test_registered['cft_preview_json']['args']['show_in_rest'] === false, 'snapshot is not REST exposed');
expect($test_registered['cft_preview_json']['args']['sanitize_callback'] === 'cft_sanitize_preview', 'validation wired to Settings API');
expect(cft_sanitize_public(cft_sanitize_public('1')) === true && cft_sanitize_public(cft_sanitize_public('0')) === false, 'public flag sanitizer is idempotent');
expect(cft_sanitize_public('true') === false && cft_sanitize_public('yes') === false, 'unexpected public flag values stay disabled');

$hostile = copy_feed($feed);
$hostile->municipalities[0]->notes = 'PRIVATE_SENTINEL </script><script>alert("x")</script></textarea><img src=x onerror=alert(1)> & \' "';
$test_options['cft_preview_json'] = json_encode($hostile, JSON_UNESCAPED_SLASHES);
$html = cft_shortcode();
expect(strpos($html, 'data-preview-mode="private-snapshot"') !== false, 'administrator draft receives private mode');
expect(strpos($html, '</script><script>alert') === false && strpos($html, '<img src=x') === false, 'hostile markup escaped');
preg_match('~<script type="application/json" data-cft-preview-data>(.*?)</script>~s', $html, $matches);
expect(isset($matches[1]) && json_decode($matches[1])->municipalities[0]->notes === $hostile->municipalities[0]->notes, 'safe inline JSON round-trips exactly');
expect(strpos($matches[1], '\\u003C') !== false && strpos($matches[1], '<') === false, 'script-closing angle brackets HEX escaped');

$test_admin = false;
expect(without_fixture(cft_shortcode()), 'anonymous viewer receives no fixture with public mode disabled');
$test_options['cft_public_enabled'] = true;
expect(without_fixture(cft_shortcode()), 'viewer without manage_options receives no fixture with public mode enabled');
$test_admin = true;
$test_post->post_status = 'publish';
$test_preview = true;
$html = cft_shortcode();
expect(without_fixture($html) && strpos($html, CFT_FEED_URL) !== false, 'published page with preview query stays on production feed');
$test_preview = false;
expect(without_fixture(cft_shortcode()), 'ordinary published page ignores snapshot');
$test_post->post_status = 'draft';
$test_loop_id = 99;
expect(without_fixture(cft_shortcode()), 'unrelated loop post cannot receive snapshot');
$test_loop_id = 12;
$test_page = false;
expect(without_fixture(cft_shortcode()), 'non-page contexts cannot receive snapshot');
$test_page = true;
foreach (array('pending', 'private') as $status) {
    $test_post->post_status = $status;
    expect(strpos(cft_shortcode(), 'data-cft-preview-data') !== false, 'administrator ' . $status . ' page receives snapshot');
}
$test_post->post_status = 'draft';
$test_options['cft_preview_json'] = '';
expect(strpos(cft_shortcode(), 'data-carryaware-fee-tracker') === false, 'missing private snapshot fails closed without mount');
$test_options['cft_preview_json'] = '{bad';
expect(strpos(cft_shortcode(), 'data-carryaware-fee-tracker') === false, 'corrupted private snapshot fails closed without mount');
$test_options['cft_preview_json'] = json_encode($hostile, JSON_UNESCAPED_SLASHES);
ob_start(); cft_admin(); $admin_html = ob_get_clean();
expect(strpos($admin_html, '&lt;/textarea&gt;') !== false && strpos($admin_html, '<img src=x') === false, 'admin textarea safely escapes hostile snapshot');
$test_admin = false;
ob_start(); cft_admin(); $admin_html = ob_get_clean();
expect($admin_html === '', 'non-admin cannot read settings page');

$test_calls = array(); do_action('wp');
expect(in_array('nocache_headers', $test_calls, true) && in_array('litespeed_control_set_nocache', $test_calls, true), 'arbitrary draft slug with shortcode disables caching');
$test_post->post_content = ''; $test_preview = true;
$test_calls = array(); do_action('wp');
expect(in_array('nocache_headers', $test_calls, true), 'page preview without canonical slug disables caching');
$test_preview = false; $test_post->post_name = 'nj-carry-permit-fee-refunds';
$test_calls = array(); do_action('wp');
expect(in_array('nocache_headers', $test_calls, true), 'canonical page disables caching');
$test_options['unrelated_option'] = 'preserve';
$test_deactivate();
expect(!isset($test_options['cft_preview_json']) && !isset($test_options['cft_public_enabled']) && $test_options['unrelated_option'] === 'preserve', 'deactivation clears only plugin settings');
echo 'PASS: ' . $test_count . " plugin validation, access-control, escaping, cache, and cleanup assertions.\n";
