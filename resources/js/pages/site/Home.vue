<script setup lang="ts">
import SiteLayout, { type SiteOrg } from '@/layouts/SiteLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, Home as HomeIcon, Users } from 'lucide-vue-next';

interface UnitCard {
    id: number; name: string; type: 'hall' | 'chalet';
    capacity: number | null; logo_url: string | null;
    sections_count: number; summary: string | null;
    starting_price: number | null;
}

defineProps<{ org: SiteOrg; halls: UnitCard[]; chalets: UnitCard[] }>();

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <Head :title="org.name" />

    <SiteLayout :org="org">
        <!-- الواجهة -->
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-14 text-center">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">قاعات وشاليهات {{ org.name }}</h1>
                <p class="mx-auto mt-3 max-w-2xl text-base font-medium text-slate-600">
                    اطّلع على الوحدات وأسعارها، واحجز موعدك إلكترونيًا في دقائق. يصلك تأكيد الطلب برقم حجز
                    تراجع به حجزك في أي وقت.
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    <a href="#halls" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                        القاعات
                    </a>
                    <a href="#chalets" class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        الشاليهات
                    </a>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-6xl space-y-10 px-4 py-10">
            <!-- القاعات -->
            <section id="halls" class="scroll-mt-20">
                <h2 class="mb-4 flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                    <Building2 class="h-6 w-6 text-slate-500" /> القاعات
                </h2>

                <div v-if="halls.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="u in halls"
                        :key="u.id"
                        class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md"
                    >
                        <div class="flex h-32 items-center justify-center bg-slate-100">
                            <img v-if="u.logo_url" :src="u.logo_url" :alt="u.name" class="max-h-24 w-auto object-contain p-2" />
                            <Building2 v-else class="h-12 w-12 text-slate-300" />
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="text-lg font-extrabold text-slate-900">{{ u.name }}</h3>
                            <p v-if="u.summary" class="mt-1 line-clamp-2 text-sm text-slate-600">{{ u.summary }}</p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                <span v-if="u.capacity" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1">
                                    <Users class="h-3.5 w-3.5" /> تتسع لـ {{ u.capacity }}
                                </span>
                                <span v-if="u.sections_count" class="rounded-md bg-slate-100 px-2 py-1">
                                    {{ u.sections_count }} أقسام
                                </span>
                            </div>
                            <div class="mt-auto flex items-end justify-between pt-4">
                                <div v-if="u.starting_price">
                                    <div class="text-[11px] font-bold text-slate-500">ابتداءً من</div>
                                    <div class="text-lg font-extrabold text-slate-900" dir="ltr">{{ money(u.starting_price) }} ريال</div>
                                </div>
                                <span v-else class="text-xs text-slate-400">السعر عند الاستفسار</span>
                                <Link
                                    :href="`/units/${u.id}`"
                                    class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                                >
                                    التفاصيل <ArrowLeft class="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-2xl bg-white py-10 text-center text-sm text-slate-500">لا قاعات معروضة حاليًا</p>
            </section>

            <!-- الشاليهات -->
            <section id="chalets" class="scroll-mt-20">
                <h2 class="mb-4 flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                    <HomeIcon class="h-6 w-6 text-slate-500" /> الشاليهات
                </h2>

                <div v-if="chalets.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="u in chalets"
                        :key="u.id"
                        class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md"
                    >
                        <div class="flex h-32 items-center justify-center bg-slate-100">
                            <img v-if="u.logo_url" :src="u.logo_url" :alt="u.name" class="max-h-24 w-auto object-contain p-2" />
                            <HomeIcon v-else class="h-12 w-12 text-slate-300" />
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="text-lg font-extrabold text-slate-900">{{ u.name }}</h3>
                            <p v-if="u.summary" class="mt-1 line-clamp-2 text-sm text-slate-600">{{ u.summary }}</p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                <span v-if="u.capacity" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1">
                                    <Users class="h-3.5 w-3.5" /> تتسع لـ {{ u.capacity }}
                                </span>
                                <span v-if="u.sections_count" class="rounded-md bg-slate-100 px-2 py-1">
                                    {{ u.sections_count }} أقسام
                                </span>
                            </div>
                            <div class="mt-auto flex items-end justify-between pt-4">
                                <div v-if="u.starting_price">
                                    <div class="text-[11px] font-bold text-slate-500">الليلة ابتداءً من</div>
                                    <div class="text-lg font-extrabold text-slate-900" dir="ltr">{{ money(u.starting_price) }} ريال</div>
                                </div>
                                <span v-else class="text-xs text-slate-400">السعر عند الاستفسار</span>
                                <Link
                                    :href="`/units/${u.id}`"
                                    class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                                >
                                    التفاصيل <ArrowLeft class="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-2xl bg-white py-10 text-center text-sm text-slate-500">لا شاليهات معروضة حاليًا</p>
            </section>
        </div>
    </SiteLayout>
</template>
