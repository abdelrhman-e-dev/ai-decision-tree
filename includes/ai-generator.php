<?php
defined('ABSPATH') || exit;

/**
 * Entry point: decide whether this post needs an AI-generated tree,
 * and schedule a background job to generate it if so.
 */
function adt_maybe_schedule_generation($post_id) {
    // [SECURITY FIX #2] Only allow scheduling from server-side hooks (e.g. publish_post).
    // Explicitly block calls that originate from front-end AJAX requests made by guests,
    // which could be abused to exhaust paid API quota across all post IDs.
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }

    if (!adt_is_popup_enabled($post_id)) {
        return; // editor turned the popup off for this article — nothing to generate
    }

    if (get_post_meta($post_id, '_adt_ai_tree', true)) {
        return; // already generated
    }

    // Prevents duplicate scheduling from multiple visitors hitting the same
    // ungenerated post, and acts as a cooldown if generation just failed.
    if (get_transient('adt_generating_' . $post_id)) {
        return;
    }
    set_transient('adt_generating_' . $post_id, 1, 5 * MINUTE_IN_SECONDS);

    if (!wp_next_scheduled('adt_generate_questions_event', [$post_id])) {
        wp_schedule_single_event(time(), 'adt_generate_questions_event', [$post_id]);
    }
}

/**
 * Cron callback: ask Gemini for questions + a CTA, build the decision
 * tree, and store it on the post. Any failure is recorded in
 * '_adt_ai_error' so it's visible instead of silently retrying forever.
 */
function adt_generate_ai_questions($post_id) {
    // Never call the AI twice for the same article.
    if (get_post_meta($post_id, '_adt_ai_tree', true)) {
        return;
    }

    if (get_post_type($post_id) !== 'post') {
        return;
    }

    if (!adt_is_popup_enabled($post_id)) {
        return; // editor turned the popup off after this job was scheduled
    }

    $title   = get_the_title($post_id);
    $summary = has_excerpt($post_id)
        ? get_the_excerpt($post_id)
        : wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post_id)), 100);

    $prompt = "بناءً على عنوان المقال وملخصه، أنشئ:
1) 4 أسئلة ذكية بصيغة \"نعم/لا\" لاكتشاف مدى اهتمام القارئ واستعداده لاتخاذ إجراء فعلي.
2) رسالة ختامية (CTA) مخصصة لموضوع المقال تحديداً، تُعرض للقارئ بعد إجابته على الأسئلة.

قواعد الأسئلة:
- كل سؤال إجابته \"نعم\" أو \"لا\" فقط، قصير وواضح ومباشر.
- رتب الأسئلة من العام إلى الأكثر تحديداً، كل سؤال يبني على افتراض أن القارئ أجاب \"نعم\" على ما قبله.
- لكل سؤال درجتان: درجة لإجابة \"نعم\" بين 20 و40، ودرجة لإجابة \"لا\" بين 5 و15.
- ركز على النية الحقيقية والاحتياج والاستعداد، وليس المعرفة النظرية.
- تجنب الأسئلة العامة أو المكررة.

قواعد الرسالة الختامية:
- عنوان قصير وقوي يخاطب القارئ مباشرة ويرتبط بموضوع المقال تحديداً (وليس عاماً).
- جملة وصفية قصيرة (سطر أو سطرين) توضح كيف يمكن مساعدته بناءً على موضوع المقال.
- نص زر دعوة لاتخاذ إجراء قصير ومحفّز.

العنوان:
{$title}

الملخص:
{$summary}

أرجع النتيجة بصيغة JSON فقط بهذا الشكل بالضبط، بدون أي شرح أو نص إضافي:

