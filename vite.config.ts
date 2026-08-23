import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',
                'resources/js/admin/admin.js',
                'resources/js/logistics/logistics.js',
                'resources/js/pickup_courier/pickup_courier.js',
                'resources/js/seller/seller.js',
                'resources/js/buyer/buyer.js',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});
