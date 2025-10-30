// vite.config.js
import { defineConfig } from "file:///var/www/html/GRTravel/node_modules/vite/dist/node/index.js";
import laravel from "file:///var/www/html/GRTravel/node_modules/laravel-vite-plugin/dist/index.mjs";
import vue from "file:///var/www/html/GRTravel/node_modules/@vitejs/plugin-vue/dist/index.mjs";
var vite_config_default = defineConfig({
  plugins: [
    laravel({
      input: "resources/js/app.js",
      ssr: "resources/js/ssr.js",
      refresh: true,
      publicDirectory: "public",
      // Keep the public directory for assets
      buildDirectory: "build"
      // Vite output directory
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false
        }
      }
    })
  ],
  ssr: {
    noExternal: ["vue", "@protonemedia/laravel-splade"]
  },
  base: "/public/"
});
export {
  vite_config_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsidml0ZS5jb25maWcuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbImNvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9kaXJuYW1lID0gXCIvdmFyL3d3dy9odG1sL0dSVHJhdmVsXCI7Y29uc3QgX192aXRlX2luamVjdGVkX29yaWdpbmFsX2ZpbGVuYW1lID0gXCIvdmFyL3d3dy9odG1sL0dSVHJhdmVsL3ZpdGUuY29uZmlnLmpzXCI7Y29uc3QgX192aXRlX2luamVjdGVkX29yaWdpbmFsX2ltcG9ydF9tZXRhX3VybCA9IFwiZmlsZTovLy92YXIvd3d3L2h0bWwvR1JUcmF2ZWwvdml0ZS5jb25maWcuanNcIjtpbXBvcnQgeyBkZWZpbmVDb25maWcgfSBmcm9tIFwidml0ZVwiO1xuaW1wb3J0IGxhcmF2ZWwgZnJvbSBcImxhcmF2ZWwtdml0ZS1wbHVnaW5cIjtcbmltcG9ydCB2dWUgZnJvbSBcIkB2aXRlanMvcGx1Z2luLXZ1ZVwiO1xuXG5leHBvcnQgZGVmYXVsdCBkZWZpbmVDb25maWcoe1xuICAgIHBsdWdpbnM6IFtcbiAgICAgICAgbGFyYXZlbCh7XG4gICAgICAgICAgICBpbnB1dDogXCJyZXNvdXJjZXMvanMvYXBwLmpzXCIsXG4gICAgICAgICAgICBzc3I6IFwicmVzb3VyY2VzL2pzL3Nzci5qc1wiLFxuICAgICAgICAgICAgcmVmcmVzaDogdHJ1ZSxcbiAgICAgICAgICAgIHB1YmxpY0RpcmVjdG9yeTogJ3B1YmxpYycsIC8vIEtlZXAgdGhlIHB1YmxpYyBkaXJlY3RvcnkgZm9yIGFzc2V0c1xuICAgICAgICAgICAgYnVpbGREaXJlY3Rvcnk6ICdidWlsZCcsICAvLyBWaXRlIG91dHB1dCBkaXJlY3RvcnlcbiAgICAgICAgfSksXG4gICAgICAgIHZ1ZSh7XG4gICAgICAgICAgICB0ZW1wbGF0ZToge1xuICAgICAgICAgICAgICAgIHRyYW5zZm9ybUFzc2V0VXJsczoge1xuICAgICAgICAgICAgICAgICAgICBiYXNlOiBudWxsLFxuICAgICAgICAgICAgICAgICAgICBpbmNsdWRlQWJzb2x1dGU6IGZhbHNlLFxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB9LFxuICAgICAgICB9KSxcbiAgICBdLFxuICAgIHNzcjoge1xuICAgICAgICBub0V4dGVybmFsOiBbXCJ2dWVcIiwgXCJAcHJvdG9uZW1lZGlhL2xhcmF2ZWwtc3BsYWRlXCJdXG4gICAgfSxcbiAgICBiYXNlOiAnL3B1YmxpYy8nLFxufSk7XG4iXSwKICAibWFwcGluZ3MiOiAiO0FBQW9QLFNBQVMsb0JBQW9CO0FBQ2pSLE9BQU8sYUFBYTtBQUNwQixPQUFPLFNBQVM7QUFFaEIsSUFBTyxzQkFBUSxhQUFhO0FBQUEsRUFDeEIsU0FBUztBQUFBLElBQ0wsUUFBUTtBQUFBLE1BQ0osT0FBTztBQUFBLE1BQ1AsS0FBSztBQUFBLE1BQ0wsU0FBUztBQUFBLE1BQ1QsaUJBQWlCO0FBQUE7QUFBQSxNQUNqQixnQkFBZ0I7QUFBQTtBQUFBLElBQ3BCLENBQUM7QUFBQSxJQUNELElBQUk7QUFBQSxNQUNBLFVBQVU7QUFBQSxRQUNOLG9CQUFvQjtBQUFBLFVBQ2hCLE1BQU07QUFBQSxVQUNOLGlCQUFpQjtBQUFBLFFBQ3JCO0FBQUEsTUFDSjtBQUFBLElBQ0osQ0FBQztBQUFBLEVBQ0w7QUFBQSxFQUNBLEtBQUs7QUFBQSxJQUNELFlBQVksQ0FBQyxPQUFPLDhCQUE4QjtBQUFBLEVBQ3REO0FBQUEsRUFDQSxNQUFNO0FBQ1YsQ0FBQzsiLAogICJuYW1lcyI6IFtdCn0K
