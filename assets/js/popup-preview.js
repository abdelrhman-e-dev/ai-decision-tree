document.addEventListener('DOMContentLoaded', function () {
    const content    = document.getElementById('adt-preview-content');
    const resetBtn   = document.getElementById('adt-preview-reset');
    const postId     = adtAdmin.previewPostId;
    const ajaxUrl    = adtAdmin.ajaxUrl;
    const nonce      = adtAdmin.previewNonce;

    if (!content || !postId) return;

    let currentNode   = 'start';
    let currentData   = null;
    let treeSource    = '';

    function fetchNode(nodeId) {
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:  'adt_get_node',
                nonce:   nonce,
                node:    nodeId,
                postId:  postId,
                source:  treeSource,
            }),
        }).then(function (r) { return r.json(); });
    }

    function renderNode() {
        if (currentNode === 'finish') {
            renderCta();
            return;
        }

        content.innerHTML = '<p style="color:var(--adt-muted);font-size:13px;">جارٍ التحميل…</p>';

        fetchNode(currentNode).then(function (res) {
            if (!res.success) {
                content.innerHTML = '<p style="color:#d63638;font-size:13px;">تعذّر تحميل الأسئلة.</p>';
                return;
            }

            currentData = res.data;
            treeSource  = res.data.source || treeSource;

            var html = '<p>' + currentData.question + '</p><div class="adt-answers">';
            currentData.answers.forEach(function (a) {
                html += '<button class="adt-answer" data-next="' + a.next + '">' + a.text + '</button>';
            });
            html += '</div>';

            content.innerHTML = html;

            content.querySelectorAll('.adt-answer').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    currentNode = this.dataset.next;
                    renderNode();
                });
            });
        });
    }

    function renderCta() {
        // Read live values from the fallback CTA fields on the settings page
        var title  = (document.querySelector('[name="fallback_cta_title"]')  || {}).value  || 'هل تحتاج مساعدة؟';
        var text   = (document.querySelector('[name="fallback_cta_text"]')   || {}).value  || '';
        var button = (document.querySelector('[name="fallback_cta_button"]') || {}).value  || 'تحدث مع خبير';

        content.innerHTML =
            '<h4>' + title  + '</h4>' +
            '<p>'  + text   + '</p>'  +
            '<button class="adt-contact">' + button + '</button>';
    }

    // Live-update CTA preview when admin types in fallback fields
    ['fallback_cta_title', 'fallback_cta_text', 'fallback_cta_button'].forEach(function (name) {
        var field = document.querySelector('[name="' + name + '"]');
        if (field) {
            field.addEventListener('input', function () {
                if (currentNode === 'finish') renderCta();
            });
        }
    });

    // Reset button restarts the preview
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            currentNode = 'start';
            treeSource  = '';
            renderNode();
        });
    }

    renderNode();
});