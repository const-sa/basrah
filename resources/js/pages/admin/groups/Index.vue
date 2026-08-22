<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLocale } from '@/composables/useLocale';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Calculator,
    CheckCheck,
    ChevronDown,
    Contact,
    FileSignature,
    Home,
    LayoutDashboard,
    Lock,
    Pencil,
    Plus,
    Settings,
    ShieldCheck,
    Trash2,
    Users,
    UsersRound,
    Waves,
    X,
} from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

const { t } = useLocale();

interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    permissions: string[];
    systems: string[];
    users_count: number;
    is_locked: boolean;
}
interface ModuleAction { key: string; action: string; label: string }
interface Module { key: string; label: string; actions: ModuleAction[] }
interface System {
    key: string;
    label: string;
    icon: string;
    description: string;
    permission_keys: string[];
    modules: Module[];
}

const props = defineProps<{
    roles: Role[];
    systems: System[];
    actionLabels: Record<string, string>;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.dashboard'), href: '/admin' },
    { title: t('nav.groups'), href: '/admin/groups' },
]);

// ترتيب أعمدة الإجراءات الثابت في المصفوفة.
const actionOrder = Object.keys(props.actionLabels);

const allKeys = computed(() => props.systems.flatMap((s) => s.permission_keys));

/**
 * أقسام النشاط الثلاثة تُعرض أولًا ومفصولة عن الأقسام الإدارية: هي التي
 * تُمنح للموظف عادةً، والإدارية تُمنح لمن يدير النظام لا لمن يشغّله.
 */
const ACTIVITY_KEYS = ['halls', 'chalets', 'pools'];
const activitySystems = computed(() => props.systems.filter((s) => ACTIVITY_KEYS.includes(s.key)));
const adminSystems = computed(() => props.systems.filter((s) => !ACTIVITY_KEYS.includes(s.key)));

/** أيقونة القسم كما يسمّيها SystemRegistry — الاسم وحده لا يرسم شيئًا. */
const ICONS: Record<string, Component> = {
    Building2,
    Home,
    Waves,
    LayoutDashboard,
    FileSignature,
    Calculator,
    Users,
    Contact,
    Settings,
};
const iconOf = (s: System): Component => ICONS[s.icon] ?? ShieldCheck;

const showModal = ref(false);
const editingId = ref<number | null>(null);
const lockedEditing = ref(false);

const form = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});

// ── مساعدات المصفوفة ─────────────────────────────────────────
const actionKeyFor = (m: Module, action: string): string | null =>
    m.actions.find((a) => a.action === action)?.key ?? null;

const has = (key: string) => form.permissions.includes(key);

const setKey = (key: string, on: boolean) => {
    const set = new Set(form.permissions);
    on ? set.add(key) : set.delete(key);
    form.permissions = [...set];
};

const moduleKeys = (m: Module) => m.actions.map((a) => a.key);
const moduleAllOn = (m: Module) => moduleKeys(m).every((k) => has(k));
const moduleSomeOn = (m: Module) => moduleKeys(m).some((k) => has(k));

const toggleModule = (m: Module) => {
    const on = !moduleAllOn(m);
    const set = new Set(form.permissions);
    moduleKeys(m).forEach((k) => (on ? set.add(k) : set.delete(k)));
    form.permissions = [...set];
};

// ── مستوى القسم: منح أو سحب قسم كامل بضغطة واحدة ────────────
const systemAllOn = (s: System) => s.permission_keys.every((k) => has(k));
const systemSomeOn = (s: System) => s.permission_keys.some((k) => has(k));
const systemCount = (s: System) => s.permission_keys.filter((k) => has(k)).length;

const toggleSystem = (s: System) => {
    const on = !systemAllOn(s);
    const set = new Set(form.permissions);
    s.permission_keys.forEach((k) => (on ? set.add(k) : set.delete(k)));
    form.permissions = [...set];
};

// طيّ الأقسام لتقصير النموذج — يُفتح القسم فتظهر شاشاته.
const openSystems = ref<Set<string>>(new Set());
const isOpen = (s: System) => openSystems.value.has(s.key);
const toggleOpen = (s: System) => {
    const set = new Set(openSystems.value);
    set.has(s.key) ? set.delete(s.key) : set.add(s.key);
    openSystems.value = set;
};

const allOn = computed(() => allKeys.value.every((k) => has(k)));
const toggleAll = () => {
    form.permissions = allOn.value ? [] : [...allKeys.value];
};

const selectedCount = computed(() => form.permissions.length);

// ملخص الأقسام التي تصل إليها المجموعة — يُعرض على البطاقة بدل سرد كل صلاحية.
const summary = (role: Role) =>
    props.systems
        .map((s) => {
            const granted = s.permission_keys.filter((k) => role.permissions.includes(k)).length;
            return granted ? { key: s.key, label: s.label, granted, total: s.permission_keys.length } : null;
        })
        .filter((x): x is { key: string; label: string; granted: number; total: number } => x !== null);

