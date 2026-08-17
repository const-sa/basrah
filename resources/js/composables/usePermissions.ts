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

    /** هل يملك المستخدم صلاحية محددة مثل bookings.create؟ */
    const can = (permission: string): boolean =>
        isSuperAdmin.value || (auth.value.permissions ?? []).includes(permission);

    /** هل يملك أيًا من الصلاحيات المعطاة؟ */
    const canAny = (...permissions: string[]): boolean => permissions.some(can);

    /** هل يصل المستخدم إلى نظام كامل مثل accounting؟ */
    const inSystem = (system: string): boolean =>
        isSuperAdmin.value || (auth.value.systems ?? []).includes(system);

    /** نطاق الوحدات: null يعني بلا تقييد لا «لا شيء». */
    const unitIds = computed<number[] | null>(() => auth.value.units ?? null);

    const seesAllUnits = computed(() => unitIds.value === null);

    const canAccessUnit = (unitId: number): boolean =>
        seesAllUnits.value || (unitIds.value ?? []).includes(unitId);

    return { can, canAny, inSystem, isSuperAdmin, unitIds, seesAllUnits, canAccessUnit };
}
