<script setup lang="ts" generic="T extends Record<string, any>">
import { Link } from '@inertiajs/vue3';

export interface Column {
    key: string;
    label: string;
    align?: 'start' | 'center' | 'end';
    /** كلاسات إضافية لخلية هذا العمود (اختياري) */
    cellClass?: string;
    /** تهيئة اتجاه LTR للأرقام/البريد */
    ltr?: boolean;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        rowKey?: string;
        /** عمود ترقيم تسلسلي في البداية (#) */
        showIndex?: boolean;
        /** روابط الترقيم القادمة من Laravel paginator */
        links?: PaginationLink[];
        emptyText?: string;
        /** إظهار عمود الإجراءات (يُملأ عبر slot #actions) */
        actions?: boolean;
        actionsLabel?: string;
    }>(),
    {
        rowKey: 'id',
        showIndex: false,
        emptyText: 'لا توجد بيانات لعرضها.',
        actions: false,
        actionsLabel: 'الإجراءات',
    },
);

const alignClass = (a?: string) => (a === 'center' ? 'text-center' : a === 'end' ? 'text-end' : 'text-start');
</script>

<template>
    <div class="space-y-4">
        <!-- شريط أدوات علوي: حبّات إحصائية + بحث + إضافة -->
        <div v-if="$slots.toolbar" class="flex flex-wrap items-center gap-2">
            <slot name="toolbar" />
        </div>

        <!-- الجدول -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b-2 border-slate-200 bg-white text-[#1e3a8a]">
                        <th v-if="showIndex" class="px-4 py-3 text-center font-bold">#</th>
                        <th v-for="col in columns" :key="col.key" :class="['px-4 py-3 font-bold', alignClass(col.align)]">
                            {{ col.label }}
                        </th>
                        <th v-if="actions" class="px-4 py-3 text-center font-bold">{{ actionsLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="row[rowKey] ?? i" :class="['border-b border-slate-100 transition hover:bg-emerald-50/60', i % 2 === 1 ? 'bg-slate-50/70' : 'bg-white']">
                        <td v-if="showIndex" class="px-4 py-3 text-center text-slate-400">{{ i + 1 }}</td>
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            :dir="col.ltr ? 'ltr' : undefined"
                            :class="['px-4 py-3 text-slate-700', alignClass(col.align), col.cellClass]"
                        >
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]" :index="i">
                                {{ row[col.key] ?? '—' }}
                            </slot>
                        </td>
                        <td v-if="actions" class="px-4 py-3">
                            <div class="flex items-center justify-center gap-1.5">
                                <slot name="actions" :row="row" :index="i" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td :colspan="columns.length + (showIndex ? 1 : 0) + (actions ? 1 : 0)" class="px-4 py-12 text-center text-sm text-slate-400">
                            <slot name="empty">{{ emptyText }}</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ترقيم الصفحات -->
        <div v-if="links && links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
            <template v-for="(link, i) in links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    :class="[
                        'rounded-lg px-3 py-1.5 text-sm font-bold transition',
                        link.active ? 'bg-cyan-500 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
                    ]"
                    v-html="link.label"
                />
                <span v-else class="rounded-lg px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
