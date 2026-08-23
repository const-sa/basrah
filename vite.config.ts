import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Split the framework code that every page loads into its own chunk so it
                // stays cached across deploys — only the app entry rehashes on a change.
                manualChunks: (id) =>
                    /[\\/]node_modules[\\/](vue|@vue|@inertiajs|axios)[\\/]/.test(id) ? 'vendor' : undefined,
            },
        },
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
    },
});
