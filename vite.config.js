import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [laravel({input: ['workbench/resources/css/theme.css'], publicDirectory: 'workbench/public', refresh: false}), tailwindcss()],
    build: {outDir: 'workbench/public/build', emptyOutDir: true, rollupOptions: {output: {assetFileNames: 'assets/[name][extname]'}}},
});
