import { existsSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const inputs = [
    'resources/js/app.js',
    'resources/css/app.css',
    'resources/js/admin/admin.js',
    'resources/js/logistics/logistics.js',
    'resources/js/pickup_courier/pickup_courier.js',
    'resources/js/seller/seller.js',
    'resources/js/buyer/buyer.js',
    'resources/js/home/home.js',
    // These blade views request their CSS file directly via @vite() as
    // its own asset (not just imported from JS/Vue <style>), so each
    // needs its own declared entry — the Vite dev server will proxy any
    // file on request without this, but a production build only emits
    // manifest entries for files reachable from `input`, so without
    // this line `npm run build` breaks those portals with "Unable to
    // locate file in Vite manifest".
    'resources/css/seller/layout.css',
    'resources/css/buyer/layout.css',
    'resources/css/home/layout.css',
    'resources/css/logistics/logistics.css',
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
    server: {
        hmr: {
            host: 'buythewaymarket.shop',
            protocol: 'https',
            port: 443,
        },
    },
});
