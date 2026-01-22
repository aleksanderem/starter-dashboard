import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "path";

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": resolve(__dirname, "./src"),
      "@/utils/cx": resolve(__dirname, "./src/cx.ts"),
      "@/utils/is-react-component": resolve(__dirname, "./src/is-react-component.ts"),
      "@/components": resolve(__dirname, "./src/components"),
      "styles/globals.css": resolve(__dirname, "./src/globals.css"),
    },
  },
  build: {
    outDir: "dist",
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, "src/wp-command-menu.tsx"),
      output: {
        entryFileNames: "wp-command-menu.js",
        chunkFileNames: "chunks/[name]-[hash].js",
        assetFileNames: "assets/[name][extname]",
      },
    },
  },
});