{
  \"questions\": [
    {\"question\": \"...\", \"yes_score\": 35, \"no_score\": 10}
  ],
  \"cta\": {
    \"title\": \"...\",
    \"text\": \"...\",
    \"button\": \"...\"
  }
}";

    $raw = adt_call_gemini($prompt);

    if (!$raw) {
        update_post_meta($post_id, '_adt_ai_error', [
            'message' => 'Gemini call failed — check error_log',
            'time'    => current_time('mysql'),
        ]);
        return; // adt_get_node() falls back to the static tree
    }

    $raw  = trim(preg_replace('/```json|```/', '', $raw));
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['questions']) || !is_array($data['questions'])) {
        update_post_meta($post_id, '_adt_ai_error', [
            'message' => 'Invalid JSON from Gemini',
            'time'    => current_time('mysql'),
        ]);
        error_log('ADT: invalid AI JSON for post ' . $post_id . ': ' . $raw);
        return;
    }

    // Drop invalid entries first so "next" links never reference a node
    // that doesn't end up in the tree.
    $questions = array_values(array_filter($data['questions'], function ($q) {
        return !empty($q['question']);
    }));

    if (empty($questions)) {
        update_post_meta($post_id, '_adt_ai_error', [
            'message' => 'No usable questions in AI response',
            'time'    => current_time('mysql'),
        ]);
        return;
    }

    $cta = !empty($data['cta']) && is_array($data['cta']) ? $data['cta'] : [];

    $keys = [];
    foreach ($questions as $i => $q) {
        $keys[] = 'ai_q' . ($i + 1);
    }
    $last_index = count($questions) - 1;

    $tree = [];
    foreach ($questions as $i => $q) {
        $next_if_yes = $keys[$i + 1] ?? 'finish';
        $next_if_no  = ($i === $last_index) ? 'finish' : 'recover'; // last question skips the recovery detour

        $tree[$keys[$i]] = [
            'question' => sanitize_text_field($q['question']),
            'answers'  => [
                ['text' => 'نعم', 'next' => $next_if_yes, 'score' => isset($q['yes_score']) ? absint($q['yes_score']) : 30],
                ['text' => 'لا',  'next' => $next_if_no,  'score' => isset($q['no_score']) ? absint($q['no_score']) : 10],
            ],
        ];
    }

    if (empty($tree)) {
        return;
    }

    // Rename the first generated node to "start" so adt_get_node() finds the entry point.
    $first_key = array_key_first($tree);
    $tree['start'] = $tree[$first_key];
    if ($first_key !== 'start') {
        unset($tree[$first_key]);
    }

    // One shared "last chance" question before the funnel actually ends.
    $tree['recover'] = [
        'question' => 'هل ترغب أن نقترح عليك حلاً أنسب لاحتياجك الفعلي؟',
        'answers'  => [
            ['text' => 'نعم', 'next' => 'finish', 'score' => 20],
            ['text' => 'لا',  'next' => 'finish', 'score' => 5],
        ],
    ];

    // Success — clear any error left over from a previous failed attempt.
    delete_post_meta($post_id, '_adt_ai_error');

    update_post_meta($post_id, '_adt_ai_tree', $tree);

    update_post_meta($post_id, '_adt_ai_cta', [
        'title'  => !empty($cta['title']) ? sanitize_text_field($cta['title']) : 'هل أنت مستعد لاتخاذ الخطوة التالية؟',
        'text'   => !empty($cta['text']) ? sanitize_text_field($cta['text']) : 'يمكننا مساعدتك في إيجاد الحل المناسب.',
        'button' => !empty($cta['button']) ? sanitize_text_field($cta['button']) : 'تحدث مع خبير',
    ]);
}

/**
 * Low-level Gemini API call. Returns the raw response text on success,
 * or false (after logging the reason) on any failure.
 */
function adt_call_gemini($prompt) {
    if (!defined('ADT_GEMINI_API_KEY') || !ADT_GEMINI_API_KEY) {
        error_log('ADT: Gemini API key not set');
        return false;
    }

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
        [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => ADT_GEMINI_API_KEY,
            ],
            'body' => wp_json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                ],
            ]),
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) {
        error_log('ADT Gemini error: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || empty($body['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('ADT Gemini bad response (' . $code . '): ' . wp_remote_retrieve_body($response));
        return false;
    }

    return $body['candidates'][0]['content']['parts'][0]['text'];
}

add_action('adt_generate_questions_event', 'adt_generate_ai_questions');
add_action('publish_post', 'adt_maybe_schedule_generation');