<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════
   DEFAULTS & HELPERS
═══════════════════════════════════════════════════════════ */

function adt_settings_defaults() {
    return [
        // Global
        'enabled'             => '1',

        // Trigger — 0 means disabled for numeric fields
        'scroll_threshold'    => 40,    // % of page; 0 = off
        'timer_delay'         => 0,     // seconds; 0 = off
        'exit_intent'         => '0',
        'frequency_cap'       => 'never',

        // CTA
        'cta_link_type'       => 'url',
        'cta_href'            => '',
        'cta_whatsapp'        => '',
        'cta_tel'             => '',
        'cta_target'          => '_blank',
        'fallback_cta_title'  => 'هل تحتاج مساعدة في تنمية أعمالك؟',
        'fallback_cta_text'   => 'يمكننا مساعدتك في إيجاد الحل المناسب بناءً على احتياجاتك.',
        'fallback_cta_button' => 'تحدث مع خبير',

        // AI
        'ai_enabled'          => '1',
        'ai_provider'         => 'gemini',
        'ai_model'            => 'gemini-2.5-flash',
        'ai_api_key'          => '',
        'ai_question_count'   => 4,
        'ai_language'         => 'formal',
        'ai_custom_persona'   => '',
        'ai_prompt'           => '',

        // Access & visibility
        'post_types'          => ['post'],
        'min_word_count'      => 0,
        'category_mode'       => 'all',
        'category_ids'        => [],

        // Notifications
        'notify_enabled'      => '0',
        'notify_email'        => '',
        'notify_threshold'    => 70,
    ];
}

function adt_get_settings() {
    return wp_parse_args(get_option('adt_settings', []), adt_settings_defaults());
}

function adt_get_setting($key) {
    return adt_get_settings()[$key] ?? null;
}

function adt_default_prompt() {
    return 'بناءً على عنوان المقال وملخصه، أنشئ:
1) {question_count} أسئلة ذكية بصيغة "نعم/لا" لاكتشاف مدى اهتمام القارئ واستعداده لاتخاذ إجراء فعلي.
2) رسالة ختامية (CTA) مخصصة لموضوع المقال تحديداً، تُعرض للقارئ بعد إجابته على الأسئلة.

{persona}قواعد الأسئلة:
- كل سؤال إجابته "نعم" أو "لا" فقط، قصير وواضح ومباشر.
- رتب الأسئلة من العام إلى الأكثر تحديداً، كل سؤال يبني على افتراض أن القارئ أجاب "نعم" على ما قبله.
- لكل سؤال درجتان: درجة لإجابة "نعم" بين 20 و40، ودرجة لإجابة "لا" بين 5 و15.
- ركز على النية الحقيقية والاحتياج والاستعداد، وليس المعرفة النظرية.
- تجنب الأسئلة العامة أو المكررة.

قواعد الرسالة الختامية:
- عنوان قصير وقوي يخاطب القارئ مباشرة ويرتبط بموضوع المقال تحديداً.
- جملة وصفية قصيرة (سطر أو سطرين) توضح كيف يمكن مساعدته.
- نص زر دعوة لاتخاذ إجراء قصير ومحفّز.

العنوان: {title}
الملخص: {summary}

أرجع النتيجة بصيغة JSON فقط بهذا الشكل بالضبط، بدون أي شرح أو نص إضافي:
{"questions": [{"question": "...", "yes_score": 35, "no_score": 10}], "cta": {"title": "...", "text": "...", "button": "..."}}';
}

function adt_post_qualifies($post_id) {
    $s = adt_get_settings();

    $min_words = (int) $s['min_word_count'];
    if ($min_words > 0) {
        $word_count = str_word_count(wp_strip_all_tags(get_post_field('post_content', $post_id)));
        if ($word_count < $min_words) return false;
    }

    if ($s['category_mode'] !== 'all' && !empty($s['category_ids'])) {
        $post_cats = wp_get_post_categories($post_id, ['fields' => 'ids']);
        $overlap   = array_intersect(array_map('intval', $post_cats), array_map('intval', $s['category_ids']));
        if ($s['category_mode'] === 'include' && empty($overlap))   return false;
        if ($s['category_mode'] === 'exclude' && !empty($overlap))  return false;
    }

    return true;
}

function adt_is_global_popup_enabled() {
    return adt_get_setting('enabled') === '1';
}

