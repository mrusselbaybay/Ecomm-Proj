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
                // buyer/dashboard.blade.php was requesting buyer.js via
                // @vite() without it being declared here — the dev server
                // proxies any file on request so this only ever showed up
                // as a production-build gap ("Unable to locate file in
                // Vite manifest"). Added now because the new homepage
                // links into /buyer/dashboard and that link needs to
                // actually build.
                'resources/js/buyer/buyer.js',
                // These blade views request their CSS file directly via
                // @vite() as its own asset (not just imported from JS/Vue
                // <style>), so each needs its own declared entry — the Vite
                // dev server will proxy any file on request without this,
                // but a production build only emits manifest entries for
                // files reachable from `input`, so without this line
                // `npm run build` breaks those portals with
                // "Unable to locate file in Vite manifest".
                'resources/css/seller/layout.css',
                'resources/css/buyer/layout.css',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});