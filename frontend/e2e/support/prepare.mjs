// Builds the isolated end-to-end backend: writes backend/.env.e2e, creates a fresh SQLite database and
// proves the e2e environment (not your normal .env) is the one in use before anything runs.
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
export const backendDir = resolve(here, '../../../backend')
export const dbPath = resolve(backendDir, 'database/e2e.sqlite')
export const envFile = resolve(backendDir, '.env.e2e')
export const logFile = resolve(backendDir, 'storage/logs/laravel.log')
export const API_ORIGIN = 'http://localhost:8100'
export const APP_ORIGIN = 'http://localhost:5273'

export function artisan(args, { input } = {}) {
  return execFileSync('php', ['artisan', ...args], {
    cwd: backendDir,
    env: { ...process.env, APP_ENV: 'e2e' },
    input,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  })
}

export function prepareBackend() {
  mkdirSync(dirname(dbPath), { recursive: true })
  let key = ''
  if (existsSync(envFile)) key = /^APP_KEY=(.+)$/m.exec(readFileSync(envFile, 'utf8'))?.[1] ?? ''
  if (!key) key = execFileSync('php', ['artisan', 'key:generate', '--show'], { cwd: backendDir, encoding: 'utf8' }).trim()

  const template = readFileSync(resolve(backendDir, '.env.e2e.example'), 'utf8')
    .replace(/^APP_KEY=.*$/m, `APP_KEY=${key}`)
    .replace(/^DB_DATABASE=.*$/m, `DB_DATABASE=${dbPath.replaceAll('\\', '/')}`)
  writeFileSync(envFile, template)
  if (!existsSync(dbPath)) writeFileSync(dbPath, '')

  // Safety: refuse to continue unless artisan really resolves to the e2e database.
  const database = artisan(['tinker', '--execute=echo DB::connection()->getDatabaseName();']).trim()
  const env = artisan(['tinker', '--execute=echo app()->environment();']).trim()
  if (!database.endsWith('e2e.sqlite') || env !== 'e2e') {
    throw new Error(`Refusing to run: expected the e2e sqlite database, got "${database}" in environment "${env}".`)
  }

  // Start every run from an empty database and an empty log, so reset links are never stale.
  artisan(['migrate:fresh', '--force'])
  artisan(['cache:clear']) // rate-limit counters live in the file cache and must not survive between runs
  writeFileSync(logFile, '')
}