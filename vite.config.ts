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
                'resources/js/seller/seller.js',
                // seller.blade.php requests this CSS file directly via
                // @vite() as its own asset (not just imported from JS/Vue
                // <style>), so it needs its own declared entry — the Vite
                // dev server will proxy any file on request without this,
                // but a production build only emits manifest entries for
                // files reachable from `input`, so without this line
                // `npm run build` breaks the seller portal with
                // "Unable to locate file in Vite manifest".
                'resources/css/seller/layout.css',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});