import { useLocale } from '@/composables/useLocale';
import { usePermissions } from '@/composables/usePermissions';
import { type NavItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    Archive as ArchiveIcon,
    Boxes,
    Building2,
    CalculatorIcon,
    CalendarDays,
    CalendarRange,
    Contact,
    CreditCard,
    FileBarChart2,
    FileSignature,
    FileText,
    History,
    Home,
    LayoutDashboard,
    LifeBuoy,
    Lightbulb,
    MapPin,
    Megaphone,
    MessageCircle,
    Package as PackageIcon,
    PartyPopper,
    Receipt,
    Ruler,
    ScrollText,
    Settings,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
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
export type GuardedNavItem = NavItem & { perm?: string; children?: GuardedNavItem[] };

/**
 * هل ينتمي المسار الحالي إلى هذا المدخل؟ يشمل صفحاته الفرعية،
 * فصفحة الإنشاء تحت «حجوزات القاعات» تُبقي القسم مضاءً.
 */
export const urlBelongsTo = (currentUrl: string, href: string): boolean => currentUrl === href || currentUrl.startsWith(`${href}/`);

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
        // «الإدارة» أولًا وتجمع ما لا يتبع نظامًا بعينه: لوحة التحكم والعملاء
        // والتقارير وما إليها. تركها مبعثرة في سطر أفقي كان يُطيل
        // الشريط بمداخل مفردة ويزاحم الأنظمة التي لها أقسام.
        {
            title: t('nav.administration'),
            href: '#administration',
            icon: LayoutDashboard,
            children: [
                { title: t('nav.dashboard'), href: '/admin', icon: LayoutDashboard, perm: 'dashboard.view' },
                { title: t('nav.clients'), href: '/admin/clients', icon: Contact, perm: 'clients.view' },
                { title: t('nav.reports'), href: '/admin/reports', icon: FileBarChart2, perm: 'reports.view' },
                { title: t('nav.support'), href: '/admin/tickets', icon: LifeBuoy, perm: 'tickets.view' },
                { title: t('nav.notifications_library'), href: '/admin/notifications/library', icon: Megaphone, perm: 'notifications.view' },
                { title: t('nav.about'), href: '/admin/about', icon: Lightbulb },
            ],
        },
        // نظاما القاعات والشاليهات منفصلان تمامًا: لكلٍّ حجوزاته وتقويمه ونموذجه،
        // لأن القاعة تُحجز بفترة داخل يوم والشاليه يُحجز بليالٍ ممتدة. جمعهما في
        // مدخل «حجوزات» واحد كان يُخفي هذا الاختلاف ويجعل الموظف يبحث عن الشاشة.
        {
            title: t('nav.halls'),
            href: '#halls',
            icon: Building2,
            perm: 'units.view',
            children: [
                { title: t('nav.hall_bookings'), href: '/admin/bookings/halls', icon: CalendarDays, perm: 'bookings.view' },
                { title: t('nav.hall_calendar'), href: '/admin/calendar/halls', icon: CalendarRange, perm: 'calendar.view' },
                { title: t('nav.hall_month_calendar'), href: '/admin/calendar/halls/month', icon: CalendarDays, perm: 'calendar.view' },
                { title: t('nav.all_halls'), href: '/admin/units/halls', icon: Building2, perm: 'units.view' },
                { title: t('nav.packages'), href: '/admin/packages', icon: PackageIcon, perm: 'packages.view' },
                { title: t('nav.event_types'), href: '/admin/event-types', icon: PartyPopper, perm: 'event_types.view' },
                { title: t('nav.hall_contract_template'), href: '/admin/units/contract-template', icon: FileText, perm: 'units.view' },
                // كل قاعة مدخل مستقل: لها حجوزاتها وفواتيرها وربحيتها، فالوصول
                // إليها مباشرة أسرع من تصفية الشاشات العامة في كل مرة.
                ...unitsOfType('hall').map((u) => ({
                    title: u.name,
                    href: `/admin/units/${u.id}/workspace`,
                    icon: Building2,
                    perm: 'units.view',
                })),
            ],
        },
        {
            title: t('nav.chalets'),
            href: '#chalets',
            icon: Home,
            perm: 'units.view',
            children: [
                { title: t('nav.chalet_bookings'), href: '/admin/bookings/chalets', icon: CalendarDays, perm: 'bookings.view' },
                { title: t('nav.chalet_calendar'), href: '/admin/calendar/chalets', icon: CalendarRange, perm: 'calendar.view' },
                { title: t('nav.all_chalets'), href: '/admin/units/chalets', icon: Home, perm: 'units.view' },
                ...unitsOfType('chalet').map((u) => ({
                    title: u.name,
                    href: `/admin/units/${u.id}/workspace`,
                    icon: Home,
                    perm: 'units.view',
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
            ],
        },
        {
            title: t('nav.pos'),
            href: '#pos',
            icon: ShoppingCart,
            children: [
                { title: t('nav.cashier'), href: '/admin/pos', icon: ShoppingCart, perm: 'pos.view' },
                { title: t('nav.sales'), href: '/admin/sales', icon: Receipt, perm: 'sales.view' },
                { title: t('nav.items'), href: '/admin/items', icon: Boxes, perm: 'items.view' },
                { title: t('nav.measure_units'), href: '/admin/inventory/units', icon: Ruler, perm: 'items.view' },
                { title: t('nav.movements'), href: '/admin/inventory/movements', icon: History, perm: 'inventory.view' },
            ],
        },
        {
            title: t('nav.accounting'),
            href: '#accounting',
            icon: CalculatorIcon,
            children: [
                { title: t('nav.accounts'), href: '/admin/accounting/accounts', icon: CalculatorIcon, perm: 'accounts.view' },
                // المورّد طرف دائن في دفاتر المحاسبة قبل أن يكون شاشة إدارية،
                // فمكانه مع الحسابات والسندات لا مع لوحة التحكم والعملاء.
                { title: t('nav.suppliers'), href: '/admin/suppliers', icon: Truck, perm: 'suppliers.view' },
                { title: t('nav.journal'), href: '/admin/accounting/journal', icon: FileText, perm: 'journal.view' },
                { title: t('nav.vouchers'), href: '/admin/accounting/vouchers', icon: Receipt, perm: 'vouchers.view' },
                { title: t('nav.fin_reports'), href: '/admin/accounting/reports', icon: FileBarChart2, perm: 'fin_reports.view' },
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
                { title: t('nav.roles'), href: '/admin/roles', icon: ShieldCheck, perm: 'roles.view' },
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
                { title: t('nav.settings_whatsapp'), href: '/admin/settings/whatsapp', icon: MessageCircle, perm: 'settings.view' },
                { title: t('nav.settings_payment_methods'), href: '/admin/settings/payment-methods', icon: CreditCard, perm: 'payment_methods.view' },
                { title: t('nav.departments'), href: '/admin/departments', icon: Building2, perm: 'departments.view' },
                { title: t('nav.cities'), href: '/admin/cities', icon: MapPin, perm: 'cities.view' },
            ],
        },
    ]);

    /**
     * إخفاء ما لا يملك المستخدم صلاحيته، وإخفاء المجموعة كاملة إذا خلت من أبنائها.
     * الحماية الفعلية على الخادم — هذا تنظيف للواجهة فقط.
     */
    const navItems = computed<GuardedNavItem[]>(() =>
        allNavItems.value
            .map((item) => {
                if (!item.children) return item.perm && !can(item.perm) ? null : item;

                const children = item.children.filter((c) => !c.perm || can(c.perm));
                return children.length ? { ...item, children } : null;
            })
            .filter((item): item is GuardedNavItem => item !== null),
    );

    return { navItems };
}
