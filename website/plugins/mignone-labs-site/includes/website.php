<?php
/** Public remote information is validated and escaped; it is never executed as code. */
if (!defined('ABSPATH')) { exit; }

final class Mignone_Labs_Site {
    const APP_ID = '6762150416';
    const STORE_URL = 'https://apps.apple.com/us/app/carryawarenj/id6762150416';
    const ARCHIVE_URL = 'https://raw.githubusercontent.com/mattymigs/mignonelabs-data/main/apps/carryawarenj.json';

    public static function boot() {
        add_filter('do_shortcode_tag', array(__CLASS__, 'format_current'), 20, 4);
        add_filter('the_content', array(__CLASS__, 'insert_archive'), 30);
        add_action('wp_head', array(__CLASS__, 'metadata'), 6);
        add_shortcode('ml_release_history', array(__CLASS__, 'history_shortcode'));
    }

    public static function valid_version($value) {
        return is_string($value) && preg_match('/^[0-9]{1,5}(?:\.[0-9]{1,5}){0,3}$/D', $value) === 1;
    }

    public static function notes($text) {
        if (!is_string($text) || strlen($text) > 30000) { return ''; }
        $lines = preg_split('/\R/u', $text);
        if ($lines === false) { return ''; }
        $html = ''; $paragraph = array(); $list = array();
        $flush = static function () use (&$html, &$paragraph, &$list) {
            if ($paragraph) { $html .= '<p>' . implode('<br>', array_map('esc_html', $paragraph)) . '</p>'; $paragraph = array(); }
            if ($list) { $html .= '<ul><li>' . implode('</li><li>', array_map('esc_html', $list)) . '</li></ul>'; $list = array(); }
        };
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { $flush(); continue; }
            if (preg_match('/^(?:\*|-|\x{2022})\s+(.+)$/u', $line, $match)) {
                if ($paragraph) { $flush(); } $list[] = $match[1];
            } else { if ($list) { $flush(); } $paragraph[] = $line; }
        }
        $flush();
        return $html;
    }

    private static function valid_current($app) {
        return is_array($app) && (string) ($app['trackId'] ?? '') === self::APP_ID
            && self::valid_version($app['version'] ?? null)
            && is_string($app['releaseNotes'] ?? null) && trim($app['releaseNotes']) !== ''
            && strlen($app['releaseNotes']) <= 30000
            && is_string($app['currentVersionReleaseDate'] ?? null)
            && strtotime($app['currentVersionReleaseDate']) !== false;
    }

    private static function current() {
        $old = get_option('mls_apple_last_good', array());
        $app = function_exists('ml_get_carryaware_app_data') ? ml_get_carryaware_app_data() : array();
        if (self::valid_current($app)) {
            if (self::valid_current($old) && version_compare($app['version'], $old['version'], '<')) {
                return array('data' => $old, 'fallback' => true);
            }
            if ($old !== $app) { update_option('mls_apple_last_good', $app, false); }
            return array('data' => $app, 'fallback' => false);
        }
        return array('data' => self::valid_current($old) ? $old : array(), 'fallback' => true);
    }

    public static function format_current($output, $tag, $attr = array(), $match = array()) {
        if ($tag !== 'carryaware_current_release') { return $output; }
        $record = self::current(); $app = $record['data'];
        if (!$app) {
            return '<article class="ml-card ml-release-current"><p>Current release information is temporarily unavailable. <a href="' . esc_url(self::STORE_URL) . '">Check the App Store</a>.</p></article>';
        }
        $date = strtotime($app['currentVersionReleaseDate']);
        $html = '<article class="ml-card ml-release-current"><div class="ml-page__eyebrow">' . ($record['fallback'] ? 'Last known public release' : 'Current public release') . '</div>';
        $html .= '<h2 class="ml-release-version">Version ' . esc_html($app['version']) . '</h2>';
        $html .= '<p class="ml-release-status">Released <time datetime="' . esc_attr(gmdate('c', $date)) . '">' . esc_html(wp_date(get_option('date_format'), $date)) . '</time></p>';
        if ($record['fallback']) { $html .= '<p class="ml2-note">Apple could not be refreshed or returned an older result. Showing the last successfully retrieved release; check the App Store for the latest status.</p>'; }
        $html .= '<div class="ml-release-notes"><h3>What’s New</h3>' . self::notes($app['releaseNotes']) . '</div>';
        return $html . '<div class="ml-actions"><a class="ml-button ml-button--primary" href="' . esc_url(self::STORE_URL) . '" target="_blank" rel="noopener noreferrer">View on the App Store</a></div></article>';
    }

    public static function valid_archive($data) {
        if (!is_array($data) || !is_array($data['app'] ?? null)
            || (string) ($data['app']['appleId'] ?? '') !== self::APP_ID
            || ($data['app']['slug'] ?? '') !== 'carryawarenj'
            || !is_array($data['releases'] ?? null) || count($data['releases']) > 200) { return false; }
        $seen = array();
        foreach ($data['releases'] as $release) {
            if (!is_array($release) || !self::valid_version($release['version'] ?? null)
                || !is_string($release['releaseNotes'] ?? null) || trim($release['releaseNotes']) === ''
                || strlen($release['releaseNotes']) > 30000) { return false; }
            $key = implode('.', array_map('intval', explode('.', $release['version'])));
            $key = preg_replace('/(?:\.0)+$/', '', $key);
            if (isset($seen[$key])) { return false; } $seen[$key] = true;
            $day = $release['releaseDate'] ?? null;
            if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) { return false; }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d') !== $day) { return false; }
        }
        return true;
    }

    private static function archive() {
        $key = 'mls_carryaware_history_v1'; $cached = get_transient($key);
        if (is_array($cached) && is_bool($cached['fallback'] ?? null)
            && (self::valid_archive($cached['data'] ?? null) || ($cached['fallback'] && ($cached['data'] ?? null) === array()))) { return $cached; }
        $response = wp_safe_remote_get(self::ARCHIVE_URL, array('timeout' => 6, 'redirection' => 0,
            'limit_response_size' => 524288, 'user-agent' => 'MignoneLabsSite/1.0'));
        $data = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true, 64);
        }
        if (self::valid_archive($data)) {
            $record = array('data' => $data, 'fallback' => false);
            if (get_option('mls_history_last_good', array()) !== $data) { update_option('mls_history_last_good', $data, false); }
            set_transient($key, $record, 15 * MINUTE_IN_SECONDS);
            return $record;
        }
        $old = get_option('mls_history_last_good', array());
        $record = array('data' => self::valid_archive($old) ? $old : array(), 'fallback' => true);
        // Cache a failure too, preventing repeated remote calls during outages.
        set_transient($key, $record, 5 * MINUTE_IN_SECONDS);
        return $record;
    }

    public static function history_shortcode($attributes = array()) {
        $attributes = shortcode_atts(array('app' => 'carryawarenj'), $attributes, 'ml_release_history');
        if ($attributes['app'] !== 'carryawarenj') { return ''; }
        $record = self::archive(); $current = self::current();
        $version = $current['data']['version'] ?? '';
        $older = array_filter($record['data']['releases'] ?? array(), static function ($r) use ($version) {
            return $version !== '' && version_compare($r['version'], $version, '<');
        });
        usort($older, static function ($a, $b) { return version_compare($b['version'], $a['version']); });
        $html = '<section class="ml2-history" aria-label="Previous releases"><h2>Previous releases</h2>';
        if ($record['fallback']) { $html .= '<p class="ml2-note">The archive could not be refreshed. Any available cached history is shown below.</p>'; }
        if ($version === '') { return $html . '<p class="ml2-note">History is temporarily unavailable until a public release can be verified.</p></section>'; }
        if (!$older) { return $html . '<p class="ml2-note">No earlier releases are available in this archive yet.</p></section>'; }
        $html .= '<div class="ml2-faq">';
        foreach ($older as $release) {
            $html .= '<details><summary>Version ' . esc_html($release['version']) . ' · ' . esc_html($release['releaseDate']) . '</summary>' . self::notes($release['releaseNotes']) . '</details>';
        }
        return $html . '</div><p class="ml2-note">History is preserved in Mignone Labs’ release-data repository. Earlier entries may be incomplete.</p></section>';
    }

    public static function insert_archive($content) {
        if (is_admin() || !is_page('carryaware-release-notes') || !is_string($content)) { return $content; }
        $slot = '<div id="ml-release-history-slot"></div>';
        if (strpos($content, $slot) === false) { return $content; }
        return str_replace($slot, self::history_shortcode(), $content);
    }

    private static function tag($name, $value, $attribute = 'name') {
        return '<meta ' . $attribute . '="' . esc_attr($name) . '" content="' . esc_attr((string) $value) . '">' . PHP_EOL;
    }

    public static function metadata_html($post) {
        if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || function_exists('aioseo') || function_exists('the_seo_framework') || defined('SEOPRESS_VERSION')) { return ''; }
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || trim($post->post_excerpt) === '') { return ''; }
        $description = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($post->post_excerpt))));
        if ($description === '') { return ''; }
        $url = get_permalink($post);
        $title = html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (int) $post->ID === (int) get_option('page_on_front') ? get_bloginfo('name') : $title . ' — ' . get_bloginfo('name');
        $html = self::tag('description', $description);
        foreach (array('og:type' => 'website', 'og:site_name' => get_bloginfo('name'), 'og:title' => $title,
            'og:description' => $description, 'og:url' => $url, 'og:locale' => get_locale()) as $name => $value) { $html .= self::tag($name, $value, 'property'); }
        $image_id = get_post_thumbnail_id($post->ID);
        // Only CarryAware pages use the CarryAware icon as an automatic fallback.
        if (!$image_id && str_starts_with($post->post_name, 'carryaware')) {
            $product = get_page_by_path('carryawarenj');
            if ($product) { $image_id = get_post_thumbnail_id($product->ID); }
        }
        $image = $image_id ? wp_get_attachment_image_src($image_id, 'full') : false;
        $html .= self::tag('twitter:card', 'summary') . self::tag('twitter:title', $title) . self::tag('twitter:description', $description);
        if ($image) {
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            foreach (array('og:image' => esc_url_raw($image[0]), 'og:image:width' => absint($image[1]), 'og:image:height' => absint($image[2]), 'og:image:alt' => $alt) as $name => $value) { $html .= self::tag($name, $value, 'property'); }
            $html .= self::tag('twitter:image', esc_url_raw($image[0])) . self::tag('twitter:image:alt', $alt);
        }
        return $html;
    }

    public static function metadata() {
        if (!is_singular('page') || is_feed()) { return; }
        echo self::metadata_html(get_queried_object()); // Every tag value is escaped by tag().
    }
}
