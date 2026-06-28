<?php
/*
Plugin Name: AI Decision Tree
Description: Simple Decision Tree Popup
Version: 1.0
Author: Abdelrhman Essam
*/

defined('ABSPATH') || exit;

define('ADT_URL', plugin_dir_url(__FILE__));
define('ADT_PATH', plugin_dir_path(__FILE__));
require_once ADT_PATH . 'includes/decision-tree.php';
require_once ADT_PATH . 'includes/ai-generator.php';
require_once ADT_PATH . 'includes/popup-toggle.php';
require_once ADT_PATH . 'includes/settings.php';

function adt_enqueue_assets() {

    if (!is_single()) {
        return;
    }
if (!adt_is_global_popup_enabled()) return;
    if (!adt_is_popup_enabled(get_the_ID())) {
        return;
    }

    wp_enqueue_style(
        'adt-popup-css',
        ADT_URL . 'assets/css/popup.css',
        [],
        filemtime(ADT_PATH . 'assets/css/popup.css')
    );

    wp_enqueue_script(
        'adt-popup-js',
        ADT_URL . 'assets/js/popup.js',
        [],
        filemtime(ADT_PATH . 'assets/js/popup.js'),
        true
    );

wp_localize_script('adt-popup-js', 'adtData', array_merge(
    [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'postId'  => get_the_ID(),
        'nonce'   => wp_create_nonce('adt_nonce'),
        'ctaLink'   => adt_get_cta_link(),
        'ctaTarget' => adt_get_setting('cta_target') ?? '_blank',
    ],
    adt_get_trigger_settings()
));
}
add_action('wp_enqueue_scripts', 'adt_enqueue_assets');

function adt_render_popup() {
    if (!is_single()) return;
      if (!adt_is_global_popup_enabled()) return; 
    if (!adt_is_popup_enabled(get_the_ID())) return;
    include ADT_PATH . 'templates/popup.php';
}
add_action('wp_footer', 'adt_render_popup');

function adt_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'adt_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        question_id VARCHAR(50) NOT NULL,
        answer TEXT NOT NULL,
        created_at DATETIME NOT NULL
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'adt_create_table');

function adt_maybe_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'adt_logs';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        adt_create_table();
    }
}
add_action('plugins_loaded', 'adt_maybe_create_table');

function adt_save_journey() {
    check_ajax_referer('adt_nonce', 'nonce');

    global $wpdb;
    $table = $wpdb->prefix . 'adt_logs';

    $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
    $journey = isset($_POST['journey']) ? json_decode(stripslashes($_POST['journey']), true) : [];

    if (!is_array($journey)) {
        wp_send_json_error('Invalid journey data');
    }

    // [SECURITY FIX #1] Cap array size to prevent DoS via database flooding.
    // A real tree path can never exceed ~20 steps; anything beyond is malicious.
    if (count($journey) > 20) {
        wp_send_json_error('Journey data exceeds maximum allowed steps');
    }

    $inserted = 0;
    foreach ($journey as $step) {
        $result = $wpdb->insert($table, [
            'post_id'     => $post_id,
            'question_id' => sanitize_text_field($step['node'] ?? ''),
            'answer'      => sanitize_text_field($step['answer'] ?? ''),
            'created_at'  => current_time('mysql'),
        ]);

        if ($result === false) {
            error_log('ADT insert failed: ' . $wpdb->last_error);
        } else {
            $inserted++;
        }
    }

    $cta = adt_get_cta($post_id, $journey);

    wp_send_json_success([
        'inserted' => $inserted,
        'total'    => count($journey),
        'cta'      => $cta,
    ]);
}
function adt_get_cta($post_id, $journey) {
    $ai_enabled = adt_get_setting('ai_enabled') === '1';

    // Only use AI CTA if AI is enabled
    if ($ai_enabled) {
        $ai_cta = get_post_meta($post_id, '_adt_ai_cta', true);
        if (!empty($ai_cta) && is_array($ai_cta)) {
            return $ai_cta;
        }
    }

    // Use the fallback fields from the settings page
    $s = adt_get_settings();
    if (!empty($s['fallback_cta_title'])) {
        return [
            'title'  => $s['fallback_cta_title'],
            'text'   => $s['fallback_cta_text'],
            'button' => $s['fallback_cta_button'],
        ];
    }

    // Last resort: category-based presets
    $category = 'default';
    foreach ($journey as $step) {
        $node = $step['node'] ?? '';
        if (strpos($node, 'seo_') === 0)  { $category = 'seo';  break; }
        if (strpos($node, 'ads_') === 0)  { $category = 'ads';  break; }
        if (strpos($node, 'lead_') === 0) { $category = 'lead'; break; }
    }

    $presets = [
        'seo'     => ['title' => 'هل أنت مستعد لتحسين ظهور موقعك؟',       'text' => 'يمكننا مساعدتك في بناء استراتيجية SEO مخصصة.',          'button' => 'احصل على تحليل SEO مجاني'],
        'ads'     => ['title' => 'هل تريد نتائج أفضل من إعلاناتك؟',        'text' => 'يمكننا مساعدتك في تحسين حملاتك وخفض تكلفة العميل.',     'button' => 'احصل على مراجعة مجانية'],
        'lead'    => ['title' => 'هل تريد المزيد من العملاء المحتملين؟',    'text' => 'يمكننا مساعدتك في بناء نظام مستمر لجذب العملاء.',        'button' => 'احصل على خطة مجانية'],
        'default' => ['title' => 'هل تحتاج مساعدة في تنمية أعمالك؟',       'text' => 'يمكننا مساعدتك في إيجاد الحل المناسب.',                  'button' => 'تحدث مع خبير'],
    ];

    return $presets[$category];
}
function adt_get_node() {
    check_ajax_referer('adt_nonce', 'nonce');

    $node_id = isset($_POST['node']) ? sanitize_text_field($_POST['node']) : 'start';
    $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
    $source  = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : '';

    $ai_enabled = adt_get_setting('ai_enabled') === '1';   // ← add this

    $ai_tree = ($ai_enabled && $post_id) ? get_post_meta($post_id, '_adt_ai_tree', true) : '';
    $has_ai  = !empty($ai_tree) && is_array($ai_tree);

    if ($source === 'ai' && $has_ai) {
        $tree = $ai_tree;
    } elseif ($source === 'static') {
        $tree = adt_get_decision_tree();
    } else {
        // [SECURITY FIX #2] Never schedule AI generation from a guest AJAX request.
        // Generation is triggered exclusively via the publish_post hook (server-side),
        // so anonymous visitors cannot exhaust API quota by iterating post IDs.
        // If no AI tree exists yet, serve the static fallback silently.
        $tree   = $has_ai ? $ai_tree : adt_get_decision_tree();
        $source = $has_ai ? 'ai' : 'static';
    }

    if (!isset($tree[$node_id])) wp_send_json_error('Invalid node');

    $response = $tree[$node_id];
    $response['source'] = $source;
    wp_send_json_success($response);
}
add_action('wp_ajax_adt_get_node', 'adt_get_node');
add_action('wp_ajax_nopriv_adt_get_node', 'adt_get_node');
add_action('wp_ajax_adt_save_journey', 'adt_save_journey');
add_action('wp_ajax_nopriv_adt_save_journey', 'adt_save_journey');