// ── فتح النماذج ──────────────────────────────────────────────
const openCreate = () => {
    editingId.value = null;
    lockedEditing.value = false;
    openSystems.value = new Set();
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (r: Role) => {
    editingId.value = r.id;
    lockedEditing.value = r.is_locked;
    openSystems.value = new Set();
    form.reset();
    form.clearErrors();
    form.name = r.name;
    form.description = r.description ?? '';
    form.permissions = [...r.permissions];
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editingId.value) {
        form.put(`/admin/groups/${editingId.value}`, opts);
    } else {
        form.post('/admin/groups', opts);
    }
};

const destroy = (r: Role) => {
    if (confirm(`${t('roles.delete_confirm')} «${r.name}»؟`)) {
        router.delete(`/admin/groups/${r.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="t('roles.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ t('roles.title') }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">{{ t('roles.subtitle') }}</p>
                </div>
                <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                    <Plus class="h-4 w-4" /> {{ t('roles.new') }}
                </button>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="r in roles" :key="r.id" class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-transparent transition duration-200 hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:ring-emerald-100">
                    <!-- شريط علوي متدرّج -->
                    <div class="h-1.5 brand-gradient"></div>

                    <div class="flex flex-1 flex-col p-5">
                        <div class="mb-3 flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl brand-gradient text-white shadow-md ring-4 ring-emerald-50"><UsersRound class="h-6 w-6" /></span>
                                <div>
                                    <div class="flex items-center gap-1.5 text-base font-extrabold text-slate-900">
                                        {{ r.name }}
                                        <Lock v-if="r.is_locked" class="h-3.5 w-3.5 text-amber-600" />
                                    </div>
                                    <div class="text-[11px] font-bold text-slate-500" dir="ltr">{{ r.slug }}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <TableActionButton variant="edit" :icon="Pencil" :title="t('common.edit')" @click="openEdit(r)" />
                                <TableActionButton v-if="!r.is_locked" variant="danger" :icon="Trash2" :title="t('common.delete')" @click="destroy(r)" />
                            </div>
                        </div>
                        <p v-if="r.description" class="mb-3 text-sm font-medium text-slate-600">{{ r.description }}</p>

                        <div class="mb-3 flex-1 space-y-1.5">
                            <template v-if="r.is_locked">
                                <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                    <CheckCheck class="h-3 w-3" /> {{ t('roles.all_permissions') }}
                                </span>
                            </template>
                            <template v-else-if="summary(r).length">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="sys in summary(r)"
                                        :key="sys.key"
                                        class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="sys.granted === sys.total ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'"
                                    >
                                        {{ sys.label }}
                                        <span class="opacity-70" dir="ltr">{{ sys.granted }}/{{ sys.total }}</span>
                                    </span>
                                </div>
                            </template>
                            <span v-else class="text-[11px] font-medium text-slate-500">{{ t('roles.no_permissions') }}</span>
                        </div>

                        <div class="flex items-center gap-1.5 border-t border-slate-100 pt-3 text-xs font-bold text-slate-600">
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2.5 py-1 text-slate-700">
                                <Users class="h-3.5 w-3.5" /> {{ r.users_count }} {{ t('roles.users_suffix') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? t('roles.edit_role') : t('roles.new_role') }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">{{ t('roles.name') }}</label>
                                <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">{{ t('roles.description') }}</label>
                                <input v-model="form.description" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                            </div>
                        </div>

                        <div v-if="lockedEditing" class="flex items-center gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-700">
                            <Lock class="h-4 w-4 shrink-0" /> {{ t('roles.locked_note') }}
                        </div>

                        <div v-else>
                            <div class="mb-2.5 flex items-center justify-between">
                                <label class="block text-sm font-bold text-slate-700">{{ t('roles.permissions') }}</label>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">{{ selectedCount }} {{ t('roles.permission_count') }}</span>
                                    <button type="button" @click="toggleAll" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-600">
                                        <CheckCheck class="h-3.5 w-3.5" /> {{ allOn ? t('roles.unselect_all') : t('roles.select_all') }}
                                    </button>
                                </div>
                            </div>

                            <!-- أقسام النشاط ثم الأقسام الإدارية، وكل قسم يُفتح فتظهر شاشاته -->
                            <template v-for="group in [
                                { title: t('roles.activity_sections'), items: activitySystems, accent: true },
                                { title: t('roles.admin_sections'), items: adminSystems, accent: false },
                            ]" :key="group.title">
                                <div class="mb-2 flex items-center gap-2" :class="group.accent ? 'mt-1' : 'mt-5'">
                                    <span class="h-px flex-1" :class="group.accent ? 'bg-emerald-200' : 'bg-slate-200'"></span>
                                    <span class="text-[11px] font-extrabold" :class="group.accent ? 'text-emerald-700' : 'text-slate-500'">{{ group.title }}</span>
                                    <span class="h-px flex-1" :class="group.accent ? 'bg-emerald-200' : 'bg-slate-200'"></span>
                                </div>

                                <div class="space-y-2.5">
                                  <div v-for="s in group.items" :key="s.key" class="overflow-hidden rounded-2xl border shadow-sm transition" :class="systemSomeOn(s) ? 'border-emerald-200' : 'border-slate-200'">
                                    <!-- رأس القسم: منح أو سحب القسم كاملاً -->
                                    <div class="flex items-center gap-3 px-4 py-3 transition" :class="systemSomeOn(s) ? 'bg-emerald-50/60' : 'bg-slate-50'">
                                        <input
                                            type="checkbox"
                                            :checked="systemAllOn(s)"
                                            :indeterminate="!systemAllOn(s) && systemSomeOn(s)"
                                            @change="toggleSystem(s)"
                                            :title="t('roles.toggle_system')"
                                            class="h-[18px] w-[18px] shrink-0 cursor-pointer rounded-md border-emerald-300 text-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:ring-offset-0"
                                        />
                                        <button type="button" @click="toggleOpen(s)" class="flex min-w-0 flex-1 items-center justify-between gap-3 text-right">
                                            <span class="flex min-w-0 items-center gap-2.5">
                                                <span
                                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition"
                                                    :class="systemSomeOn(s) ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600'"
                                                >
                                                    <component :is="iconOf(s)" class="h-4 w-4" />
                                                </span>
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-extrabold text-slate-900">{{ s.label }}</span>
                                                    <span class="block truncate text-[11px] font-medium text-slate-500">{{ s.description }}</span>
                                                </span>
                                            </span>
                                            <span class="flex shrink-0 items-center gap-2">
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                                                    :class="systemSomeOn(s) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                                    dir="ltr"
                                                >{{ systemCount(s) }}/{{ s.permission_keys.length }}</span>
                                                <ChevronDown class="h-4 w-4 text-slate-400 transition" :class="isOpen(s) && 'rotate-180'" />
                                            </span>
                                        </button>
                                    </div>

                                    <table v-show="isOpen(s)" class="w-full border-collapse border-t border-slate-200 text-sm">
                                        <thead>
                                            <tr>
                                                <th class="border-b border-slate-200 bg-slate-100 px-4 py-2 text-right text-xs font-extrabold text-slate-700">{{ t('roles.module') }}</th>
                                                <th v-for="act in actionOrder" :key="act" class="border-b border-slate-200 bg-slate-100 px-2 py-2 text-center text-xs font-extrabold text-slate-700">{{ actionLabels[act] }}</th>
                                                <th class="border-b border-r border-emerald-200 bg-emerald-100 px-2 py-2 text-center text-xs font-extrabold text-emerald-700">{{ t('roles.all') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="m in s.modules" :key="m.key" class="group border-b border-slate-100 transition last:border-0 hover:bg-emerald-50/30">
                                                <td class="px-4 py-2.5 text-right">
                                                    <span class="flex items-center gap-2 font-extrabold text-slate-800">
                                                        <span class="h-2 w-2 rounded-full bg-emerald-500 transition group-hover:bg-emerald-600"></span>
                                                        {{ m.label }}
                                                    </span>
                                                </td>
                                                <td v-for="act in actionOrder" :key="act" class="px-2 py-2.5 text-center">
                                                    <label v-if="actionKeyFor(m, act)" class="inline-flex cursor-pointer items-center justify-center">
                                                        <input
                                                            type="checkbox"
                                                            :checked="has(actionKeyFor(m, act)!)"
                                                            @change="setKey(actionKeyFor(m, act)!, ($event.target as HTMLInputElement).checked)"
                                                            class="h-[18px] w-[18px] cursor-pointer rounded-md border-slate-300 text-emerald-600 transition focus:ring-2 focus:ring-emerald-200 focus:ring-offset-0"
                                                        />
                                                    </label>
                                                    <span v-else class="mx-auto block h-1 w-3 rounded-full bg-slate-100" title="غير متاح لهذه الشاشة"></span>
                                                </td>
                                                <td class="bg-emerald-50/40 px-2 py-2.5 text-center group-hover:bg-emerald-50/70">
                                                    <input
                                                        type="checkbox"
                                                        :checked="moduleAllOn(m)"
                                                        :indeterminate="!moduleAllOn(m) && moduleSomeOn(m)"
                                                        @change="toggleModule(m)"
                                                        class="h-[18px] w-[18px] cursor-pointer rounded-md border-emerald-300 text-emerald-600 transition focus:ring-2 focus:ring-emerald-200 focus:ring-offset-0"
                                                    />
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                  </div>
                                </div>
                            </template>

                            <p class="mt-2 text-[11px] font-medium text-slate-500">{{ t('roles.matrix_hint') }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">{{ t('common.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
