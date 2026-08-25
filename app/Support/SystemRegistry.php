<?php

namespace App\Support;

/**
 * سجل أقسام المنصة وصلاحياتها.
 *
 * البنية ثلاثية المستوى:
 *   قسم (Section)  →  شاشة (Screen)  →  إجراء (Action)
 *
 * مفتاح الصلاحية النهائي يبقى "{screen}.{action}" (مثل: hall_bookings.create)
 * حتى يعمل مع الوسيط ->middleware('perm:hall_bookings.create') دون تغيير.
 *
 * الأقسام الثلاثة الأولى أنشطة المؤسسة: القاعات والشاليهات والمسابح، ولكلٍّ
 * منها مفاتيحه المستقلة. كان مفتاحٌ واحد (bookings.*, units.*, calendar.*)
 * يخدم القاعات والشاليهات معًا، فمنحُ موظفِ القاعات حجوزاتِها يمنحه حجوزات
 * الشاليهات كذلك دون أن يظهر ذلك في الشاشة؛ والفصل هنا يجعل ما تراه في
 * المجموعة هو ما يُمنح فعلًا. ثم تأتي الأقسام الإدارية المشتركة.
 */
class SystemRegistry
{
    /**
     * الإجراءات القياسية المتاحة في المنصة.
     */
    public const ACTIONS = [
        'view' => 'عرض',
        'create' => 'إضافة',
        'edit' => 'تعديل',
        'delete' => 'حذف',
        'approve' => 'اعتماد',
        'send' => 'إرسال',
        'export' => 'تصدير',
        'restore' => 'استرجاع',
    ];

