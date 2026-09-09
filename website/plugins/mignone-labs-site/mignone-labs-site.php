<?php
/**
 * Plugin Name: Mignone Labs Site
 * Description: Preview-first release history, readable release notes, and page-sharing metadata. Does not replace the theme.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Mignone Labs LLC
 * License: GPL-2.0-or-later
 * Update URI: https://mignonelabs.com/mignone-labs-site
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/website.php';

add_action('admin_menu', static function () {
    add_management_page('Mignone Labs Site', 'Mignone Labs Site', 'manage_options', 'mignone-labs-site', 'mls_admin_page');
});
add_action('admin_init', static function () {
    register_setting('mls_settings', 'mls_live', array('type' => 'boolean', 'default' => false,
        'sanitize_callback' => static function ($value) { return $value === '1' || $value === 1 || $value === true; }));
});

/** Preview cannot be enabled by an anonymous visitor, a URL alone, or an invalid nonce. */
add_action('wp', static function () {
    $preview = isset($_GET['mls_preview']) && is_string($_GET['mls_preview'])
        && current_user_can('manage_options')
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['mls_preview'])), 'mls_preview');
    if (!get_option('mls_live', false) && !$preview) { return; }
    if ($preview || is_page('carryaware-release-notes')) {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        do_action('litespeed_control_set_nocache', 'Mignone Labs dynamic release information or authorized preview');
    }
    Mignone_Labs_Site::boot();
    if ($preview) {
        add_filter('wp_robots', static function ($robots) { $robots['noindex'] = true; return $robots; });
        add_action('wp_footer', static function () {
            echo '<aside role="status" style="position:fixed;bottom:12px;left:12px;right:12px;z-index:99999;background:#07111f;color:#fff;border:2px solid #ff8585;padding:12px 16px;font:14px/1.5 sans-serif;border-radius:8px">Mignone Labs preview — these enhancements are not being shown to public visitors while Preview-only mode is selected.</aside>';
        });
    }
});

function mls_purge_cache() {
    do_action('litespeed_purge_all');
}
add_action('update_option_mls_live', 'mls_purge_cache');
add_action('add_option_mls_live', 'mls_purge_cache');
register_deactivation_hook(__FILE__, static function () {
    delete_option('mls_live'); // A reactivation must start in preview mode again.
    mls_purge_cache();
});

function mls_admin_page() {
    if (!current_user_can('manage_options')) { return; }
    $preview_url = wp_nonce_url(home_url('/carryaware-release-notes/'), 'mls_preview', 'mls_preview');
    echo '<div class="wrap"><h1>Mignone Labs Site</h1><p>This add-on keeps your current theme and page designs. It improves release-note lists, inserts the GitHub release archive, and adds metadata from your existing page excerpts.</p>';
    echo '<p><strong>Mode: ' . (get_option('mls_live', false) ? 'Live' : 'Preview only — public visitors unchanged') . '</strong></p>';
    if (!function_exists('ml_get_carryaware_app_data')) {
        echo '<div class="notice notice-warning inline"><p>The existing Mignone Labs Core release-data function was not found. Leave that Code Snippets entry active; release information depends on it.</p></div>';
    }
    echo '<p><a class="button button-secondary" target="_blank" rel="noopener" href="' . esc_url($preview_url) . '">Preview the release page</a></p>';
    echo '<p>Before enabling: check version 1.0.5 or the then-current public version, the bullet list, previous releases, and the unchanged header/footer. A backup is required before production deployment.</p>';
    echo '<form action="options.php" method="post">';
    settings_fields('mls_settings');
    echo '<input type="hidden" name="mls_live" value="0"><label><input type="checkbox" name="mls_live" value="1" ' . checked((bool) get_option('mls_live', false), true, false) . '> Enable the enhancements for public visitors</label>';
    submit_button('Save mode');
    echo '</form><p>For rollback, clear the checkbox and save, or deactivate this plugin. The original pages and current-release shortcode remain intact.</p></div>';
}

/** Authenticated, read-only verification path for connected WordPress tools. */
add_action('rest_api_init', static function () {
    register_rest_route('mignone-labs-site/v1', '/preview', array(
        'methods' => 'GET',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'callback' => static function () {
            $page = get_page_by_path('carryaware-release-notes');
            $response = new WP_REST_Response(array(
                'plugin_version' => '1.0.0',
                'mode' => get_option('mls_live', false) ? 'live' : 'preview-only',
                'theme' => get_stylesheet(),
                'core_provider_available' => function_exists('ml_get_carryaware_app_data'),
                'current_release_html' => Mignone_Labs_Site::format_current('', 'carryaware_current_release'),
                'history_html' => Mignone_Labs_Site::history_shortcode(),
                'release_page_metadata_html' => $page ? Mignone_Labs_Site::metadata_html($page) : '',
            ));
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        },
    ));
});
