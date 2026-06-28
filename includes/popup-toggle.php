<?php
defined('ABSPATH') || exit;

/**
 * Whether the decision-tree popup is allowed to run on this post.
 * Defaults to enabled when the editor hasn't made an explicit choice yet,
 * so existing published articles keep working without needing to be
 * re-saved just to pick up the new setting.
 */
function adt_is_popup_enabled($post_id) {
    $value = get_post_meta($post_id, '_adt_popup_enabled', true);

    if ($value === '') {
        return true; // no explicit choice saved yet — default to on
    }

    return $value === '1';
}

function adt_register_popup_toggle_meta_box() {
    add_meta_box(
        'adt_popup_toggle',
        'النافذة المنبثقة (Decision Tree)',
        'adt_render_popup_toggle_meta_box',
        'post',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'adt_register_popup_toggle_meta_box');

function adt_render_popup_toggle_meta_box($post) {
    wp_nonce_field('adt_save_popup_toggle', 'adt_popup_toggle_nonce');

    $value = get_post_meta($post->ID, '_adt_popup_enabled', true);
    if ($value === '') {
        $value = '1'; // matches adt_is_popup_enabled()'s default
    }

    $ai_enabled   = adt_get_setting('ai_enabled') === '1';
    $has_ai_tree  = !empty(get_post_meta($post->ID, '_adt_ai_tree', true));
    $settings_url = admin_url('options-general.php?page=adt-settings');
    ?>
    <p style="margin-top:0;">
        <label style="display:block;margin-bottom:6px;">
            <input type="radio" name="adt_popup_enabled" value="1" <?php checked($value, '1'); ?>>
            عرض النافذة المنبثقة في هذا المقال
        </label>
        <label style="display:block;">
            <input type="radio" name="adt_popup_enabled" value="0" <?php checked($value, '0'); ?>>
            عدم عرض النافذة المنبثقة في هذا المقال
        </label>
    </p>

    <?php if (!$ai_enabled): ?>
        <div style="margin-top:8px;padding:8px 10px;background:#fff8e5;border-right:3px solid #dba617;border-radius:3px;font-size:12px;line-height:1.5;color:#5a4000;">
            <?php if ($has_ai_tree): ?>
                ⚠️ توليد الأسئلة بالذكاء الاصطناعي <strong>معطّل</strong> حالياً. النافذة تعمل بالأسئلة المُولَّدة مسبقاً لهذا المقال.
            <?php else: ?>
                ⚠️ توليد الأسئلة بالذكاء الاصطناعي <strong>معطّل</strong> من قِبَل المدير. لن تُولَّد أسئلة لهذا المقال، وستستخدم النافذة الأسئلة الثابتة بدلاً منها.
                <br><a href="<?php echo esc_url($settings_url); ?>" style="color:#0e7c7b;">تفعيل الميزة من الإعدادات</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
}

function adt_save_popup_toggle($post_id) {
    if (
        !isset($_POST['adt_popup_toggle_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['adt_popup_toggle_nonce'])), 'adt_save_popup_toggle')
    ) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    if (get_post_type($post_id) !== 'post') {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $value = isset($_POST['adt_popup_enabled'])
        ? sanitize_text_field(wp_unslash($_POST['adt_popup_enabled']))
        : '1';
    $value = ($value === '0') ? '0' : '1'; // whitelist — anything unexpected falls back to "on"

    update_post_meta($post_id, '_adt_popup_enabled', $value);
}
add_action('save_post', 'adt_save_popup_toggle');