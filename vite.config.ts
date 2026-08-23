import { existsSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const inputs = [
    'resources/js/app.js',
    'resources/js/admin/admin.js',
    'resources/js/seller/seller.js',
].filter((entry) => existsSync(entry));

export default defineConfig({
    plugins: [
        laravel({
            input: inputs,
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});