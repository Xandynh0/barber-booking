import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// When served through the docker-compose nginx proxy, the browser only
// knows about APP_PORT; the HMR websocket must advertise that port instead
// of the internal 5173 the Vite dev server actually listens on.
const isProxied = process.env.VITE_DEV_SERVER_PROXIED === 'true'
const proxyPort = Number(process.env.APP_PORT) || 8080

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    // Docker Desktop on Windows does not reliably forward native filesystem
    // change events across the Windows -> WSL2/Linux boundary for bind
    // mounts, so chokidar's default watcher can miss edits entirely and
    // Vite keeps serving an old module graph. Polling guarantees changes
    // are detected regardless of how the host delivers (or fails to
    // deliver) fs events.
    watch: {
      usePolling: true,
      interval: 300,
    },
    hmr: isProxied ? { host: 'localhost', clientPort: proxyPort } : undefined,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/setupTests.js',
  },
})
