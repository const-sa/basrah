<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Account {
    id: number; code: string; name: string; parent_id: number | null;
    type: string; type_label: string; is_group: boolean; is_active: boolean;
    balance: number | null;
}

const props = defineProps<{
    accounts: Account[];
    types: { key: string; label: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'شجرة الحسابات', href: '/admin/accounting/accounts' },
];

const money = (n: number | null) =>
    n === null ? '' : new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

/** بناء الشجرة بالمستويات من قائمة مسطّحة. */
interface TreeNode extends Account { depth: number }

const collapsed = ref<Set<number>>(new Set());

const tree = computed<TreeNode[]>(() => {
    const byParent = new Map<number | null, Account[]>();
    for (const a of props.accounts) {
        const list = byParent.get(a.parent_id) ?? [];
        list.push(a);
        byParent.set(a.parent_id, list);
    }

    const out: TreeNode[] = [];
    const walk = (parent: number | null, depth: number) => {
        for (const a of byParent.get(parent) ?? []) {
            out.push({ ...a, depth });
            if (!collapsed.value.has(a.id)) walk(a.id, depth + 1);
        }
    };
    walk(null, 0);

    return out;
});

const hasChildren = (id: number) => props.accounts.some((a) => a.parent_id === id);

const toggleCollapse = (id: number) => {
    const set = new Set(collapsed.value);
    set.has(id) ? set.delete(id) : set.add(id);
    collapsed.value = set;
};

const typeClass = (t: string) =>
    ({
        asset: 'bg-sky-100 text-sky-700',
        liability: 'bg-amber-100 text-amber-700',
        equity: 'bg-violet-100 text-violet-700',
        revenue: 'bg-emerald-100 text-emerald-700',
        expense: 'bg-red-100 text-red-700',
    })[t] ?? 'bg-slate-100 text-slate-700';

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    code: '', name: '', parent_id: null as number | null,
    type: 'asset', is_group: false, opening_balance: 0, is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (a: Account) => {
    editingId.value = a.id;
    form.clearErrors();
    form.code = a.code;
    form.name = a.name;
    form.parent_id = a.parent_id;
    form.type = a.type;
    form.is_group = a.is_group;
    form.is_active = a.is_active;
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/accounting/accounts/${editingId.value}`, opts) : form.post('/admin/accounting/accounts', opts);
};

const destroy = (a: Account) => {
    if (confirm(`حذف الحساب «${a.name}»؟`)) router.delete(`/admin/accounting/accounts/${a.id}`, { preserveScroll: true });
};

const groupAccounts = computed(() => props.accounts.filter((a) => a.is_group));
</script>

<template>
    <Head title="شجرة الحسابات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">شجرة الحسابات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">الأرصدة من القيود المرحَّلة فقط — المسوّدات لا تُحتسب</p>
                </div>
                <button v-if="can('accounts.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Plus class="h-4 w-4" /> حساب جديد
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الحساب</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">النوع</th>
                            <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">الرصيد</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in tree" :key="a.id" class="border-t border-slate-100 hover:bg-slate-50" :class="[a.is_group && 'bg-slate-50/60', !a.is_active && 'opacity-50']">
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-1.5" :style="{ paddingInlineStart: `${a.depth * 1.25}rem` }">
                                    <button v-if="hasChildren(a.id)" type="button" @click="toggleCollapse(a.id)" class="text-slate-400 hover:text-slate-600">
                                        <ChevronDown class="h-3.5 w-3.5 transition" :class="collapsed.has(a.id) && '-rotate-90'" />
                                    </button>
                                    <span v-else class="w-3.5"></span>
                                    <span class="font-mono text-[11px] text-slate-500" dir="ltr">{{ a.code }}</span>
                                    <span :class="a.is_group ? 'font-extrabold text-slate-800' : 'font-bold text-slate-700'">{{ a.name }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span class="rounded-md px-2 py-0.5 text-[10px] font-bold" :class="typeClass(a.type)">{{ a.type_label }}</span>
                            </td>
                            <td class="px-4 py-2 text-left font-extrabold" :class="(a.balance ?? 0) < 0 ? 'text-red-600' : 'text-slate-800'" dir="ltr">
                                {{ money(a.balance) }}
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex items-center justify-center gap-1">
                                    <TableActionButton v-if="can('accounts.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(a)" />
                                    <TableActionButton v-if="can('accounts.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(a)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل حساب' : 'حساب جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3 px-6 py-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الكود</label>
                            <input v-model="form.code" :disabled="!!editingId" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm disabled:bg-slate-50" />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                            <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                    </div>

                    <div v-if="!editingId" class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">النوع</label>
                            <select v-model="form.type" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الحساب الأب</label>
                            <select v-model="form.parent_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">— حساب رئيسي —</option>
                                <option v-for="g in groupAccounts" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                            </select>
                        </div>
                    </div>

                    <label v-if="!editingId" class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="form.is_group" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                        حساب تجميعي (لا يُرحَّل عليه مباشرة)
                    </label>

                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                        فعّال
                    </label>

                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