function adt_get_trigger_settings() {
    $s = adt_get_settings();
    return [
        'scrollPercent' => (int) $s['scroll_threshold'],
        'timerDelay'    => (int) $s['timer_delay'],
        'triggerExit'   => $s['exit_intent'] === '1',
        'frequencyCap'  => $s['frequency_cap'],
    ];
}

function adt_get_cta_link() {
    $s = adt_get_settings();

    switch ($s['cta_link_type']) {
        case 'whatsapp':
            return 'https://wa.me/' . ltrim($s['cta_whatsapp'], '+');
        case 'tel':
            return 'tel:' . $s['cta_tel'];
        default:
            return $s['cta_href'];
    }
}

/* ═══════════════════════════════════════════════════════════
   SAVE HANDLER
═══════════════════════════════════════════════════════════ */

function adt_save_settings() {
    // Only act on our settings page POST
    if (($_GET['page'] ?? '') !== 'adt-settings') return;
    if (!isset($_POST['adt_settings_nonce']))        return;

    if (!wp_verify_nonce(
        sanitize_text_field(wp_unslash($_POST['adt_settings_nonce'])),
        'adt_save_settings'
    )) {
        wp_die('Security check failed', 403);
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions', 403);
    }

    $p = $_POST;

    $allowed_providers = ['gemini', 'openai', 'anthropic'];
    $allowed_models    = [
        'gemini'    => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-2.0-flash-lite', 'gemini-3.1-flash-lite'],
        'openai'    => ['gpt-4o', 'gpt-4o-mini', 'o3', 'o3-mini', 'o1', 'gpt-4-turbo'],
        'anthropic' => ['claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
    ];

    $provider = in_array($p['ai_provider'] ?? '', $allowed_providers, true)
        ? sanitize_text_field($p['ai_provider']) : 'gemini';

    $model = in_array($p['ai_model'] ?? '', $allowed_models[$provider], true)
        ? sanitize_text_field($p['ai_model']) : $allowed_models[$provider][0];

    $settings = [
        'enabled'             => isset($p['enabled']) ? '1' : '0',
        'scroll_threshold'    => min(100, max(0, absint($p['scroll_threshold'] ?? 40))),
        'timer_delay'         => min(300, max(0, absint($p['timer_delay'] ?? 0))),
        'exit_intent'         => isset($p['exit_intent']) ? '1' : '0',
        'frequency_cap'       => in_array($p['frequency_cap'] ?? '', ['never', '1', '7', '30', '90'], true)
                                    ? sanitize_text_field($p['frequency_cap']) : 'never',
        'cta_link_type'       => in_array($p['cta_link_type'] ?? '', ['url', 'whatsapp', 'tel'], true)
                                    ? sanitize_text_field($p['cta_link_type']) : 'url',
        'cta_href'            => esc_url_raw($p['cta_href'] ?? ''),
        'cta_whatsapp'        => preg_replace('/[^0-9+]/', '', $p['cta_whatsapp'] ?? ''),
        'cta_tel'             => preg_replace('/[^0-9+]/', '', $p['cta_tel'] ?? ''),
        'cta_target'          => ($p['cta_target'] ?? '_blank') === '_self' ? '_self' : '_blank',
        'fallback_cta_title'  => sanitize_text_field($p['fallback_cta_title'] ?? ''),
        'fallback_cta_text'   => sanitize_textarea_field($p['fallback_cta_text'] ?? ''),
        'fallback_cta_button' => sanitize_text_field($p['fallback_cta_button'] ?? ''),
        'ai_enabled'          => isset($p['ai_enabled']) ? '1' : '0',
        'ai_provider'         => $provider,
        'ai_model'            => $model,
        'ai_api_key'          => sanitize_text_field($p['ai_api_key'] ?? ''),
        'ai_question_count'   => min(8, max(2, absint($p['ai_question_count'] ?? 4))),
        'ai_language'         => in_array($p['ai_language'] ?? '', ['formal', 'gulf', 'egyptian', 'custom'], true)
                                    ? sanitize_text_field($p['ai_language']) : 'formal',
        'ai_custom_persona'   => sanitize_textarea_field($p['ai_custom_persona'] ?? ''),
        'ai_prompt'           => sanitize_textarea_field($p['ai_prompt'] ?? ''),
        'post_types'          => array_map('sanitize_text_field', (array) ($p['post_types'] ?? ['post'])),
        'min_word_count'      => absint($p['min_word_count'] ?? 0),
        'category_mode'       => in_array($p['category_mode'] ?? '', ['all', 'include', 'exclude'], true)
                                    ? sanitize_text_field($p['category_mode']) : 'all',
        'category_ids'        => array_map('absint', (array) ($p['category_ids'] ?? [])),
        'notify_enabled'      => isset($p['notify_enabled']) ? '1' : '0',
        'notify_email'        => sanitize_email($p['notify_email'] ?? ''),
        'notify_threshold'    => min(200, max(0, absint($p['notify_threshold'] ?? 70))),
    ];

    update_option('adt_settings', $settings);

    // PRG: redirect so browser refresh won't re-submit the form
    wp_safe_redirect(admin_url('options-general.php?page=adt-settings&updated=1'));
    exit;
}
add_action('admin_init', 'adt_save_settings');

/* ═══════════════════════════════════════════════════════════
   AJAX: TEST API  (defined here — was missing from ai-generator.php)
═══════════════════════════════════════════════════════════ */

function adt_call_ai_test($provider, $model, $api_key) {
    $prompt = 'قل "مرحبا" فقط.';

    switch ($provider) {
        case 'gemini':
            $res  = wp_remote_post(
                'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
                ['headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $api_key],
                 'body'    => wp_json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
                 'timeout' => 15]
            );
            if (is_wp_error($res)) return false;
            $body = json_decode(wp_remote_retrieve_body($res), true);
            return wp_remote_retrieve_response_code($res) === 200
                && !empty($body['candidates'][0]['content']['parts'][0]['text']);

        case 'openai':
            $res  = wp_remote_post(
                'https://api.openai.com/v1/chat/completions',
                ['headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
                 'body'    => wp_json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 10]),
                 'timeout' => 15]
            );
            if (is_wp_error($res)) return false;
            $body = json_decode(wp_remote_retrieve_body($res), true);
            return wp_remote_retrieve_response_code($res) === 200
                && !empty($body['choices'][0]['message']['content']);

        case 'anthropic':
            $res  = wp_remote_post(
                'https://api.anthropic.com/v1/messages',
                ['headers' => ['Content-Type' => 'application/json', 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01'],
                 'body'    => wp_json_encode(['model' => $model, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
                 'timeout' => 15]
            );
            if (is_wp_error($res)) return false;
            $body = json_decode(wp_remote_retrieve_body($res), true);
            return wp_remote_retrieve_response_code($res) === 200
                && !empty($body['content'][0]['text']);
    }
    return false;
}

function adt_ajax_test_api() {
    check_ajax_referer('adt_test_api', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $provider = sanitize_text_field($_POST['provider'] ?? '');
    $model    = sanitize_text_field($_POST['model']    ?? '');
    $api_key  = sanitize_text_field($_POST['api_key']  ?? '');

    if (empty($api_key)) wp_send_json_error('أدخل مفتاح API أولاً.');

    if (adt_call_ai_test($provider, $model, $api_key)) {
        wp_send_json_success(' الاتصال ناجح');
    } else {
        wp_send_json_error(' فشل الاتصال — تحقق من المفتاح والنموذج المختار.');
    }
}
add_action('wp_ajax_adt_test_api', 'adt_ajax_test_api');

/* ═══════════════════════════════════════════════════════════
   AJAX: DANGER ZONE
═══════════════════════════════════════════════════════════ */

function adt_ajax_danger_zone() {
    check_ajax_referer('adt_danger_zone', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    global $wpdb;
    $action = sanitize_text_field($_POST['danger_action'] ?? '');

    switch ($action) {
        case 'reset_trees':
            $wpdb->delete($wpdb->postmeta, ['meta_key' => '_adt_ai_tree']);
            $wpdb->delete($wpdb->postmeta, ['meta_key' => '_adt_ai_cta']);
            $wpdb->delete($wpdb->postmeta, ['meta_key' => '_adt_ai_error']);
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_adt_generating_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_adt_generating_%'");
            wp_send_json_success('تم حذف جميع الأشجار المولّدة. ستُعاد توليدها تلقائياً عند الزيارة التالية لكل مقال.');
            break;

        case 'reset_toggles':
            $wpdb->delete($wpdb->postmeta, ['meta_key' => '_adt_popup_enabled']);
            wp_send_json_success('تم إعادة ضبط حالة النافذة لجميع المقالات إلى الافتراضي.');
            break;

        case 'bump_storage':
            update_option('adt_storage_version', absint(get_option('adt_storage_version', 1)) + 1);
            wp_send_json_success('تم تحديث مفتاح التخزين. سيُعامَل جميع الزوار السابقين كزوار جدد وستظهر لهم النافذة مجدداً.');
            break;

        default:
            wp_send_json_error('إجراء غير معروف.');
    }
}
add_action('wp_ajax_adt_danger_zone', 'adt_ajax_danger_zone');

/* ═══════════════════════════════════════════════════════════
   ADMIN MENU + ASSETS
═══════════════════════════════════════════════════════════ */

function adt_register_settings_page() {
    add_options_page(
        'إعدادات AI Decision Tree', 'AI Decision Tree',
        'manage_options', 'adt-settings', 'adt_render_settings_page'
    );
}
add_action('admin_menu', 'adt_register_settings_page');

function adt_enqueue_settings_assets($hook) {
    if ($hook !== 'settings_page_adt-settings') return;

    $css_path = ADT_PATH . 'assets/css/settings.css';
    if (file_exists($css_path)) {
        wp_enqueue_style('adt-settings-css', ADT_URL . 'assets/css/settings.css', [], filemtime($css_path));
    }

    wp_enqueue_script(
        'adt-settings-js', ADT_URL . 'assets/js/settings.js',
        ['jquery'], filemtime(ADT_PATH . 'assets/js/settings.js'), true
    );

    wp_enqueue_style('adt-popup-css', ADT_URL . 'assets/css/popup.css', [], filemtime(ADT_PATH . 'assets/css/popup.css'));
    wp_enqueue_style('adt-preview-css', ADT_URL . 'assets/css/popup-preview.css', ['adt-popup-css'], '1.0');
    wp_enqueue_script('adt-preview-js', ADT_URL . 'assets/js/popup-preview.js', ['jquery'], '1.0', true);
    wp_localize_script('adt-settings-js', 'adtAdmin', [
        'ajaxUrl'       => admin_url('admin-ajax.php'),
        'dangerNonce'   => wp_create_nonce('adt_danger_zone'),
        'testNonce'     => wp_create_nonce('adt_test_api'),
        'defaultPrompt' => adt_default_prompt(),
        'previewNonce'  => wp_create_nonce('adt_nonce'),
        'previewPostId' => (function() {
            $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'meta_key' => '_adt_ai_tree']);
            return $posts ? $posts[0]->ID : 0;
        })(),
        'models'        => [
            'gemini'    => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-2.0-flash-lite', 'gemini-3.1-flash-lite'],
            'openai'    => ['gpt-4o', 'gpt-4o-mini', 'o3', 'o3-mini', 'o1', 'gpt-4-turbo'],
            'anthropic' => ['claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'adt_enqueue_settings_assets');

/* ═══════════════════════════════════════════════════════════
   RENDER
═══════════════════════════════════════════════════════════ */

function adt_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $s              = adt_get_settings();
    $models_map     = [
        'gemini'    => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-2.0-flash-lite', 'gemini-3.1-flash-lite'],
        'openai'    => ['gpt-4o', 'gpt-4o-mini', 'o3', 'o3-mini', 'o1', 'gpt-4-turbo'],
        'anthropic' => ['claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
    ];
    $current_models = $models_map[$s['ai_provider']] ?? $models_map['gemini'];
    ?>
    
    <div class="adt-settings-wrap">
        <h1>إعدادات AI Decision Tree</h1>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible">
                <p>تم حفظ الإعدادات بنجاح ودمج واجهة العميل المحدثة.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('adt_save_settings', 'adt_settings_nonce'); ?>

            <h2>الإعدادات العامة</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">حالة الإضافة</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'], '1'); ?>>
                            تشغيل النافذة المنبثقة على الموقع
                        </label>
                        <p class="description">عند إيقاف التشغيل لن تظهر النافذة لأي زائر على أي صفحة بشكل قاطع.</p>
                    </td>
                </tr>
            </table>

            <h2>متى تظهر النافذة؟</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="scroll_threshold">نسبة التمرير</label></th>
                    <td>
                        <input type="number" id="scroll_threshold" name="scroll_threshold"
                               value="<?php echo esc_attr($s['scroll_threshold']); ?>" min="0" max="100" class="small-text"> %
                        <p class="description">تظهر النافذة بعد تمرير هذه النسبة من المقال. اضبطه على 0 لتعطيل الميزة. (الافتراضي: 40%)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="timer_delay">التأخير الزمني</label></th>
                    <td>
                        <input type="number" id="timer_delay" name="timer_delay"
                               value="<?php echo esc_attr($s['timer_delay']); ?>" min="0" max="300" class="small-text"> ثانية
                        <p class="description">تظهر النافذة تلقائياً بعد مرور هذا الوقت منذ فتح الصفحة. 0 تعني معطّل.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">نية المغادرة</th>
                    <td>
                        <label>
                            <input type="checkbox" name="exit_intent" value="1" <?php checked($s['exit_intent'], '1'); ?>>
                            إظهار النافذة عند محاولة الزائر مغادرة الصفحة (Exit Intent)
                        </label>
                        <p class="description">يعمل على الأجهزة المكتبية فقط من خلال رصد تحرك مؤشر الماوس نحو شريط عنوان المتصفح.</p>
                    </td>
                </tr>
            </table>

            <h2>معاينة النافذة المنبثقة</h2>
            <div style="overflow:hidden;">
            <div class="adt-preview-wrapper " role="presentation">
                <div class="adt-preview-frame">
                    <div class="adt-popup show" role="dialog" aria-modal="true" style="position:relative;top:auto;left:auto;transform:none;display:block;">
                        <button class="adt-close" type="button" id="adt-preview-reset" aria-label="إعادة تشغيل">↺</button>
                        <div class="adt-header">
                            <span class="adt-eyebrow">مساعد سريع</span>
                            <h3>هل تحتاج إلى مساعدة؟</h3>
                        </div>
                        <div id="adt-preview-content" class="adt-content">
                            <p style="color:var(--adt-muted);font-size:13px;">جارٍ تحميل المعاينة…</p>
                        </div>
                    </div>
                </div>
                <?php $preview_post_id = (function() { $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'meta_key' => '_adt_ai_tree']); return $posts ? $posts[0]->ID : 0; })(); if (!$preview_post_id): ?>
                <p style="color:#d63638;margin-top:12px;">⚠️ لا يوجد مقال منشور يحتوي على أسئلة مولّدة بعد. ستظهر المعاينة بعد توليد أول شجرة.</p>
                <?php endif; ?>
            </div>
         </div>
            <h2>الزر الختامي (CTA)</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">نوع الرابط</th>
                    <td>
                        <fieldset>
                            <?php foreach (['url' => 'رابط ويب مباشر URL', 'whatsapp' => 'محادثة واتساب مباشر', 'tel' => 'اتصال هاتفي'] as $val => $label): ?>
                                <label style="margin-left: 25px; display: inline-block;">
                                    <input type="radio" name="cta_link_type" value="<?php echo esc_attr($val); ?>"
                                           class="adt-link-type" <?php checked($s['cta_link_type'], $val); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>
                <tr class="adt-link-field-url" <?php echo $s['cta_link_type'] !== 'url' ? 'style="display:none"' : ''; ?>>
                    <th scope="row"><label for="cta_href">رابط الـ URL</label></th>
                    <td>
                        <input type="url" id="cta_href" name="cta_href" dir="ltr" value="<?php echo esc_attr($s['cta_href']); ?>" class="regular-text" placeholder="https://example.com/contact">
                    </td>
                </tr>
                <tr class="adt-link-field-whatsapp" <?php echo $s['cta_link_type'] !== 'whatsapp' ? 'style="display:none"' : ''; ?>>
                    <th scope="row"><label for="cta_whatsapp">رقم الواتساب الدولي</label></th>
                    <td>
                        <input type="text" id="cta_whatsapp" name="cta_whatsapp" dir="ltr" value="<?php echo esc_attr($s['cta_whatsapp']); ?>" class="regular-text" placeholder="201012345678">
                        <p class="description">أدخل الرقم الدولي كاملاً متبوعاً بكود الدولة وبدون رمز (+) أو أي مسافات.</p>
                    </td>
                </tr>
                <tr class="adt-link-field-tel" <?php echo $s['cta_link_type'] !== 'tel' ? 'style="display:none"' : ''; ?>>
                    <th scope="row"><label for="cta_tel">رقم الهاتف</label></th>
                    <td>
                        <input type="text" id="cta_tel" name="cta_tel" dir="ltr" value="<?php echo esc_attr($s['cta_tel']); ?>" class="regular-text" placeholder="+201012345678">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cta_target">طريقة فتح الرابط</label></th>
                    <td>
                        <select id="cta_target" name="cta_target">
                            <option value="_blank" <?php selected($s['cta_target'], '_blank'); ?>>في نافذة جديدة مستقلة (_blank)</option>
                            <option value="_self"  <?php selected($s['cta_target'], '_self'); ?>>في نفس النافذة الحالية (_self)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="border-top:1px dashed var(--adt-border); padding-top:25px;">محتوى الـ CTA الاحتياطي</th>
                    <td style="border-top:1px dashed var(--adt-border); padding-top:25px;">
                        <p class="description" style="margin-bottom:15px; background: var(--adt-primary-light); padding: 10px 14px; border-radius: 8px; color: var(--adt-primary);">
                            ⚠️ يُعرض هذا المحتوى التلقائي كخيار بديل في حال تعذر التوليد أو سلك الزائر مساراً عاماً لا يغطي الأسئلة الذكية.
                        </p>
                        <label style="display:block; margin-bottom:12px;">
                            <span style="font-weight:700; font-size:13px; color:var(--adt-text); display:inline-block; margin-bottom:5px;">العنوان الرئيسي</span>
                            <input type="text" name="fallback_cta_title" value="<?php echo esc_attr($s['fallback_cta_title']); ?>" class="large-text">
                        </label>
                        <label style="display:block; margin-bottom:12px;">
                            <span style="font-weight:700; font-size:13px; color:var(--adt-text); display:inline-block; margin-bottom:5px;">النص التوضيحي المساعد</span>
                            <textarea name="fallback_cta_text" rows="3" class="large-text"><?php echo esc_textarea($s['fallback_cta_text']); ?></textarea>
                        </label>
                        <label style="display:block;">
                            <span style="font-weight:700; font-size:13px; color:var(--adt-text); display:inline-block; margin-bottom:5px;">نص زر الإجراء</span>
                            <input type="text" name="fallback_cta_button" value="<?php echo esc_attr($s['fallback_cta_button']); ?>" class="regular-text">
                        </label>
                    </td>
                </tr>
            </table>

            <h2>إعدادات محرك الذكاء الاصطناعي</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">التوليد التلقائي عبر الـ API</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ai_enabled" value="1" <?php checked($s['ai_enabled'], '1'); ?>>
                            توليد الأسئلة بشكل ديناميكي لكل مقال على حدة
                        </label>
                        <p class="description">عند التعطيل، ستعتمد الإضافة كلياً على مصفوفة الأسئلة الثابتة والمدمجة افتراضياً.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_provider">مزود الخدمة</label></th>
                    <td>
                        <select id="ai_provider" name="ai_provider">
                            <option value="gemini"    <?php selected($s['ai_provider'], 'gemini'); ?>>Google Gemini API</option>
                            <option value="openai"    <?php selected($s['ai_provider'], 'openai'); ?>>OpenAI GPT Service</option>
                            <option value="anthropic" <?php selected($s['ai_provider'], 'anthropic'); ?>>Anthropic Claude Engine</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_model">نموذج التوليد المستهدف (Model)</label></th>
                    <td>
                        <select id="ai_model" name="ai_model">
                            <?php foreach ($current_models as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected($s['ai_model'], $m); ?>><?php echo esc_html($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_api_key">مفتاح بروتوكول الاتصال (API Key)</label></th>
                    <td>
                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            <input type="password" id="ai_api_key" name="ai_api_key" dir="ltr"
                                   value="<?php echo esc_attr($s['ai_api_key']); ?>" class="regular-text" autocomplete="new-password" style="flex:1; min-width:250px;">
                            <button type="button" id="adt-test-api" class="button">اختبار جودة الاتصال</button>
                        </div>
                        <div id="adt-test-result" style="display:none; margin-top:12px;"></div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_question_count">عدد الأسئلة المطلوبة</label></th>
                    <td>
                        <input type="number" id="ai_question_count" name="ai_question_count"
                               value="<?php echo esc_attr($s['ai_question_count']); ?>" min="2" max="8" class="small-text">
                        <p class="description">النطاق المتاح من 2 إلى 8 أسئلة في الرحلة الواحدة. (الافتراضي: 4)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_language">لهجة وأسلوب صياغة الأسئلة</label></th>
                    <td>
                        <select id="ai_language" name="ai_language">
                            <option value="formal"   <?php selected($s['ai_language'], 'formal'); ?>>اللغة العربية الفصحى</option>
                            <option value="gulf"     <?php selected($s['ai_language'], 'gulf'); ?>>اللهجة الخليجية</option>
                            <option value="egyptian" <?php selected($s['ai_language'], 'egyptian'); ?>>اللهجة المصرية العادية</option>
                            <option value="custom"   <?php selected($s['ai_language'], 'custom'); ?>>تخصيص أسلوب مسبق (اكتبه بالأسفل)</option>
                        </select>
                        <div id="adt-custom-persona" style="margin-top:12px; <?php echo $s['ai_language'] !== 'custom' ? 'display:none' : ''; ?>">
                            <textarea name="ai_custom_persona" rows="3" class="large-text"
                                      placeholder="مثال: خاطب القارئ بروح استشارية، موضحاً الفوائد المباشرة لرواد الأعمال المبتدئين..."><?php echo esc_textarea($s['ai_custom_persona']); ?></textarea>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_prompt">هيكل وتوجيهات الـ Prompt</label></th>
                    <td>
                        <textarea id="ai_prompt" name="ai_prompt" rows="12" class="large-text" dir="rtl"><?php echo esc_textarea($s['ai_prompt'] ?: adt_default_prompt()); ?></textarea>
                        <p class="description" style="margin-top:8px;">
                            المتغيرات الديناميكية المدعومة حالياً: <code>{title}</code> · <code>{summary}</code> · <code>{question_count}</code> · <code>{persona}</code>
                        </p>
                        <button type="button" id="adt-reset-prompt" class="button button-secondary" style="margin-top:10px;">
                             استعادة الـ Prompt الافتراضي للإضافة
                        </button>
                    </td>
                </tr>
            </table>

            <p class="submit" style="margin-top:35px; text-align: left;">
                <?php submit_button('حفظ وتطبيق الإعدادات', 'primary large', 'submit', false); ?>
            </p>
        </form>

        <div style="margin-top:55px; border: 2px solid var(--adt-danger); border-radius: var(--adt-radius); padding: 25px; background: var(--adt-danger-bg); box-shadow: var(--adt-shadow);">
            <h3 style="color: var(--adt-danger); margin-top:0; font-size: 18px; font-weight:800; display:flex; align-items:center; gap:8px;">
                 منطقة العمليات الحساسة (Danger Zone)
            </h3>
            <p style="color: var(--adt-muted); margin-bottom:20px; font-size:13px;">هذه العمليات تقوم بتعديلات فورية ومباشرة على قاعدة البيانات ولا يمكن التراجع عنها بعد التنفيذ. يرجى المتابعة بوعي.</p>
            <div style="display:flex; flex-wrap:wrap; gap:12px;">
                <button type="button" class="button button-secondary adt-danger"
                        data-action="reset_trees"
                        data-confirm="سيتم إزالة وحذف كافة الأشجار والأسئلة المخزنة حالياً في الكاش وقاعدة البيانات ليتم إعادة بنائها بالذكاء الاصطناعي مع أول زيارة قادمة لكل مقال. هل أنت متأكد؟">
                    حذف جميع الأشجار المولّدة سابقاً
                </button>
                <button type="button" class="button button-secondary adt-danger"
                        data-action="bump_storage"
                        data-confirm="سيتم تصفير كاش المتصفحات لجميع الزوار والمستخدمين الذين أغلقوا النافذة المنبثقة قديماً، مما يضمن ظهور النافذة لهم من جديد فوراً. هل أنت متأكد؟">
                    إعادة تفعيل العرض لجميع الزوار السابقين
                </button>
            </div>
            <div id="adt-danger-result" style="margin-top:15px; font-weight: 600; font-size: 13px; display: none;"></div>
        </div>

    </div>

    <?php
}