    /**
     * الأقسام وشاشاتها.
     *
     * @var array<string, array{label: string, icon: string, description: string, modules: array<string, array{label: string, actions: list<string>}>}>
     */
    public const SYSTEMS = [
        'halls' => [
            'label' => 'القاعات',
            'icon' => 'Building2',
            'description' => 'حجوزات القاعات وتقويمها والباقات وأنواع المناسبات وقالب العقد',
            'modules' => [
                'hall_bookings' => ['label' => 'حجوزات القاعات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'hall_calendar' => ['label' => 'تقويم القاعات', 'actions' => ['view']],
                'halls' => ['label' => 'القاعات ومساحات عملها', 'actions' => ['view', 'create', 'edit', 'delete']],
                'packages' => ['label' => 'باقات القاعات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'event_types' => ['label' => 'أنواع المناسبات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'hall_contract' => ['label' => 'قالب عقد القاعات', 'actions' => ['view', 'edit']],
            ],
        ],

        'chalets' => [
            'label' => 'الشاليهات',
            'icon' => 'Home',
            'description' => 'حجوزات الشاليهات بالليالي وتقويمها وملفات الوحدات وقالب العقد',
            'modules' => [
                'chalet_bookings' => ['label' => 'حجوزات الشاليهات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'chalet_calendar' => ['label' => 'تقويم الشاليهات', 'actions' => ['view']],
                'chalets' => ['label' => 'الشاليهات ومساحات عملها', 'actions' => ['view', 'create', 'edit', 'delete']],
                'chalet_contract' => ['label' => 'قالب عقد الشاليهات', 'actions' => ['view', 'edit']],
            ],
        ],

        'pools' => [
            'label' => 'المسابح',
            'icon' => 'Waves',
            'description' => 'فواتير منتجات المسابح وخدمات الصيانة والأصناف والمخزون',
            'modules' => [
                'pos' => ['label' => 'شاشة الفواتير', 'actions' => ['view', 'create']],
                'sales' => ['label' => 'المبيعات والمرتجعات', 'actions' => ['view', 'create', 'delete', 'export']],
                'items' => ['label' => 'الأصناف', 'actions' => ['view', 'create', 'edit', 'delete']],
                'item_groups' => ['label' => 'مجموعات الأصناف', 'actions' => ['view', 'create', 'edit', 'delete']],
                'inventory' => ['label' => 'المخزون والجرد', 'actions' => ['view', 'create', 'edit', 'approve']],
                'purchases' => ['label' => 'المشتريات', 'actions' => ['view', 'create']],
                'quotations' => ['label' => 'عروض الأسعار', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'core' => [
            'label' => 'الرئيسية',
            'icon' => 'LayoutDashboard',
            'description' => 'لوحة المؤشرات والتقارير العامة والإشعارات',
            'modules' => [
                'dashboard' => ['label' => 'لوحة التحكم', 'actions' => ['view']],
                'reports' => ['label' => 'التقارير', 'actions' => ['view', 'export']],
                'notifications' => ['label' => 'الإشعارات', 'actions' => ['view', 'create', 'edit', 'delete', 'send']],
            ],
        ],

        'contracts' => [
            'label' => 'العقود والواتساب',
            'icon' => 'FileSignature',
            'description' => 'قوالب العقود، توليد PDF، الإرسال والتذكيرات عبر واتساب',
            'modules' => [
                'contracts' => ['label' => 'العقود', 'actions' => ['view', 'create', 'edit', 'delete', 'send', 'export']],
                'contract_templates' => ['label' => 'قوالب العقود', 'actions' => ['view', 'create', 'edit', 'delete']],
                'whatsapp' => ['label' => 'رسائل واتساب', 'actions' => ['view', 'send']],
            ],
        ],

        'accounting' => [
            'label' => 'المحاسبة',
            'icon' => 'Calculator',
            'description' => 'شجرة الحسابات، القيود، الخزائن، الذمم، مراكز التكلفة والتقارير المالية',
            'modules' => [
                'accounts' => ['label' => 'شجرة الحسابات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'journal' => ['label' => 'القيود اليومية', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'treasury' => ['label' => 'الصناديق والبنوك', 'actions' => ['view', 'create', 'edit', 'delete']],
                'payment_methods' => ['label' => 'طرق الدفع', 'actions' => ['view', 'create', 'edit', 'delete']],
                'vouchers' => ['label' => 'سندات القبض والصرف', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                // الإيرادات شاشة قراءة لا إدخال: القيد يأتي من الحجز والفاتورة
                // والسند، فلا معنى لـ create/edit فيها.
                'revenues' => ['label' => 'الإيرادات', 'actions' => ['view', 'export']],
                'expenses' => ['label' => 'المصروفات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'receivables' => ['label' => 'ذمم العملاء والموردين', 'actions' => ['view', 'create', 'edit', 'export']],
                'cost_centers' => ['label' => 'مراكز التكلفة', 'actions' => ['view', 'create', 'edit', 'delete']],
                'fin_reports' => ['label' => 'التقارير المالية', 'actions' => ['view', 'export']],
            ],
        ],

        'hr' => [
            'label' => 'الموارد البشرية والرواتب',
            'icon' => 'Users',
            'description' => 'ملفات الموظفين، الحضور والإجازات، السلف ومسيّر الرواتب',
            'modules' => [
                'staff' => ['label' => 'ملفات الموظفين', 'actions' => ['view', 'create', 'edit', 'delete']],
                'attendance' => ['label' => 'الحضور والانصراف', 'actions' => ['view', 'create', 'edit', 'approve']],
                'leaves' => ['label' => 'الإجازات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'advances' => ['label' => 'السلف والخصومات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'bonuses' => ['label' => 'المكافآت', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'payroll' => ['label' => 'مسيّر الرواتب', 'actions' => ['view', 'create', 'edit', 'approve', 'export']],
            ],
        ],

        'crm' => [
            'label' => 'العملاء والموردون',
            'icon' => 'Contact',
            'description' => 'سجل العملاء والموردين وتذاكر الدعم',
            'modules' => [
                'clients' => ['label' => 'العملاء', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                'suppliers' => ['label' => 'الموردون', 'actions' => ['view', 'create', 'edit', 'delete']],
                'tickets' => ['label' => 'تذاكر الدعم', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'system' => [
            'label' => 'الإدارة والنظام',
            'icon' => 'Settings',
            'description' => 'الموظفون والمجموعات والإعدادات وسجل التدقيق',
            'modules' => [
                'employees' => ['label' => 'مستخدمو النظام', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'المجموعات والصلاحيات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'cities' => ['label' => 'المدن', 'actions' => ['view', 'create', 'edit', 'delete']],
                // Add-ons are filed with the shared catalogues rather than under
                // halls or chalets: both booking forms sell the same list, and
                // giving it to either activity would hand the other's staff a
                // key inside a section that is not theirs.
                'addons' => ['label' => 'الخدمات الإضافية', 'actions' => ['view', 'create', 'edit', 'delete']],
                'departments' => ['label' => 'الأقسام الإدارية', 'actions' => ['view', 'edit']],
                'audit' => ['label' => 'سجل التدقيق', 'actions' => ['view', 'export']],
                // الحذف هنا هو الإتلاف النهائي من الأرشيف وحده — ولذلك
                // يُمنح منفصلًا عن الاسترجاع وعن حذف الشاشات الاعتيادي.
                'archive' => ['label' => 'الأرشيف (المحذوفات)', 'actions' => ['view', 'restore', 'delete']],
                // نسخة القاعدة تُنزَّل بصلاحية لا برابط — فيها بيانات العملاء
                // كلها، وتنزيلها يعادل قراءة النظام بأسره.
                // والاستعادة تُمنح منفصلة عما سواها: تنزيل النسخة قراءة،
                // والاستعادة كتابة فوق القاعدة كلها لا تُراجَع.
                'backups' => ['label' => 'النسخ الاحتياطي', 'actions' => ['view', 'create', 'restore', 'delete']],
                'settings' => ['label' => 'الإعدادات العامة', 'actions' => ['view', 'edit']],
            ],
        ],
    ];

    /**
     * ترجمة مفاتيح ما قبل فصل القاعات عن الشاليهات إلى مفاتيح اليوم.
     *
     * المفتاح القديم الواحد كان يخدم النشاطين معًا، فترجمته تعطي مفتاحي
     * النشاطين — وهو السلوك الذي يُبقي المجموعات القائمة على ما كانت عليه
     * بعد الترقية بدل أن تفقد صلاحياتها صامتةً.
     *
     * @var array<string, list<string>>
     */
    public const LEGACY_KEY_MAP = [
        'bookings.view' => ['hall_bookings.view', 'chalet_bookings.view'],
        'bookings.create' => ['hall_bookings.create', 'chalet_bookings.create'],
        'bookings.edit' => ['hall_bookings.edit', 'chalet_bookings.edit'],
        'bookings.delete' => ['hall_bookings.delete', 'chalet_bookings.delete'],
        'bookings.approve' => ['hall_bookings.approve', 'chalet_bookings.approve'],
        'calendar.view' => ['hall_calendar.view', 'chalet_calendar.view'],
        'units.view' => ['halls.view', 'chalets.view', 'hall_contract.view', 'chalet_contract.view'],
        'units.create' => ['halls.create', 'chalets.create'],
        'units.edit' => ['halls.edit', 'chalets.edit', 'hall_contract.edit', 'chalet_contract.edit'],
        'units.delete' => ['halls.delete', 'chalets.delete'],
        // A screen that never had a route — it drops with no replacement.
        // (addons.* used to be listed here for the same reason; the add-ons
        // screen exists now, so those keys pass through untranslated again.)
        'pricing.view' => [],
        'pricing.create' => [],
        'pricing.edit' => [],
        'pricing.delete' => [],
    ];

    /**
     * كل مفاتيح الصلاحيات المتاحة في المنصة.
     *
     * @return list<string>
     */
    public static function permissionKeys(): array
    {
        $keys = [];
        foreach (self::SYSTEMS as $system) {
            foreach ($system['modules'] as $module => $meta) {
                foreach ($meta['actions'] as $action) {
                    $keys[] = "{$module}.{$action}";
                }
            }
        }

        return $keys;
    }

    /**
     * مفاتيح صلاحيات قسم واحد — تُستخدم لمنح أو سحب قسم كامل دفعة واحدة.
     *
     * @return list<string>
     */
    public static function systemPermissionKeys(string $system): array
    {
        $keys = [];
        foreach (self::SYSTEMS[$system]['modules'] ?? [] as $module => $meta) {
            foreach ($meta['actions'] as $action) {
                $keys[] = "{$module}.{$action}";
            }
        }

        return $keys;
    }

    /**
     * خريطة الشاشة → القسم الذي تتبعه (مثل: hall_bookings → halls، payroll → hr).
     *
     * @return array<string, string>
     */
    public static function moduleSystemMap(): array
    {
        $map = [];
        foreach (self::SYSTEMS as $systemKey => $system) {
            foreach (array_keys($system['modules']) as $module) {
                $map[$module] = $systemKey;
            }
        }

        return $map;
    }

    /**
     * خريطة مفتاح الصلاحية → التسمية العربية الكاملة (لعرض الشارات).
     *
     * @return array<string, string>
     */
    public static function permissionLabels(): array
    {
        $labels = [];
        foreach (self::SYSTEMS as $system) {
            foreach ($system['modules'] as $module => $meta) {
                foreach ($meta['actions'] as $action) {
                    $labels["{$module}.{$action}"] = $meta['label'].' - '.self::ACTIONS[$action];
                }
            }
        }

        return $labels;
    }

    /**
     * ترجمة قائمة صلاحيات محفوظة بالمفاتيح القديمة إلى مفاتيح اليوم،
     * مع إسقاط أي مفتاح لم يعد له وجود.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function migrateLegacyKeys(array $permissions): array
    {
        $valid = array_flip(self::permissionKeys());
        $out = [];

        foreach ($permissions as $key) {
            if (array_key_exists($key, self::LEGACY_KEY_MAP)) {
                foreach (self::LEGACY_KEY_MAP[$key] as $mapped) {
                    $out[$mapped] = true;
                }

                continue;
            }

            if (isset($valid[$key])) {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * تمثيل الأقسام جاهزًا للواجهة الأمامية (شجرة: قسم ← شاشات ← إجراءات).
     *
     * @return list<array<string, mixed>>
     */
    public static function forView(): array
    {
        return collect(self::SYSTEMS)->map(fn ($system, $systemKey) => [
            'key' => $systemKey,
            'label' => $system['label'],
            'icon' => $system['icon'],
            'description' => $system['description'],
            'permission_keys' => self::systemPermissionKeys($systemKey),
            'modules' => collect($system['modules'])->map(fn ($meta, $module) => [
                'key' => $module,
                'label' => $meta['label'],
                'actions' => collect($meta['actions'])->map(fn ($action) => [
                    'key' => "{$module}.{$action}",
                    'action' => $action,
                    'label' => self::ACTIONS[$action],
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }
}
