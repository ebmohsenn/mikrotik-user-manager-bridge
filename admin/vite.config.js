import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@mwp/shared': resolve(__dirname, 'src/common'),
      '@mwp/utils': resolve(__dirname, 'src/utils'),
      '@mwp/api': resolve(__dirname, 'src/api'),
      '@mwp/types': resolve(__dirname, 'src/types')
    }
  },
  build: {
    outDir: 'build',
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/index.jsx'),
      output: {
        entryFileNames: `index.js`,
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) return 'style.css'
          return 'assets/[name][extname]'
        }
      }
    }
  }
})
