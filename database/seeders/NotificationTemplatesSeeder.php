<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * القوالب الجاهزة لمكتبة الإشعارات.
 *
 * تُطابَق بالقسم والمناسبة لا بالعنوان: إعادة التشغيل لا تُكرّر القوالب،
 * ولا تدهس نصًّا عدّله المستخدم — الموجود يُترك كما هو.
 */
class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $index => $template) {
            $exists = NotificationTemplate::withTrashed()
                ->where('category', $template['category'])
                ->where('event', $template['event'])
                ->exists();

            if ($exists) {
                continue;
            }

            NotificationTemplate::create($template + ['is_active' => true, 'sort_order' => $index]);
        }
    }

    /**
     * @return list<array{category: string, event: string, title: string, body: string}>
     */
    private function templates(): array
    {
        return [
            // ===================== عام =====================
            [
                'category' => 'general',
                'event' => 'welcome',
                'title' => 'ترحيب بعميل جديد',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'أهلاً بك في {business_name}.',
                    'سعدنا بانضمامك، ونحن في خدمتك لأي استفسار أو حجز.',
                ]),
            ],
            [
                'category' => 'general',
                'event' => 'booking_confirm',
                'title' => 'تأكيد حجز — عام',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم تأكيد حجزكم رقم {reference} في {business_name}.',
                    'الوحدة: {unit}',
                    'التاريخ: {date} — {period}',
                    'الإجمالي: {total} | المسدَّد: {paid} | المتبقي: {remaining}',
                    'شكراً لثقتكم بنا 🌿',
                ]),
            ],
            [
                'category' => 'general',
                'event' => 'invoice',
                'title' => 'فاتورة — عام',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'فاتورة حجزكم رقم {reference} لدى {business_name}:',
                    'الوحدة: {unit}',
                    'التاريخ: {date}',
                    'الإجمالي: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    'شكراً لتعاملكم معنا.',
                ]),
            ],

            // ===================== الشاليهات =====================
            [
                'category' => 'chalet',
                'event' => 'welcome',
                'title' => 'ترحيب — عملاء الشاليهات',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'أهلاً بك في {business_name} 🏝️',
                    'شاليهاتنا بمسابح خاصة وخصوصية تامة، وفريقنا جاهز لمساعدتك في اختيار الشاليه والموعد المناسبين.',
                    'تفضّل بالتواصل معنا في أي وقت.',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'booking_confirm',
                'title' => 'تأكيد حجز شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم تأكيد حجز الشاليه بنجاح ✅',
                    'رقم الحجز: {reference}',
                    'الشاليه: {unit}',
                    'تاريخ الدخول: {date} — الفترة: {period}',
                    'الإجمالي: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '',
                    '📌 يُرجى إحضار الهوية الوطنية عند الاستلام، والالتزام بموعد الإخلاء.',
                    'نتشرّف باستقبالكم في {business_name} 🌿',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'invoice',
                'title' => 'فاتورة حجز شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'مرفق فاتورة حجز الشاليه رقم {reference} 🧾',
                    'الشاليه: {unit}',
                    'التاريخ: {date} — {period}',
                    '——————————————',
                    'إجمالي الحجز: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '——————————————',
                    'شكراً لاختياركم {business_name} 🏝️',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'reminder',
                'title' => 'تذكير بحجز شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تذكير بحجز الشاليه 🏝️',
                    'رقم الحجز: {reference}',
                    'الشاليه: {unit}',
                    'موعد الدخول: {date} — {period}',
                    'المتبقي: {remaining}',
                    '',
                    '📌 يُرجى إحضار الهوية الوطنية عند الاستلام والالتزام بموعد الإخلاء.',
                    'نتشرّف باستقبالكم في {business_name} 🌿',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'balance_reminder',
                'title' => 'تذكير بالمتبقي — شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تذكير بالمبلغ المتبقي على حجز الشاليه رقم {reference}.',
                    'الشاليه: {unit}',
                    'موعد الدخول: {date}',
                    'إجمالي الحجز: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    'نرجو السداد قبل الموعد. شكراً لكم 🏝️',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'payment',
                'title' => 'إشعار سداد — شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم استلام مبلغ {amount} على حجز الشاليه رقم {reference} ✅',
                    'الشاليه: {unit}',
                    'المتبقي: {remaining}',
                    'شكراً لكم — {business_name} 🏝️',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'cancellation',
                'title' => 'إلغاء حجز شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'نفيدكم بإلغاء حجز الشاليه رقم {reference}.',
                    'الشاليه: {unit}',
                    'الموعد: {date}',
                    'السبب: {reason}',
                    'المبلغ المسدَّد: {paid} — سيتم التواصل معكم بشأنه.',
                    'نأسف لذلك، ونسعد بخدمتكم في إقامة قادمة 🏝️',
                ]),
            ],
            [
                'category' => 'chalet',
                'event' => 'contract',
                'title' => 'إرسال عقد شاليه',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'مرفق عقد حجز الشاليه رقم {reference} 📄',
                    'رقم العقد: {contract_number}',
                    'الشاليه: {unit}',
                    'تاريخ الدخول: {date} — {period}',
                    'نرجو الاطلاع والتأكيد.',
                    '{business_name} 🏝️',
                ]),
            ],

            // ===================== القاعات =====================
            [
                'category' => 'hall',
                'event' => 'welcome',
                'title' => 'ترحيب — عملاء القاعات',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'أهلاً بك في {business_name} 🏛️',
                    'قاعاتنا مجهّزة لاستقبال مناسباتكم من الأفراح إلى الاجتماعات، مع باقات ضيافة متكاملة.',
                    'يسعدنا خدمتك واختيار الباقة الأنسب لمناسبتك.',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'booking_confirm',
                'title' => 'تأكيد حجز قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم تأكيد حجز القاعة بنجاح ✅',
                    'رقم الحجز: {reference}',
                    'القاعة: {unit}',
                    'تاريخ المناسبة: {date} — الفترة: {period}',
                    'الإجمالي: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '',
                    '📌 يُرجى تزويدنا بعدد الحضور النهائي وتفاصيل الضيافة قبل الموعد بثلاثة أيام.',
                    'مبارك لكم مقدماً، ونتشرّف بخدمتكم في {business_name} 🌿',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'invoice',
                'title' => 'فاتورة حجز قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'مرفق فاتورة حجز القاعة رقم {reference} 🧾',
                    'القاعة: {unit}',
                    'تاريخ المناسبة: {date} — {period}',
                    '——————————————',
                    'إجمالي الحجز: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '——————————————',
                    'شكراً لاختياركم {business_name} 🏛️',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'reminder',
                'title' => 'تذكير بمناسبة قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تذكير بمناسبتكم القادمة 🏛️',
                    'رقم الحجز: {reference}',
                    'القاعة: {unit}',
                    'تاريخ المناسبة: {date} — {period}',
                    'المتبقي: {remaining}',
                    '',
                    '📌 يُرجى تزويدنا بعدد الحضور النهائي وتفاصيل الضيافة قبل الموعد بثلاثة أيام.',
                    'نتشرّف بخدمتكم في {business_name} 🌿',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'balance_reminder',
                'title' => 'تذكير بالمتبقي — قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تذكير بالمبلغ المتبقي على حجز القاعة رقم {reference}.',
                    'القاعة: {unit}',
                    'تاريخ المناسبة: {date}',
                    'إجمالي الحجز: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    'نرجو السداد قبل الموعد. شكراً لكم 🏛️',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'payment',
                'title' => 'إشعار سداد — قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم استلام مبلغ {amount} على حجز القاعة رقم {reference} ✅',
                    'القاعة: {unit}',
                    'المتبقي: {remaining}',
                    'شكراً لكم — {business_name} 🏛️',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'cancellation',
                'title' => 'إلغاء حجز قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'نفيدكم بإلغاء حجز القاعة رقم {reference}.',
                    'القاعة: {unit}',
                    'الموعد: {date}',
                    'السبب: {reason}',
                    'المبلغ المسدَّد: {paid} — سيتم التواصل معكم بشأنه.',
                    'نأسف لذلك، ونسعد بخدمتكم في مناسبة قادمة 🏛️',
                ]),
            ],
            [
                'category' => 'hall',
                'event' => 'contract',
                'title' => 'إرسال عقد قاعة',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'مرفق عقد حجز القاعة رقم {reference} 📄',
                    'رقم العقد: {contract_number}',
                    'القاعة: {unit}',
                    'تاريخ المناسبة: {date} — {period}',
                    'نرجو الاطلاع والتأكيد.',
                    '{business_name} 🏛️',
                ]),
            ],

            // ===================== المسابح =====================
            [
                'category' => 'pool',
                'event' => 'welcome',
                'title' => 'ترحيب — عملاء المسابح',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'أهلاً بك في {business_name} 🏊',
                    'مسابحنا متاحة بفترات صباحية ومسائية وبخصوصية كاملة للعائلات.',
                    'تواصل معنا لمعرفة الفترات المتاحة والأسعار.',
                ]),
            ],
            [
                'category' => 'pool',
                'event' => 'booking_confirm',
                'title' => 'تأكيد حجز مسبح',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'تم تأكيد حجز المسبح بنجاح ✅',
                    'رقم الحجز: {reference}',
                    'المسبح: {unit}',
                    'التاريخ: {date} — الفترة: {period}',
                    'الإجمالي: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '',
                    '📌 يُرجى الالتزام بوقت الفترة، ومرافقة الأطفال طوال فترة السباحة.',
                    'نتشرّف باستقبالكم في {business_name} 🌿',
                ]),
            ],
            [
                'category' => 'pool',
                'event' => 'invoice',
                'title' => 'فاتورة حجز مسبح',
                'body' => implode("\n", [
                    'مرحباً {name} 👋',
                    'مرفق فاتورة حجز المسبح رقم {reference} 🧾',
                    'المسبح: {unit}',
                    'التاريخ: {date} — {period}',
                    '——————————————',
                    'إجمالي الحجز: {total}',
                    'المسدَّد: {paid}',
                    'المتبقي: {remaining}',
                    '——————————————',
                    'شكراً لاختياركم {business_name} 🏊',
                ]),
            ],
        ];
    }
}
