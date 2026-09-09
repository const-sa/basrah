import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * صلاحيات المستخدم الحالي كما يشاركها HandleInertiaRequests.
 *
 * ملاحظة أمنية: هذا الفحص لإخفاء الواجهة فقط ولا يُعوَّل عليه للحماية.
 * الحماية الحقيقية في الوسيطين perm: و system: على الخادم.
 */
export function usePermissions() {
    const page = usePage();

    const auth = computed(() => (page.props.auth ?? {}) as {
        permissions?: string[];
        systems?: string[];
        units?: number[] | null;
    });

    /** المدير العام يُمرَّر له '*' بدل سرد 144 مفتاحًا. */
    const isSuperAdmin = computed(() => (auth.value.permissions ?? []).includes('*'));

    /** هل يملك المستخدم صلاحية محددة مثل hall_bookings.create؟ */
    const can = (permission: string): boolean =>
        isSuperAdmin.value || (auth.value.permissions ?? []).includes(permission);

    /** هل يملك أيًا من الصلاحيات المعطاة؟ */
    const canAny = (...permissions: string[]): boolean => permissions.some(can);

    /**
     * صلاحية على وحدة بحسب نوعها: hall → halls.edit، chalet → chalets.edit.
     * تُطابق AuthorizesByUnitType على الخادم، فما يظهر في الشاشة هو ما يُقبل.
     */
    const canUnit = (type: string | null | undefined, action: string): boolean =>
        can(`${type === 'chalet' ? 'chalets' : 'halls'}.${action}`);

    /** صلاحية على حجز بحسب نوع وحدته: hall_bookings.* أو chalet_bookings.*. */
    const canBooking = (type: string | null | undefined, action: string): boolean =>
        can(`${type === 'chalet' ? 'chalet' : 'hall'}_bookings.${action}`);

    /**
     * Clients, expenses and contracts are one screen per activity: the global key
     * answers for all three, the scoped one only for its own. Mirrors ActivityPermission.
     */
    const ACTIVITY_PREFIX: Record<string, string> = { halls: 'hall', chalets: 'chalet', pools: 'pool' };

    const canActivity = (screen: string, action: string, activity: 'halls' | 'chalets' | 'pools' | null): boolean => {
        if (can(`${screen}.${action}`)) return true;
        const prefix = activity ? ACTIVITY_PREFIX[activity] : null;
        return prefix ? can(`${prefix}_${screen}.${action}`) : false;
    };

    /** هل يصل المستخدم إلى نظام كامل مثل accounting؟ */
    const inSystem = (system: string): boolean =>
        isSuperAdmin.value || (auth.value.systems ?? []).includes(system);

    /** نطاق الوحدات: null يعني بلا تقييد لا «لا شيء». */
    const unitIds = computed<number[] | null>(() => auth.value.units ?? null);

    const seesAllUnits = computed(() => unitIds.value === null);

    const canAccessUnit = (unitId: number): boolean =>
        seesAllUnits.value || (unitIds.value ?? []).includes(unitId);

    return { can, canAny, canActivity, canUnit, canBooking, inSystem, isSuperAdmin, unitIds, seesAllUnits, canAccessUnit };
}
