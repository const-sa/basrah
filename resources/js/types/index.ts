import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: number | string | null;
    children?: NavItem[];
}

export interface SharedData {
    name: string;
    version: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash?: {
        success?: string;
        warning?: string;
    };
    notificationsUnread?: number;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role?: string | null;
    is_active?: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

/**
 * خيار طريقة دفع كما يرسله الخادم من `PaymentMethod::options`.
 *
 * مشتركٌ بين شاشات الحجوزات والكاشير والمبيعات والسندات: كانت كل شاشة تكتب
 * قائمتها نصًّا، فتُضاف طريقة في الجدول ولا تظهر في نصف الشاشات.
 */
export interface PaymentMethodOption {
    id: number;
    code: string;
    label: string;
    is_credit: boolean;
}
