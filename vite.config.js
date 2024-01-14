import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import path from 'path'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.scss',
                'resources/css/app.dark.scss',
                'resources/css/app.dark.auto.scss',
                'resources/js/app.js'
            ],
            refresh: true,
            hotFile: '/dev/null' // disable hot reload file
        })
    ],
    resolve: {
        alias: {
            '~bootstrap': path.resolve(__dirname, 'node_modules/bootstrap'),
        }
    },
    server: {
        host: '0.0.0.0',
        hmr: false,
        strictPort: true,
        cors: {
            origin: '*'
        }
    }
});
