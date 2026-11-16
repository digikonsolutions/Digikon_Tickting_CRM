// Seeds one real user per role into the isolated e2e database, using the real application flows wherever
// they exist:
//   admin                      tinker (app:create-admin needs an interactive hidden password prompt)
//   employee, agency owner     admin signs in and calls POST /api/auth/users (real invitation email)
//   agency client              the owner signs in and calls POST /api/agency/users
//   passwords                  set through POST /api/auth/reset-password, using the link read from the mail log
//   team lead, QA, member      tinker (no creation path exists in the application for these roles)
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import { API_ORIGIN, backendDir, logFile } from './support/prepare.mjs'
import { MULTI_USER, PASSWORD_PREFIX, ROLE_USERS } from './support/users.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const API = `${API_ORIGIN}/api`

// Minimal cookie-aware client for the real API (session cookie + XSRF cookie).
class Api {
  jar = new Map()

  async call(method, path, body, attempt = 0) {
    if (method !== 'GET') await this.call('GET', '/auth/csrf-cookie')
    const xsrf = this.jar.get('XSRF-TOKEN')
    const response = await fetch(`${API}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Cookie: [...this.jar].map(([name, value]) => `${name}=${value}`).join('; '),
        ...(xsrf && method !== 'GET' ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    })
    for (const line of response.headers.getSetCookie()) {
      const [pair] = line.split(';')
      const index = pair.indexOf('=')
      this.jar.set(pair.slice(0, index), pair.slice(index + 1))
    }
    const text = await response.text()
    let json = null
    try { json = JSON.parse(text) } catch { json = null }
    // The real throttles apply during seeding too (password links: 3 a minute). Wait out the window and retry.
    if (response.status === 429 && attempt < 3) {
      const wait = Math.min(70, Number(response.headers.get('retry-after')) || 61)
      console.log(`[e2e] rate limited on ${path}; waiting ${wait}s`)
      await new Promise((resolve) => setTimeout(resolve, wait * 1000))
      return this.call(method, path, body, attempt + 1)
    }
    if (!response.ok && !(path === '/auth/csrf-cookie')) {
      throw new Error(`${method} ${path} -> ${response.status} ${text.slice(0, 300)}`)
    }
    return json
  }
}

// Laravel's log mailer already decodes the message body, so the link is readable as it is. Find the newest reset link for an address.
function resetLinkFor(email) {
  const decoded = readFileSync(logFile, 'utf8')
  const matches = [...decoded.matchAll(/reset-password\?token=([A-Za-z0-9]+)&(?:amp;)?email=([^&"\s<>]+)/g)]
    .map((match) => ({ token: match[1], email: decodeURIComponent(match[2]).toLowerCase() }))
    .filter((match) => match.email === email.toLowerCase())
  if (!matches.length) throw new Error(`No password link for ${email} found in ${logFile}`)
  return matches[matches.length - 1]
}

function tinker(code, extraEnv = {}) {
  return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    cwd: backendDir,
    env: { ...process.env, APP_ENV: 'e2e', ...extraEnv },
    encoding: 'utf8',
  })
}

async function setPassword(email, password) {
  const { token } = resetLinkFor(email)
  await new Api().call('POST', '/auth/reset-password', { token, email, password, password_confirmation: password })
}

export default async function globalSetup() {
  const users = [...ROLE_USERS, MULTI_USER].map((user, index) => ({ ...user, password: `${PASSWORD_PREFIX}${index + 1}` }))
  const by = (role) => users.find((user) => user.role === role)
  const adminUser = by('admin')
  const employee = by('employee')
  const owner = by('agency_owner')
  const client = by('agency_client')
  const multi = users.find((user) => user.email === MULTI_USER.email)

  // 1. Administrator (tinker: the artisan command cannot take its password non-interactively).
  tinker("App\\Models\\User::create(['name' => getenv('E2E_NAME'), 'email' => getenv('E2E_EMAIL'), 'password' => getenv('E2E_PASSWORD'), 'role' => App\\Enums\\Role::Admin, 'agency_id' => null, 'is_active' => true]);", {
    E2E_NAME: adminUser.name, E2E_EMAIL: adminUser.email, E2E_PASSWORD: adminUser.password,
  })

  // 2. Admin invites an employee, a second employee and an agency owner through the real endpoint.
  const admin = new Api()
  await admin.call('POST', '/auth/login', { email: adminUser.email, password: adminUser.password })
  await admin.call('POST', '/auth/users', { name: employee.name, email: employee.email, role: 'employee' })
  await admin.call('POST', '/auth/users', { name: multi.name, email: multi.email, role: 'employee' })
  await admin.call('POST', '/auth/users', { name: owner.name, email: owner.email, role: 'agency_owner', agency_name: 'E2E Agency' })
  for (const user of [employee, multi, owner]) await setPassword(user.email, user.password)

  // 3. The owner invites a client into their agency.
  const ownerApi = new Api()
  await ownerApi.call('POST', '/auth/login', { email: owner.email, password: owner.password })
  await ownerApi.call('POST', '/agency/users', { name: client.name, email: client.email, role: 'agency_client' })
  await setPassword(client.email, client.password)

  // 4. Roles with no creation path: tinker.
  for (const role of ['team_lead', 'qa', 'agency_member']) {
    const user = by(role)
    tinker("$agency = App\\Models\\Agency::where('name', 'E2E Agency')->first(); App\\Models\\User::create(['name' => getenv('E2E_NAME'), 'email' => getenv('E2E_EMAIL'), 'password' => getenv('E2E_PASSWORD'), 'role' => App\\Enums\\Role::from(getenv('E2E_ROLE')), 'agency_id' => getenv('E2E_ROLE') === 'agency_member' ? $agency->id : null, 'is_active' => true]);", {
      E2E_NAME: user.name, E2E_EMAIL: user.email, E2E_PASSWORD: user.password, E2E_ROLE: role,
    })
  }

  writeFileSync(resolve(here, '.users.json'), JSON.stringify(users, null, 2))
  console.log(`[e2e] seeded ${users.length} users: ${users.map((user) => user.role).join(', ')}`)
}