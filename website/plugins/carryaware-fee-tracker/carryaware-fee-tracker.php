<?php
/**
 * Plugin Name: CarryAwareNJ Municipal Fee Tracker
 * Description: Municipal carry-fee tracker backed by carryaware-data. Preview first; public mode requires an explicit setting.
 * Version: 1.0.1-review2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Mignone Labs LLC
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
const CFT_FEED_URL = 'https://mattymigs.github.io/carryaware-data/nj_carry_fee_relief.json';
const CFT_VERSION = '1.0.1-review2';
const CFT_PREVIEW_MAX_BYTES = 1000000;

// The JSON option is never exposed through REST or a public file/URL.
function cft_valid_date($value) {
    if ($value === null) { return true; }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return false; }
    return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}
function cft_valid_https($value) {
    if (!is_string($value) || preg_match('/\s/', $value) || !filter_var($value, FILTER_VALIDATE_URL)) { return false; }
    $parts = parse_url($value);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
}
function cft_valid_preview_feed($feed) {
    if (!is_object($feed) || ($feed->schema_version ?? null) !== 1 || ($feed->state_municipality_count ?? null) !== 564 || !isset($feed->municipalities) || !is_array($feed->municipalities) || count($feed->municipalities) > 564) { return false; }
    foreach (array('last_checked_at', 'last_verified_at') as $key) {
        if (!property_exists($feed, $key) || !cft_valid_date($feed->$key) || ($key === 'last_checked_at' && $feed->$key === null)) { return false; }
    }
    $statuses = array('verified_full_or_substantial', 'verified_partial', 'announced_pending_documents', 'under_consideration', 'inactive_or_repealed');
    $sources = array('official_resolution', 'official_minutes', 'official_police', 'official_agenda', 'official_notice', 'advocacy_reporting', 'secondary_reporting', 'social_media');
    $codes = array();
    foreach ($feed->municipalities as $row) {
        if (!is_object($row) || !isset($row->municipality_code) || !is_string($row->municipality_code) || !preg_match('/^\d{4}$/D', $row->municipality_code) || isset($codes[$row->municipality_code]) || !in_array($row->status ?? null, $statuses, true) || !in_array($row->source_type ?? null, $sources, true)) { return false; }
        $codes[$row->municipality_code] = true;
        foreach (array('municipality', 'county', 'municipality_type', 'application_instructions', 'eligibility_summary', 'notes') as $key) {
            if (!isset($row->$key) || !is_string($row->$key) || trim($row->$key) === '') { return false; }
        }
        foreach (array('refund_amount', 'net_municipal_cost') as $key) {
            if (!property_exists($row, $key)) { return false; }
            $value = $row->$key;
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 150)) { return false; }
        }
        if (($row->statutory_municipal_portion ?? null) !== 150 || ($row->refund_amount !== null && $row->net_municipal_cost !== null && abs($row->refund_amount + $row->net_municipal_cost - 150) > 0.000001)) { return false; }
        foreach (array('effective_date', 'retroactive_date', 'verified_at', 'last_checked_at') as $key) {
            if (!property_exists($row, $key) || !cft_valid_date($row->$key)) { return false; }
        }
        foreach (array('official_source_url', 'secondary_source_url') as $key) {
            if (!property_exists($row, $key) || ($row->$key !== null && !cft_valid_https($row->$key))) { return false; }
        }
        if (!$row->official_source_url && !$row->secondary_source_url) { return false; }
        if (str_starts_with($row->status, 'verified_') && $row->verified_at === null) { return false; }
        if ($row->status === 'announced_pending_documents' && $row->verified_at !== null) { return false; }
    }
    return true;
}
function cft_decode_preview($raw) {
    if (!is_string($raw) || strlen($raw) > CFT_PREVIEW_MAX_BYTES) { return null; }
    $feed = json_decode($raw, false, 32);
    return json_last_error() === JSON_ERROR_NONE && cft_valid_preview_feed($feed) ? $feed : null;
}
function cft_sanitize_preview($raw) {
    $previous = get_option('cft_preview_json', '');
    if (!current_user_can('manage_options')) { return $previous; }
    if (is_string($raw) && trim($raw) === '') { return ''; }
    $feed = cft_decode_preview($raw);
    if ($feed === null) {
        add_settings_error('cft_preview_json', 'cft_invalid_preview', 'Preview JSON was not saved: use a valid municipal fee-tracker dataset of at most 1 MB. The previous snapshot is unchanged.');
        return $previous;
    }
    $encoded = wp_json_encode($feed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT, 32);
    if (!is_string($encoded) || strlen($encoded) > CFT_PREVIEW_MAX_BYTES) {
        add_settings_error('cft_preview_json', 'cft_large_encoded_preview', 'Preview JSON was not saved: its safely encoded preview exceeds 1 MB. Reduce the snapshot size. The previous snapshot is unchanged.');
        return $previous;
    }
    return $raw;
}
function cft_sanitize_public($value) {
    // Settings API may sanitize twice while creating an option for the first time.
    return in_array($value, array(true, 1, '1'), true);
}
function cft_private_preview_allowed() {
    // A published post stays on the production feed, even when ?preview=true.
    return current_user_can('manage_options') && is_page() && get_the_ID() === get_queried_object_id() && in_array(get_post_status(get_queried_object_id()), array('draft', 'pending', 'private'), true);
}
function cft_no_cache() {
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'Municipal fee tracker');
}
function cft_can_render() { return (bool) get_option('cft_public_enabled', false) || current_user_can('manage_options'); }
function cft_shortcode() {
    if (!cft_can_render()) { return '<p>The municipal fee tracker is not yet published.</p>'; }
    $preview = '';
    $mode = '';
    if (cft_private_preview_allowed()) {
        cft_no_cache();
        $feed = cft_decode_preview(get_option('cft_preview_json', ''));
        if ($feed === null) { return '<p>Private tracker preview: save valid review JSON under Tools → CarryAwareNJ Fee Tracker, then reload this draft. No live data is loaded here.</p>'; }
        // HEX_TAG prevents a JSON string containing </script> from closing the element.
        $json = wp_json_encode($feed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT, 32);
        if (!is_string($json) || strlen($json) > CFT_PREVIEW_MAX_BYTES) { return '<p>Private tracker preview could not encode the saved snapshot within its size limit. No live data is loaded here.</p>'; }
        $preview = '<script type="application/json" data-cft-preview-data>' . $json . '</script>';
        $mode = ' data-preview-mode="private-snapshot"';
    }
    wp_enqueue_style('carryaware-fee-tracker', plugins_url('assets/tracker.css', __FILE__), array(), CFT_VERSION);
    wp_enqueue_script_module('carryaware-fee-tracker', plugins_url('assets/tracker.mjs', __FILE__), array(), CFT_VERSION);
    // No query-string or shortcode-attribute URL override. Public always uses this endpoint.
    return '<div class="alignfull" data-carryaware-fee-tracker data-feed-url="' . esc_attr(CFT_FEED_URL) . '"' . $mode . '>' . $preview . file_get_contents(__DIR__.'/assets/tracker.html') . '</div>';
}
add_shortcode('carryaware_fee_tracker', 'cft_shortcode');
add_action('admin_menu', static function () {
    add_management_page('CarryAwareNJ Fee Tracker', 'CarryAwareNJ Fee Tracker', 'manage_options', 'carryaware-fee-tracker', 'cft_admin');
});
add_action('admin_init', static function () {
    // Create before the first save so the snapshot is not autoloaded on every request.
    add_option('cft_preview_json', '', '', false);
    register_setting('cft_settings', 'cft_public_enabled', array('type'=>'boolean','default'=>false,'sanitize_callback'=>'cft_sanitize_public'));
    register_setting('cft_settings', 'cft_preview_json', array('type'=>'string', 'default'=>'', 'show_in_rest'=>false, 'sanitize_callback'=>'cft_sanitize_preview'));
});
function cft_admin() {
    if (!current_user_can('manage_options')) { return; }
    echo '<div class="wrap"><h1>CarryAwareNJ Municipal Fee Tracker</h1><p>Create a draft page with slug <code>nj-carry-permit-fee-refunds</code> and the shortcode <code>[carryaware_fee_tracker]</code>. Paste the review dataset below, save, then preview the draft while signed in as an administrator. The private snapshot is never used on published pages. Public mode is off by default.</p>';
    settings_errors('cft_preview_json');
    echo '<form method="post" action="options.php">';
    // options.php enforces the Settings API nonce and manage_options capability.
    settings_fields('cft_settings');
    echo '<h2>Private preview snapshot</h2><p><label for="cft-preview-json">Contents of <code>nj_carry_fee_relief.json</code> (maximum 1 MB)</label></p><textarea id="cft-preview-json" name="cft_preview_json" rows="16" class="large-text code" spellcheck="false">' . esc_textarea(get_option('cft_preview_json', '')) . '</textarea><p>Only administrators viewing a draft, pending, or private page receive this snapshot. It is not published, does not update automatically, and is not saved in browser session storage. Leave this field blank and save to remove it.</p><h2>Public mode</h2><p>Enable and publish the page only after owner approval. Published pages use the fixed production feed.</p>';
    echo '<input type="hidden" name="cft_public_enabled" value="0"><label><input type="checkbox" name="cft_public_enabled" value="1" '.checked((bool)get_option('cft_public_enabled',false),true,false).'> Enable the tracker for public visitors</label>';
    submit_button('Save tracker settings');echo '</form></div>';
}
add_action('wp', static function () {
    $post = get_queried_object();
    $tracker_page = is_page() && $post instanceof WP_Post && has_shortcode($post->post_content, 'carryaware_fee_tracker');
    // Cover arbitrary draft slugs as well as the canonical public URL before output.
    if (!is_page('nj-carry-permit-fee-refunds') && !$tracker_page && !(is_page() && is_preview())) { return; }
    cft_no_cache();
    if (!get_option('cft_public_enabled',false) || is_preview() || ($tracker_page && get_post_status($post) !== 'publish')) {
        add_filter('wp_robots',static function($robots){$robots['noindex']=true;return $robots;});
    }
});
add_action('update_option_cft_public_enabled', static function(){do_action('litespeed_purge_all');});
register_deactivation_hook(__FILE__,static function(){delete_option('cft_public_enabled');delete_option('cft_preview_json');do_action('litespeed_purge_all');});
