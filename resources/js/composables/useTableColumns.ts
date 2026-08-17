import { computed, ref, watch } from 'vue';

/**
 * إظهار أعمدة الجداول العريضة وإخفاؤها، محفوظًا في المتصفح.
 *
 * الجدول الذي يحمل عشرين عمودًا لا يُقرأ على شاشة واحدة، وإخفاء ما لا
 * يعني الموظفَ اليومَ خيرٌ من تمرير أفقي طويل. والاختيار يُحفظ لأن كل
 * موظف يراجع أعمدةً بعينها: المحاسب يريد المال، والمناوب يريد المواعيد.
 */
export interface TableColumn {
    key: string;
    label: string;
    /** عمود لا يُخفى — الهوية والتحكم يبقيان مهما ضاق العرض. */
    fixed?: boolean;
}

export interface ColumnPreset {
    key: string;
    label: string;
    columns: string[];
}

export function useTableColumns(storageKey: string, columns: TableColumn[], presets: ColumnPreset[]) {
    const fixedKeys = columns.filter((c) => c.fixed).map((c) => c.key);
    const defaultKeys = presets[0]?.columns ?? columns.map((c) => c.key);

    const read = (): string[] => {
        if (typeof window === 'undefined') return defaultKeys;

        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) return defaultKeys;

            const saved = JSON.parse(raw);

            // مفتاحٌ محفوظ لعمود حُذف من الجدول يُطرح بصمت، وإلا بقي
            // الاختيار القديم يشير إلى أعمدة لم تعد موجودة.
            return Array.isArray(saved)
                ? saved.filter((k: unknown) => typeof k === 'string' && columns.some((c) => c.key === k))
                : defaultKeys;
        } catch {
            return defaultKeys;
        }
    };

    const visible = ref<string[]>([...new Set([...read(), ...fixedKeys])]);

    watch(
        visible,
        (v) => {
            if (typeof window !== 'undefined') localStorage.setItem(storageKey, JSON.stringify(v));
        },
        { deep: true },
    );

    const shows = (key: string): boolean => visible.value.includes(key);

    const toggle = (key: string): void => {
        if (fixedKeys.includes(key)) return;

        const i = visible.value.indexOf(key);

        if (i === -1) {
            visible.value.push(key);
        } else {
            visible.value.splice(i, 1);
        }
    };

    const applyPreset = (preset: ColumnPreset): void => {
        visible.value = [...new Set([...preset.columns, ...fixedKeys])];
    };

    /** النمط المطابق للاختيار الحالي — يُبرزه الزر ليعرف الموظف موضعه. */
    const activePreset = computed(() => {
        const current = [...visible.value].sort().join('|');

        return presets.find((p) => [...new Set([...p.columns, ...fixedKeys])].sort().join('|') === current)?.key ?? null;
    });

    /** عدد الأعمدة المعروضة من مجموعة بعينها — تحتاجه خلايا colspan. */
    const countOf = (keys: string[]): number => keys.filter(shows).length;

    return { visible, shows, toggle, applyPreset, activePreset, countOf };
}
