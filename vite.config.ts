// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',           // Your existing auth app
                'resources/js/admin/admin.js',   // Admin dashboard entry
            ],
            refresh: true,
        }),
        vue(),
    ],
});