<?php
defined('ABSPATH') || exit;

function adt_get_decision_tree() {
    return [
        'start' => [
            'question' => 'هل تهتم بتحسين ظهور موقعك في محركات البحث (SEO)؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'seo_1', 'score' => 20],
                ['text' => 'لا', 'next' => 'ask_ads', 'score' => 5],
            ],
        ],

        'ask_ads' => [
            'question' => 'هل تهتم بإعلانات Google Ads؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'ads_1', 'score' => 20],
                ['text' => 'لا', 'next' => 'ask_lead', 'score' => 5],
            ],
        ],

        'ask_lead' => [
            'question' => 'هل تبحث عن طرق لزيادة عدد العملاء المحتملين لديك؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'lead_1', 'score' => 20],
                ['text' => 'لا', 'next' => 'recover', 'score' => 5],
            ],
        ],

        // ================= SEO =================
        'seo_1' => [
            'question' => 'هل لديك موقع إلكتروني فعّال حالياً؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'seo_2', 'score' => 25],
                ['text' => 'لا', 'next' => 'recover', 'score' => 10],
            ],
        ],
        'seo_2' => [
            'question' => 'هل ترغب في زيادة عدد الزيارات أو العملاء المحتملين من خلال SEO؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'seo_3', 'score' => 30],
                ['text' => 'لا', 'next' => 'recover', 'score' => 10],
            ],
        ],
        'seo_3' => [
            'question' => 'هل أنت غير راضٍ عن ترتيب موقعك الحالي في نتائج البحث؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'seo_4', 'score' => 35],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'seo_4' => [
            'question' => 'هل ترغب في الحصول على تحليل SEO مجاني لموقعك؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'finish', 'score' => 40],
                ['text' => 'لا', 'next' => 'finish', 'score' => 10],
            ],
        ],

        // ================= GOOGLE ADS =================
        'ads_1' => [
            'question' => 'هل تدير حالياً حملات إعلانية ضمن Google Ads؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'ads_2', 'score' => 25],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'ads_2' => [
            'question' => 'هل الهدف الأساسي لديك هو زيادة المبيعات أو العملاء المحتملين من خلال الإعلانات؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'ads_3', 'score' => 30],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'ads_3' => [
            'question' => 'هل تواجه ارتفاعاً في تكلفة النقرة أو ضعفاً في جودة العملاء المحتملين؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'ads_4', 'score' => 35],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'ads_4' => [
            'question' => 'هل ترغب في مراجعة مجانية لحساب Google Ads الخاص بك؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'finish', 'score' => 40],
                ['text' => 'لا', 'next' => 'finish', 'score' => 10],
            ],
        ],

        // ================= LEAD GENERATION =================
        'lead_1' => [
            'question' => 'هل تواجه صعوبة في الحصول على عملاء محتملين بشكل ثابت؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'lead_2', 'score' => 30],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'lead_2' => [
            'question' => 'هل ترى أن جودة العملاء المحتملين الحاليين ضعيفة أو تكلفتهم مرتفعة؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'lead_3', 'score' => 35],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'lead_3' => [
            'question' => 'هل ترغب في زيادة عدد العملاء المحتملين خلال الأشهر القادمة؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'lead_4', 'score' => 30],
                ['text' => 'لا', 'next' => 'recover', 'score' => 15],
            ],
        ],
        'lead_4' => [
            'question' => 'هل ترغب في الحصول على خطة مجانية لزيادة العملاء المحتملين؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'finish', 'score' => 40],
                ['text' => 'لا', 'next' => 'finish', 'score' => 10],
            ],
        ],

        // ================= RECOVERY (one more chance before exiting) =================
        'recover' => [
            'question' => 'هل ترغب أن نقترح عليك حلاً أنسب لاحتياجك الفعلي؟',
            'answers' => [
                ['text' => 'نعم', 'next' => 'finish', 'score' => 20],
                ['text' => 'لا', 'next' => 'finish', 'score' => 5],
            ],
        ],
    ];
}