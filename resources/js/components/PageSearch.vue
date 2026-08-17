<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { router } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';
import {
    Bell, Boxes, Building2, CalculatorIcon, CalendarDays, CalendarRange, Contact, CornerDownLeft,
    FileBarChart2, FileSignature, FileText, History, Home, LayoutDashboard, LifeBuoy, MapPin,
    Megaphone, MessageCircle, Receipt, Search, ShieldCheck, ShoppingCart, SlidersHorizontal,
    Truck, Users, Wallet, type LucideIcon,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Page {
    href: string;
    icon: LucideIcon;
    title: string;
    keywords: string[];
    /** الصلاحية التي تُظهر الصفحة في البحث؛ بلا قيمة تظهر للجميع. */
    perm?: string;
}

const { t } = useLocale();
const { can } = usePermissions();

// فهرس صفحات اللوحة القابلة للبحث. الكلمات المفتاحية تشمل مرادفات عربية/إنجليزية
// لتسهيل الوصول حتى لو اختلفت صياغة المستخدم عن عنوان الصفحة.
const allPages = computed<Page[]>(() => [
    { href: '/admin', icon: LayoutDashboard, title: t('nav.dashboard'), perm: 'dashboard.view', keywords: ['home', 'dashboard', 'رئيسية', 'الرئيسية'] },

    // القاعات — حجز مناسبة بفترة داخل يوم
    { href: '/admin/bookings/halls', icon: CalendarDays, title: t('nav.hall_bookings'), perm: 'bookings.view', keywords: ['bookings', 'halls', 'حجز', 'حجوزات', 'قاعة', 'قاعات', 'مناسبة', 'موعد'] },
    { href: '/admin/calendar/halls', icon: CalendarRange, title: t('nav.hall_calendar'), perm: 'calendar.view', keywords: ['calendar', 'halls', 'تقويم', 'روزنامة', 'جدول', 'قاعات'] },
    { href: '/admin/units/halls', icon: Building2, title: t('nav.halls'), perm: 'units.view', keywords: ['halls', 'units', 'قاعة', 'قاعات', 'وحدة', 'وحدات', 'اقسام'] },

    // الشاليهات — إقامة ممتدة بالليالي
    { href: '/admin/bookings/chalets', icon: CalendarDays, title: t('nav.chalet_bookings'), perm: 'bookings.view', keywords: ['bookings', 'stays', 'chalets', 'حجز', 'حجوزات', 'شاليه', 'شاليهات', 'اقامة', 'مبيت', 'ليالي'] },
    { href: '/admin/calendar/chalets', icon: CalendarRange, title: t('nav.chalet_calendar'), perm: 'calendar.view', keywords: ['calendar', 'chalets', 'تقويم', 'روزنامة', 'جدول', 'شاليهات', 'اشغال'] },
    { href: '/admin/units/chalets', icon: Home, title: t('nav.chalets'), perm: 'units.view', keywords: ['chalets', 'units', 'شاليه', 'شاليهات', 'وحدة', 'وحدات', 'اقسام'] },
    { href: '/admin/units/contract-template', icon: FileText, title: t('nav.hall_contract_template'), perm: 'units.view', keywords: ['contract', 'form', 'نموذج', 'العقد', 'عقد', 'شروط', 'احكام', 'قاعات'] },

    // العقود والواتساب
    { href: '/admin/contracts', icon: FileSignature, title: t('nav.contracts_list'), perm: 'contracts.view', keywords: ['contracts', 'عقد', 'عقود'] },
    { href: '/admin/contract-templates', icon: FileText, title: t('nav.contract_templates'), perm: 'contract_templates.view', keywords: ['templates', 'قالب', 'قوالب', 'عقد'] },
    { href: '/admin/whatsapp-log', icon: MessageCircle, title: t('nav.whatsapp_log'), perm: 'whatsapp.view', keywords: ['whatsapp', 'واتساب', 'رسائل', 'سجل'] },

    // نقطة البيع
    { href: '/admin/pos', icon: ShoppingCart, title: t('nav.cashier'), perm: 'pos.view', keywords: ['pos', 'cashier', 'كاشير', 'بيع', 'فاتورة'] },
    { href: '/admin/items', icon: Boxes, title: t('nav.items'), perm: 'items.view', keywords: ['items', 'products', 'صنف', 'اصناف', 'مخزون', 'جرد'] },
    { href: '/admin/inventory/movements', icon: History, title: t('nav.movements'), perm: 'inventory.view', keywords: ['movements', 'حركات', 'مخزون'] },

    // المحاسبة
    { href: '/admin/accounting/accounts', icon: CalculatorIcon, title: t('nav.accounts'), perm: 'accounts.view', keywords: ['accounts', 'حساب', 'حسابات', 'شجرة'] },
    { href: '/admin/accounting/journal', icon: FileText, title: t('nav.journal'), perm: 'journal.view', keywords: ['journal', 'قيد', 'قيود', 'يومية'] },
    { href: '/admin/accounting/vouchers', icon: Receipt, title: t('nav.vouchers'), perm: 'vouchers.view', keywords: ['vouchers', 'سند', 'سندات', 'قبض', 'صرف', 'خزينة'] },
    { href: '/admin/accounting/reports', icon: FileBarChart2, title: t('nav.fin_reports'), perm: 'fin_reports.view', keywords: ['financial', 'ميزانية', 'ميزان', 'دخل', 'ربحية'] },

    // الموارد البشرية
    { href: '/admin/hr/staff', icon: Users, title: t('nav.staff'), perm: 'staff.view', keywords: ['staff', 'employees', 'موظف', 'موظفين', 'ملفات'] },
    { href: '/admin/hr/attendance', icon: CalendarRange, title: t('nav.attendance'), perm: 'attendance.view', keywords: ['attendance', 'حضور', 'غياب', 'انصراف'] },
    { href: '/admin/hr/leaves', icon: FileText, title: t('nav.leaves'), perm: 'leaves.view', keywords: ['leaves', 'advances', 'اجازة', 'اجازات', 'سلفة', 'سلف'] },
    { href: '/admin/hr/payroll', icon: Wallet, title: t('nav.payroll'), perm: 'payroll.view', keywords: ['payroll', 'راتب', 'رواتب', 'مسير'] },

    // العملاء والإدارة
    { href: '/admin/clients', icon: Contact, title: t('nav.clients'), perm: 'clients.view', keywords: ['clients', 'customers', 'عميل', 'عملاء'] },
    { href: '/admin/suppliers', icon: Truck, title: t('nav.suppliers'), perm: 'suppliers.view', keywords: ['suppliers', 'مورد', 'موردين'] },
    { href: '/admin/employees', icon: ShieldCheck, title: t('nav.employees_list'), perm: 'employees.view', keywords: ['users', 'مستخدمين', 'صلاحيات', 'نطاق'] },
    { href: '/admin/roles', icon: ShieldCheck, title: t('nav.roles'), perm: 'roles.view', keywords: ['roles', 'permissions', 'دور', 'ادوار', 'صلاحيات'] },
    { href: '/admin/cities', icon: MapPin, title: t('nav.cities'), perm: 'cities.view', keywords: ['cities', 'city', 'مدينة', 'مدن'] },
    { href: '/admin/departments', icon: Building2, title: t('nav.departments'), perm: 'departments.view', keywords: ['departments', 'قسم', 'اقسام'] },
    { href: '/admin/tickets', icon: LifeBuoy, title: t('nav.support'), perm: 'tickets.view', keywords: ['support', 'tickets', 'دعم', 'تذكرة', 'تذاكر'] },
    { href: '/admin/reports', icon: FileBarChart2, title: t('nav.reports'), perm: 'reports.view', keywords: ['reports', 'تقرير', 'تقارير'] },
    { href: '/admin/notifications', icon: Bell, title: t('header.notifications'), perm: 'notifications.view', keywords: ['notifications', 'اشعار', 'اشعارات'] },
    { href: '/admin/notifications/library', icon: Megaphone, title: t('nav.notifications_library'), perm: 'notifications.view', keywords: ['library', 'مكتبة', 'قوالب'] },
    { href: '/admin/settings/general', icon: SlidersHorizontal, title: t('nav.settings_general'), perm: 'settings.view', keywords: ['settings', 'اعدادات', 'عامة'] },
    { href: '/admin/settings/whatsapp', icon: MessageCircle, title: t('nav.settings_whatsapp'), perm: 'settings.view', keywords: ['whatsapp', 'واتساب', 'واتس'] },
]);

// لا يُقترح على المستخدم ما لا يملك صلاحيته — اقتراح يفضي إلى 403 إزعاج لا مساعدة.
const pages = computed<Page[]>(() => allPages.value.filter((p) => !p.perm || can(p.perm)));

const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const query = ref('');
const open = ref(false);
const active = ref(0);

const results = computed<Page[]>(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return pages.value;
    return pages.value.filter((p) => p.title.toLowerCase().includes(q) || p.keywords.some((k) => k.includes(q)));
});

