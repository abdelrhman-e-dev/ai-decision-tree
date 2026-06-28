(function ($) {
    'use strict';

    const models = adtAdmin.models;

    /* ── Provider → model dropdown ───────────────────────────── */
    $('#ai_provider').on('change', function () {
        const provider    = $(this).val();
        const $model      = $('#ai_model');
        const currentVal  = $model.val();
        const provModels  = models[provider] || [];

        $model.empty();
        provModels.forEach(function (m) {
            $model.append(new Option(m, m, m === currentVal, m === currentVal));
        });
        // If previous value isn't in new list, just select first
        if (!provModels.includes(currentVal)) {
            $model.val(provModels[0] || '');
        }
    });

    /* ── CTA link type show/hide ─────────────────────────────── */
    function updateLinkFields() {
        const type = $('input[name="cta_link_type"]:checked').val();
        $('.adt-link-field-url, .adt-link-field-whatsapp, .adt-link-field-tel').hide();
        if (type) {
            $('.adt-link-field-' + type).show();
        }
    }
    $('input.adt-link-type').on('change', updateLinkFields);

    /* ── Category mode show/hide ─────────────────────────────── */
    function updateCategoryList() {
        const mode = $('input[name="category_mode"]:checked').val();
        $('#adt-category-list').toggle(mode !== 'all');
    }
    $('input.adt-cat-mode').on('change', updateCategoryList);

    /* ── Custom persona show/hide ────────────────────────────── */
    $('#ai_language').on('change', function () {
        $('#adt-custom-persona').toggle($(this).val() === 'custom');
    });

    /* ── Test API connection ─────────────────────────────────── */
    $('#adt-test-api').on('click', function () {
        const $btn    = $(this);
        const $result = $('#adt-test-result');

        $btn.prop('disabled', true).text('جارٍ الاختبار…');
        $result.hide();

        $.post(adtAdmin.ajaxUrl, {
            action:   'adt_test_api',
            nonce:    adtAdmin.testNonce,
            provider: $('#ai_provider').val(),
            model:    $('#ai_model').val(),
            api_key:  $('#ai_api_key').val(),
        })
        .done(function (res) {
            $result
                .text(res.success ? res.data : res.data)
                .css('color', res.success ? '#00a32a' : '#dc3232')
                .show();
        })
        .fail(function () {
            $result.text('✗ فشل الاتصال بالخادم').css('color', '#dc3232').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('اختبار الاتصال');
        });
    });

    /* ── Reset prompt to default ─────────────────────────────── */
    $('#adt-reset-prompt').on('click', function () {
        if (confirm('سيتم استبدال نص الـ Prompt الحالي بالنص الافتراضي. هل أنت متأكد؟')) {
            $('#ai_prompt').val(adtAdmin.defaultPrompt);
        }
    });

    /* ── Danger zone ─────────────────────────────────────────── */
    $('.adt-danger').on('click', function () {
        const $btn    = $(this);
        const action  = $btn.data('action');
        const message = $btn.data('confirm');
        const $result = $('#adt-danger-result');

        if (!confirm(message)) {
            return;
        }

        $btn.prop('disabled', true);
        $result.hide();

        $.post(adtAdmin.ajaxUrl, {
            action:        'adt_danger_zone',
            nonce:         adtAdmin.dangerNonce,
            danger_action: action,
        })
        .done(function (res) {
            $result
                .text((res.success ? '✓ ' : '✗ ') + res.data)
                .css('color', res.success ? '#00a32a' : '#dc3232')
                .show();
        })
        .fail(function () {
            $result.text('✗ فشل الاتصال بالخادم').css('color', '#dc3232').show();
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

})(jQuery);