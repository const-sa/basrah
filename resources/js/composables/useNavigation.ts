import { useLocale } from '@/composables/useLocale';
import { usePermissions } from '@/composables/usePermissions';
import { type NavItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    Archive as ArchiveIcon,
    Armchair,
    BookUser,
    Boxes,
    Building2,
    CalculatorIcon,
    CalendarDays,
    CalendarRange,
    Clock,
    ConciergeBell,
    Contact,
    CreditCard,
    DatabaseBackup,
    FileBarChart2,
    FileSignature,
    FileText,
    History,
    Home,
    Landmark,
    Layers,
    LayoutDashboard,
    LifeBuoy,
    MapPin,
    Megaphone,
    MessageCircle,
    Package as PackageIcon,
    PartyPopper,
    Percent,
    PieChart,
    Receipt,
    Ruler,
    ScrollText,
    Settings,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    SlidersHorizontal,
    TrendingUp,
    Truck,
    Users,
    UsersRound,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface SidebarUnit {
    id: number;
    code: string;
    name: string;
    type: 'hall' | 'chalet';
}

/**
 * عنصر تنقّل مع الصلاحية التي تحكم ظهوره.
 * العنصر بلا `perm` يظهر لكل مستخدم مسجّل.
 */
// `shared` marks a screen this menu only borrows — it belongs to another section,
// so holding its key must not keep this activity's menu open on its own.
export type GuardedNavItem = NavItem & { perm?: string; shared?: boolean; children?: GuardedNavItem[] };

/**
 * هل ينتمي المسار الحالي إلى هذا المدخل؟ يشمل صفحاته الفرعية،
 * فصفحة الإنشاء تحت «حجوزات القاعات» تُبقي القسم مضاءً.
 */
export const urlBelongsTo = (currentUrl: string, href: string): boolean => currentUrl === href || currentUrl.startsWith(`${href}/`);

/** لوحة التحكم — جذر القسم الإداري، ووجهة الرجوع حين لا يكون فوق الصفحة غيرها. */
export const DASHBOARD_HREF = '/admin';

/**
 * المدخل الذي يمثّل الصفحة المفتوحة من بين إخوته — أطولُ مسارٍ مطابق يفوز.
 *
 * «تقويم القاعات» (‏/calendar/halls) بادئةٌ لـ«التقويم الشهري»
 * (‏/calendar/halls/month)، فالمطابقة بالبادئة وحدها تجعل الاثنين «الصفحة
 * الحالية» معًا: يُضاءان في القائمة، ويسقط أحدهما من الاختصارات وهو المقصود.
 */
export const activeHref = (currentUrl: string, hrefs: string[]): string | null =>
    hrefs.filter((href) => urlBelongsTo(currentUrl, href)).sort((a, b) => b.length - a.length)[0] ?? null;

/**
 * شجرة التنقّل — مصدرها واحد مهما تعدّد شكل عرضها.
 *
 * كانت الشجرة مكتوبة داخل مكوّن الشريط الجانبي، فلمّا انتقلت القائمة إلى
 * الترويسة كان النسخ يعني قائمتين تتباعدان: شاشة تُضاف هنا وتُنسى هناك.
 */
export function useNavigation() {
    const { t } = useLocale();
    const { can } = usePermissions();

    /** الوحدات التي يراها المستخدم — يشاركها HandleInertiaRequests مع كل صفحة. */
    const sidebarUnits = computed<SidebarUnit[]>(() => (usePage().props.sidebarUnits as SidebarUnit[] | undefined) ?? []);

    const unitsOfType = (type: SidebarUnit['type']) => sidebarUnits.value.filter((u) => u.type === type);

    const allNavItems = computed<GuardedNavItem[]>(() => [
        // شاشاتٌ عامّة لا تتبع نظامًا بعينه، وكلٌّ منها شاشةٌ واحدة لا قسم.
        // كانت مجموعةً باسم «الإدارة»، فكان الوصول إلى أكثر الشاشات ترددًا
        // (لوحة التحكم والعملاء) يمرّ بنقرةِ فتحٍ لا تكشف إلا رابطًا واحدًا؛
        // وهنا تصير مداخل مفردة في أعلى القائمة تُفتح بنقرة.
        // العملاء ليسوا مدخلًا واحدًا هنا: لكل نشاط سجلّه داخل قائمته، فالموظف
        // يفتح عملاءه لا دليلًا يجمع الأنشطة الثلاثة.
        { title: t('nav.dashboard'), href: '/admin', icon: LayoutDashboard, perm: 'dashboard.view' },
        { title: t('nav.reports'), href: '/admin/reports', icon: FileBarChart2, perm: 'reports.view' },
        // نظاما القاعات والشاليهات منفصلان تمامًا: لكلٍّ حجوزاته وتقويمه ونموذجه،
        // لأن القاعة تُحجز بفترة داخل يوم والشاليه يُحجز بليالٍ ممتدة. جمعهما في
        // مدخل «حجوزات» واحد كان يُخفي هذا الاختلاف ويجعل الموظف يبحث عن الشاشة.
        {
            title: t('nav.halls'),
            href: '#halls',
            icon: Building2,
            perm: 'halls.view',
            children: [
                { title: t('nav.hall_bookings'), href: '/admin/bookings/halls', icon: CalendarDays, perm: 'hall_bookings.view' },
                { title: t('nav.hall_calendar'), href: '/admin/calendar/halls', icon: CalendarRange, perm: 'hall_calendar.view' },
                { title: t('nav.hall_month_calendar'), href: '/admin/calendar/halls/month', icon: CalendarDays, perm: 'hall_calendar.view' },
                { title: t('nav.clients'), href: '/admin/halls/clients', icon: Contact, perm: 'hall_clients.view' },
                // Spend is recorded where it is incurred: the register opens on
                // this activity's centres, and on the employee's own units.
                { title: t('nav.expenses'), href: '/admin/halls/expenses', icon: Receipt, perm: 'hall_expenses.view' },
                { title: t('nav.all_halls'), href: '/admin/units/halls', icon: Building2, perm: 'halls.view' },
                // The two papers of an event — the rental pad and the services
                // list — as the chalets and pools menus hold their own.
                { title: t('nav.contracts_list'), href: '/admin/halls/contracts', icon: FileSignature, perm: 'hall_contracts.view' },
                { title: t('nav.packages'), href: '/admin/packages', icon: PackageIcon, perm: 'packages.view' },
                { title: t('nav.event_types'), href: '/admin/event-types', icon: PartyPopper, perm: 'event_types.view' },
                {
                    title: t('nav.hall_contract_template'),
                    href: '/admin/units/contract-template',
                    icon: FileText,
                    perm: 'hall_contract_template.view',
                },
                // كل قاعة مدخل مستقل: لها حجوزاتها وفواتيرها وربحيتها، فالوصول
                // إليها مباشرة أسرع من تصفية الشاشات العامة في كل مرة.
                ...unitsOfType('hall').map((u) => ({
                    title: u.name,
                    href: `/admin/units/${u.id}/workspace`,
                    icon: Building2,
                    perm: 'halls.view',
                })),
            ],
        },
        {
            title: t('nav.chalets'),
            href: '#chalets',
            icon: Home,
            perm: 'chalets.view',
            children: [
                { title: t('nav.chalet_bookings'), href: '/admin/bookings/chalets', icon: CalendarDays, perm: 'chalet_bookings.view' },
                { title: t('nav.chalet_calendar'), href: '/admin/calendar/chalets', icon: CalendarRange, perm: 'chalet_calendar.view' },
                { title: t('nav.clients'), href: '/admin/chalets/clients', icon: Contact, perm: 'chalet_clients.view' },
                { title: t('nav.expenses'), href: '/admin/chalets/expenses', icon: Receipt, perm: 'chalet_expenses.view' },
                { title: t('nav.all_chalets'), href: '/admin/units/chalets', icon: Home, perm: 'chalets.view' },
                // The rental sheets let on the chalets, as the pools menu holds
                // theirs: the employee here wants this activity's contracts,
                // not every hall rental and pool job in the business.
                { title: t('nav.contracts_list'), href: '/admin/chalets/contracts', icon: FileSignature, perm: 'chalet_contracts.view' },
                {
                    title: t('nav.chalet_contract_template'),
                    href: '/admin/units/chalet-contract-template',
                    icon: FileText,
                    perm: 'chalet_contract_template.view',
                },
                ...unitsOfType('chalet').map((u) => ({
                    title: u.name,
                    href: `/admin/units/${u.id}/workspace`,
                    icon: Home,
                    perm: 'chalets.view',
                })),
            ],
        },
        {
            title: t('nav.contracts'),
            href: '#contracts',
            icon: FileSignature,
            children: [
                { title: t('nav.contracts_list'), href: '/admin/contracts', icon: FileSignature, perm: 'contracts.view' },
                { title: t('nav.contract_templates'), href: '/admin/contract-templates', icon: FileText, perm: 'contract_templates.view' },
                { title: t('nav.whatsapp_log'), href: '/admin/whatsapp-log', icon: MessageCircle, perm: 'whatsapp.view' },
                { title: t('nav.notifications_library'), href: '/admin/notifications/library', icon: Megaphone, perm: 'notifications.view' },
            ],
        },
        {
            title: t('nav.pos'),
            href: '#pos',
            icon: ShoppingCart,
            children: [
                { title: t('nav.cashier'), href: '/admin/pos', icon: ShoppingCart, perm: 'pos.view' },
                { title: t('nav.sales'), href: '/admin/sales', icon: Receipt, perm: 'sales.view' },
                { title: t('nav.clients'), href: '/admin/pools/clients', icon: Contact, perm: 'pool_clients.view' },
                { title: t('nav.expenses'), href: '/admin/pools/expenses', icon: Receipt, perm: 'pool_expenses.view' },
                { title: t('nav.items'), href: '/admin/items', icon: Boxes, perm: 'items.view' },
                { title: t('nav.item_groups'), href: '/admin/item-groups', icon: Layers, perm: 'item_groups.view' },
                { title: t('nav.measure_units'), href: '/admin/inventory/units', icon: Ruler, perm: 'measure_units.view' },
                { title: t('nav.movements'), href: '/admin/inventory/movements', icon: History, perm: 'inventory.view' },
                { title: t('nav.suppliers'), href: '/admin/suppliers', icon: Truck, perm: 'suppliers.view', shared: true },
                { title: t('nav.purchases'), href: '/admin/purchases', icon: ShoppingBag, perm: 'purchases.view' },
                { title: t('nav.quotations'), href: '/admin/quotations', icon: FileText, perm: 'quotations.view' },
                // A pools contract is drawn from the quotation the client
                // accepted, so it belongs next to them in this activity's menu.
                // It points at the pools register, not the full one: from here
                // the employee wants this activity's contracts, not every
                // hall and chalet rental in the business.
                { title: t('nav.contracts_list'), href: '/admin/pools/contracts', icon: FileSignature, perm: 'pool_contracts.view' },
            ],
        },
        {
            // A section with a page of its own: its href is a route, not an
            // anchor, so the sidebar links to it instead of unfolding thirteen
            // rows into a column too narrow to scan. The children stay here —
            // the page is built from them, and so are the shortcut chips.
            title: t('nav.accounting'),
            href: '/admin/accounting',
            icon: CalculatorIcon,
            children: [
                { title: t('nav.accounts'), href: '/admin/accounting/accounts', icon: CalculatorIcon, perm: 'accounts.view' },
                // المورّد طرف دائن في دفاتر المحاسبة قبل أن يكون شاشة إدارية،
                // فمكانه مع الحسابات والسندات لا مع لوحة التحكم والعملاء.
                { title: t('nav.suppliers'), href: '/admin/suppliers', icon: Truck, perm: 'suppliers.view', shared: true },
                { title: t('nav.journal'), href: '/admin/accounting/journal', icon: FileText, perm: 'journal.view' },
                { title: t('nav.vouchers'), href: '/admin/accounting/vouchers', icon: Receipt, perm: 'vouchers.view' },
                { title: t('nav.revenues'), href: '/admin/accounting/revenues', icon: TrendingUp, perm: 'revenues.view' },
                // شاشة الإيرادات تجمع؛ وهذه تفتح حسابًا واحدًا بحركته ورصيده.
                { title: t('nav.revenue_statement'), href: '/admin/accounting/revenue-statement', icon: FileText, perm: 'revenues.view' },
                { title: t('nav.expenses'), href: '/admin/accounting/expenses', icon: Receipt, perm: 'expenses.view' },
                { title: t('nav.receivables'), href: '/admin/accounting/receivables', icon: BookUser, perm: 'receivables.view' },
                { title: t('nav.cost_centers'), href: '/admin/accounting/cost-centers', icon: PieChart, perm: 'cost_centers.view' },
                { title: t('nav.fixed_assets'), href: '/admin/accounting/fixed-assets', icon: Armchair, perm: 'fixed_assets.view' },
                {
                    title: t('nav.bank_reconciliation'),
                    href: '/admin/accounting/bank-reconciliation',
                    icon: Landmark,
                    perm: 'bank_reconciliation.view',
                },
                { title: t('nav.fin_reports'), href: '/admin/accounting/reports', icon: FileBarChart2, perm: 'fin_reports.view' },
                // إعدادات القسم في ذيله: تُضبط مرةً وتُقرأ في كل شاشة فوقها —
                // نسبةُ الضريبة في كل فاتورة، وطريقةُ الدفع حسابٌ يُرحَّل عليه،
                // وحساباتُ الإيراد وجهةُ كل قيد دخل.
                {
                    title: t('nav.settings_payment_methods'),
                    href: '/admin/accounting/payment-methods',
                    icon: CreditCard,
                    perm: 'payment_methods.view',
                },
                { title: t('nav.settings_accounting'), href: '/admin/accounting/settings', icon: SlidersHorizontal, perm: 'settings.view' },
                { title: t('nav.settings_tax'), href: '/admin/accounting/tax', icon: Percent, perm: 'settings.view' },
            ],
        },
        {
            title: t('nav.hr'),
            href: '#hr',
            icon: UsersRound,
            children: [
                { title: t('nav.staff'), href: '/admin/hr/staff', icon: Users, perm: 'staff.view' },
                { title: t('nav.attendance'), href: '/admin/hr/attendance', icon: CalendarRange, perm: 'attendance.view' },
                { title: t('nav.leaves'), href: '/admin/hr/leaves', icon: FileText, perm: 'leaves.view' },
                { title: t('nav.payroll'), href: '/admin/hr/payroll', icon: Wallet, perm: 'payroll.view' },
            ],
        },
        {
            title: t('nav.employees'),
            href: '#employees',
            icon: ShieldCheck,
            children: [
                { title: t('nav.employees_list'), href: '/admin/employees', icon: Users, perm: 'employees.view' },
                { title: t('nav.groups'), href: '/admin/groups', icon: ShieldCheck, perm: 'roles.view' },
                { title: t('nav.audit_log'), href: '/admin/audit-log', icon: ScrollText, perm: 'audit.view' },
                { title: t('nav.archive'), href: '/admin/archive', icon: ArchiveIcon, perm: 'archive.view' },
            ],
        },
        {
            title: t('nav.settings'),
            href: '#settings',
            icon: Settings,
            children: [
                { title: t('nav.settings_general'), href: '/admin/settings/general', icon: SlidersHorizontal, perm: 'settings.view' },
                // The hours behind every booking range — day periods and chalet
                // check-in/out. They decide what counts as a clash, not just labels.
                { title: t('nav.settings_booking_times'), href: '/admin/settings/booking-times', icon: Clock, perm: 'settings.view' },
                { title: t('nav.settings_whatsapp'), href: '/admin/settings/whatsapp', icon: MessageCircle, perm: 'settings.view' },
                { title: t('nav.backups'), href: '/admin/backups', icon: DatabaseBackup, perm: 'backups.view' },
                { title: t('nav.departments'), href: '/admin/departments', icon: Building2, perm: 'departments.view' },
                { title: t('nav.cities'), href: '/admin/cities', icon: MapPin, perm: 'cities.view' },
                // Shared by both booking forms, so it sits with the common
                // catalogues rather than under halls or chalets.
                { title: t('nav.addons'), href: '/admin/addons', icon: ConciergeBell, perm: 'addons.view' },
            ],
        },
        // شاشاتٌ مساندة لا تخدم العمل اليومي: تُفتح عند الحاجة لا كل يوم،
        // فذيل القائمة مكانها كي يبقى أعلاها للعمل ولأقسام الأنظمة.
        { title: t('nav.support'), href: '/admin/tickets', icon: LifeBuoy, perm: 'tickets.view' },
        // مخفيّة من القائمة بطلب الإدارة (الصفحة والمسار باقيان يُفتحان بالرابط المباشر):
        // { title: t('nav.about'), href: '/admin/about', icon: Lightbulb },
    ]);

    /**
     * إخفاء ما لا يملك المستخدم صلاحيته، وإخفاء المجموعة كاملة إذا خلت من أبنائها.
     * الحماية الفعلية على الخادم — هذا تنظيف للواجهة فقط.
     *
     * A group opens on its own screens only. Clients, expenses, suppliers and
     * contracts are one key each, shared by all three activities, so a pools
     * employee holding them would otherwise be shown a halls and a chalets menu
     * carrying nothing but those borrowed rows.
     */
    const navItems = computed<GuardedNavItem[]>(() =>
        allNavItems.value
            .map((item) => {
                if (!item.children) return item.perm && !can(item.perm) ? null : item;

                // Cast: the intersection widens children back to NavItem, dropping perm/shared.
                const children = (item.children as GuardedNavItem[]).filter((c) => !c.perm || can(c.perm));
                return children.some((c) => !c.shared) ? { ...item, children } : null;
            })
            .filter((item): item is GuardedNavItem => item !== null),
    );

    /**
     * وجهة الرجوع من الصفحة المفتوحة: الدرجة التي فوقها في شجرة التنقّل.
     *
     * تُحسب من الشجرة نفسها لا من قائمةٍ مكتوبة بجانبها، فالشاشة التي تُضاف
     * إلى القائمة يصير لها رجوعٌ من تلقائه. وترتيب البحث من الأخصّ إلى الأعمّ:
     *
     *  - صفحةٌ فرعية داخل شاشة (‏/bookings/halls/5/edit) ترجع إلى شاشتها.
     *  - شاشةٌ داخل قسمٍ له صفحة (المحاسبة) ترجع إلى صفحة القسم.
     *  - ما عدا ذلك يرجع إلى لوحة التحكم، وهي وحدها بلا رجوع.
     *
     * أقسام القائمة التي مرساتها وسمٌ (‏#halls) لا صفحة لها تُفتح، فلا تصلح
     * وجهةً — الرجوع منها إلى اللوحة.
     */
    const backTarget = computed<{ title: string; href: string } | null>(() => {
        const url = usePage().url.split(/[?#]/)[0];
        if (url === DASHBOARD_HREF) return null;

        const dashboard = { title: t('nav.dashboard'), href: DASHBOARD_HREF };

        // أطولُ مسارٍ مطابق يفوز: «تقويم القاعات» بادئةُ «التقويم الشهري»، فلو
        // فاز الأقصر لصار رجوعُ الشهري إليه لا إلى قسمه.
        //
        // ثم القسم الذي له صفحة تُفتح: شاشةٌ تعرضها قائمتان (المورّدون في
        // المسابح وفي المحاسبة) خيرُ رجوعٍ منها ما كان صفحةً لا لوحةَ تحكم.
        const rank = (e: { group: GuardedNavItem; child: GuardedNavItem }) => [
            e.child.href.length,
            e.group.href.startsWith('/') ? 1 : 0,
            e.child.shared ? 0 : 1,
        ];

        const owner = navItems.value
            .flatMap((group) => (group.children ?? []).map((child) => ({ group, child: child as GuardedNavItem })))
            .filter(({ child }) => urlBelongsTo(url, child.href))
            .sort((a, b) => {
                const [x, y] = [rank(a), rank(b)];
                return y[0] - x[0] || y[1] - x[1] || y[2] - x[2];
            })[0];

        if (owner) {
            // داخل الشاشة لا عليها: الرجوع خطوةٌ واحدة إلى الشاشة نفسها.
            if (url !== owner.child.href) return { title: owner.child.title, href: owner.child.href };

            return owner.group.href.startsWith('/') ? { title: owner.group.title, href: owner.group.href } : dashboard;
        }

        // مدخلٌ مفرد (التقارير، الدعم) أو صفحةٌ خارج الشجرة كلها.
        const top = navItems.value.find((item) => !item.children && item.href !== DASHBOARD_HREF && urlBelongsTo(url, item.href));

        return top && url !== top.href ? { title: top.title, href: top.href } : dashboard;
    });

    return { navItems, backTarget };
}