// إعادة ضبط العنصر المميّز عند تغيّر النتائج حتى لا يتجاوز حدود القائمة.
const onInput = () => {
    open.value = true;
    active.value = 0;
};

const go = (page?: Page) => {
    const target = page ?? results.value[active.value];
    if (!target) return;
    open.value = false;
    query.value = '';
    input.value?.blur();
    router.visit(target.href);
};

const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        open.value = true;
        active.value = Math.min(results.value.length - 1, active.value + 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        go();
    } else if (e.key === 'Escape') {
        open.value = false;
        input.value?.blur();
    }
};

// اختصار عام Ctrl/⌘ + K لتركيز مربّع البحث من أي مكان.
const onGlobalKey = (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        input.value?.focus();
        open.value = true;
    }
};

// إغلاق القائمة عند النقر خارج المكوّن.
const onClickOutside = (e: MouseEvent) => {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false;
};

onMounted(() => {
    window.addEventListener('keydown', onGlobalKey);
    document.addEventListener('mousedown', onClickOutside);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKey);
    document.removeEventListener('mousedown', onClickOutside);
});
</script>

<template>
    <div ref="root" class="relative w-full max-w-md">
        <div class="flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-white transition focus-within:border-emerald-400/60 focus-within:bg-white/15">
            <Search class="h-4 w-4 shrink-0 text-slate-300" />
            <input
                ref="input"
                v-model="query"
                type="search"
                :placeholder="t('header.search_placeholder')"
                autocomplete="off"
                class="w-full border-0 bg-transparent p-0 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-0"
                @focus="open = true"
                @input="onInput"
                @keydown="onKeydown"
            />
            <kbd class="hidden items-center gap-0.5 rounded border border-white/20 px-1.5 py-0.5 text-[10px] font-bold text-slate-300 sm:flex">Ctrl K</kbd>
        </div>

        <!-- قائمة النتائج -->
        <div
            v-if="open"
            class="absolute inset-x-0 top-[calc(100%+8px)] z-50 overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-800 shadow-2xl"
        >
            <ul class="max-h-[60vh] overflow-y-auto py-1.5">
                <li v-for="(p, i) in results" :key="p.href">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2.5 text-start text-sm transition"
                        :class="i === active ? 'bg-emerald-50 text-emerald-700' : 'text-slate-700 hover:bg-slate-50'"
                        @mouseenter="active = i"
                        @mousedown.prevent="go(p)"
                    >
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                            :class="i === active ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500'"
                        >
                            <component :is="p.icon" class="h-4 w-4" />
                        </span>
                        <span class="flex-1 font-bold">{{ p.title }}</span>
                        <CornerDownLeft v-if="i === active" class="h-3.5 w-3.5 text-emerald-500" />
                    </button>
                </li>
                <li v-if="results.length === 0" class="px-4 py-8 text-center text-sm text-slate-400">
                    {{ t('header.search_empty') }}
                </li>
            </ul>
        </div>
    </div>
</template>
