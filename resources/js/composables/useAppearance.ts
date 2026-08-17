import { ref } from 'vue';

type Appearance = 'light';

export function updateTheme(_value?: Appearance) {
    document.documentElement.classList.remove('dark');
}

export function initializeTheme() {
    document.documentElement.classList.remove('dark');
    // Clear any saved dark preference and prevent system dark mode from applying
    try {
        localStorage.setItem('appearance', 'light');
    } catch {}
}

export function useAppearance() {
    const appearance = ref<Appearance>('light');

    function updateAppearance(_value?: Appearance) {
        document.documentElement.classList.remove('dark');
    }

    return {
        appearance,
        updateAppearance,
    };
}
