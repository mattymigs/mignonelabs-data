<?php
/**
 * Plugin Name: CarryAwareNJ Municipal Fee Tracker
 * Description: Municipal carry-fee tracker backed by carryaware-data. Preview first; public mode requires an explicit setting.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Mignone Labs LLC
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
const CFT_FEED_URL = 'https://mattymigs.github.io/carryaware-data/nj_carry_fee_relief.json';
function cft_can_render() { return (bool) get_option('cft_public_enabled', false) || current_user_can('manage_options'); }
function cft_shortcode() {
    if (!cft_can_render()) { return '<p>The municipal fee tracker is not yet published.</p>'; }
    wp_enqueue_style('carryaware-fee-tracker', plugins_url('assets/tracker.css', __FILE__), array(), '1.0.0');
    wp_enqueue_script_module('carryaware-fee-tracker', plugins_url('assets/tracker.mjs', __FILE__), array(), '1.0.0');
    // A fixed public JSON endpoint shared with the app. No query-string URL override.
    return '<div class="alignfull" data-carryaware-fee-tracker data-feed-url="' . esc_attr(CFT_FEED_URL) . '">' . file_get_contents(__DIR__.'/assets/tracker.html') . '</div>';
}
add_shortcode('carryaware_fee_tracker', 'cft_shortcode');
add_action('admin_menu', static function () {
    add_management_page('CarryAwareNJ Fee Tracker', 'CarryAwareNJ Fee Tracker', 'manage_options', 'carryaware-fee-tracker', 'cft_admin');
});
add_action('admin_init', static function () {
    register_setting('cft_settings', 'cft_public_enabled', array('type'=>'boolean','default'=>false,'sanitize_callback'=>static function($v){ return $v === '1'; }));
});
function cft_admin() {
    if (!current_user_can('manage_options')) { return; }
    echo '<div class="wrap"><h1>CarryAwareNJ Municipal Fee Tracker</h1><p>Create a draft page with slug <code>nj-carry-permit-fee-refunds</code> and the shortcode <code>[carryaware_fee_tracker]</code>. Preview it while signed in. Public mode is off by default; enable it and publish the page only after owner approval.</p><form method="post" action="options.php">';
    settings_fields('cft_settings');
    echo '<input type="hidden" name="cft_public_enabled" value="0"><label><input type="checkbox" name="cft_public_enabled" value="1" '.checked((bool)get_option('cft_public_enabled',false),true,false).'> Enable the tracker for public visitors</label>';
    submit_button('Save mode');echo '</form></div>';
}
add_action('wp', static function () {
    if (!is_page('nj-carry-permit-fee-refunds')) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
    nocache_headers();do_action('litespeed_control_set_nocache','Municipal fee tracker');
    if (!get_option('cft_public_enabled',false) || is_preview()) {
        add_filter('wp_robots',static function($robots){$robots['noindex']=true;return $robots;});
    }
});
add_action('update_option_cft_public_enabled', static function(){do_action('litespeed_purge_all');});
register_deactivation_hook(__FILE__,static function(){delete_option('cft_public_enabled');do_action('litespeed_purge_all');});
