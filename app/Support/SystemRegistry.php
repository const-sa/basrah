<?php

namespace App\Support;

/**
 * سجل أنظمة المنصة وصلاحياتها.
 *
 * البنية ثلاثية المستوى:
 *   نظام (System)  →  وحدة (Module)  →  إجراء (Action)
 *
 * مفتاح الصلاحية النهائي يبقى "{module}.{action}" (مثل: bookings.create)
 * حتى يعمل مع الوسيط الحالي ->middleware('perm:bookings.create') دون تغيير.
 *
 * فائدة مستوى "النظام" أنه يسمح بمنح أو منع نظام كامل بضغطة واحدة،
 * وبإخفاء قوائم النظام من الشريط الجانبي لمن لا يملك أي صلاحية فيه.
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
     * الأنظمة ووحداتها.
     *
     * @var array<string, array{label: string, icon: string, description: string, modules: array<string, array{label: string, actions: list<string>}>}>
     */
    public const SYSTEMS = [
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

        'bookings' => [
            'label' => 'نظام الحجوزات',
            'icon' => 'CalendarDays',
            'description' => 'الوحدات والأقسام، التقويم، الحجوزات، الأسعار والخدمات الإضافية',
            'modules' => [
                'units' => ['label' => 'الوحدات والأقسام', 'actions' => ['view', 'create', 'edit', 'delete']],
                'bookings' => ['label' => 'الحجوزات', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'calendar' => ['label' => 'التقويم الموحّد', 'actions' => ['view']],
                'pricing' => ['label' => 'الأسعار والمواسم', 'actions' => ['view', 'create', 'edit', 'delete']],
                'addons' => ['label' => 'الخدمات الإضافية', 'actions' => ['view', 'create', 'edit', 'delete']],
                'packages' => ['label' => 'باقات القاعات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'event_types' => ['label' => 'أنواع المناسبات', 'actions' => ['view', 'create', 'edit', 'delete']],
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

        'pos' => [
            'label' => 'المسابح — بيع وصيانة',
            'icon' => 'ShoppingCart',
            'description' => 'نشاط مستقل: فواتير منتجات المسابح وخدمات الصيانة والمخزون',
            'modules' => [
                'pos' => ['label' => 'شاشة الفواتير', 'actions' => ['view', 'create']],
                'items' => ['label' => 'الأصناف', 'actions' => ['view', 'create', 'edit', 'delete']],
                'inventory' => ['label' => 'المخزون والجرد', 'actions' => ['view', 'create', 'edit', 'approve']],
                'sales' => ['label' => 'المبيعات والمرتجعات', 'actions' => ['view', 'create', 'delete', 'export']],
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
            'description' => 'المستخدمون والأدوار والإعدادات وسجل التدقيق',
            'modules' => [
                'employees' => ['label' => 'مستخدمو النظام', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'الأدوار والصلاحيات', 'actions' => ['view', 'create', 'edit', 'delete']],
                'cities' => ['label' => 'المدن', 'actions' => ['view', 'create', 'edit', 'delete']],
                'departments' => ['label' => 'الأقسام الإدارية', 'actions' => ['view', 'create', 'edit', 'delete']],
                'audit' => ['label' => 'سجل التدقيق', 'actions' => ['view', 'export']],
                // الحذف هنا هو الإتلاف النهائي من الأرشيف وحده — ولذلك
                // يُمنح منفصلًا عن الاسترجاع وعن حذف الشاشات الاعتيادي.
                'archive' => ['label' => 'الأرشيف (المحذوفات)', 'actions' => ['view', 'restore', 'delete']],
                'settings' => ['label' => 'الإعدادات العامة', 'actions' => ['view', 'edit']],
            ],
        ],
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
     * مفاتيح صلاحيات نظام واحد — تُستخدم لمنح أو سحب نظام كامل دفعة واحدة.
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
     * خريطة الوحدة → النظام الذي تتبعه (مثل: bookings → bookings، payroll → hr).
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
     * تمثيل الأنظمة جاهزًا للواجهة الأمامية (شجرة: نظام ← وحدات ← إجراءات).
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
