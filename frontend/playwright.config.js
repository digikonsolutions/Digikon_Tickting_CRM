import { defineConfig } from '@playwright/test'
import { prepareBackend, API_ORIGIN, APP_ORIGIN, backendDir } from './e2e/support/prepare.mjs'

// Only the runner process prepares the isolated backend (workers re-import this file and must not reset the database).
if (!process.env.TEST_WORKER_INDEX && !process.env.E2E_SKIP_PREPARE) prepareBackend()

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.js',
  globalSetup: './e2e/global-setup.mjs',
  timeout: 90_000,
  expect: { timeout: 10_000 },
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: APP_ORIGIN,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  // Real servers, no mocks: Laravel on the isolated e2e database and the Vite dev server pointed at it.
  webServer: [
    {
      command: 'php artisan serve --host=localhost --port=8100',
      cwd: backendDir,
      env: { APP_ENV: 'e2e', PHP_CLI_SERVER_WORKERS: '1' },
      url: `${API_ORIGIN}/api/auth/csrf-cookie`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: 'npx vite --port 5273 --strictPort',
      env: { VITE_API_URL: `${API_ORIGIN}/api`, VITE_AUTH_MODE: 'api' },
      url: APP_ORIGIN,
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
})