import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/js/app.js","resources/js/web.js"],
            ssr: "resources/js/ssr.js",
            refresh: true,
            publicDirectory: 'public', // Keep the public directory for assets
            buildDirectory: 'build',  // Vite output directory
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
    ssr: {
        noExternal: ["vue", "@protonemedia/laravel-splade"]
    },
    base: '/public/',
});
