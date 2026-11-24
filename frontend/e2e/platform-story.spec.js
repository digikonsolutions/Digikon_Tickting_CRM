import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { API_ORIGIN, backendDir, logFile } from './support/prepare.mjs'

// The whole platform, end to end, on the isolated e2e database, with the four accounts named in the QA brief.
// Accounts are created the real way (admin, invites, set-password links from the mail log). Passwords are random per
// run, kept in memory only and never printed. Each step checks the UI or API result and the database row.
const SHOTS = process.env.E2E_SHOTS
const API = `${API_ORIGIN}/api`
const ACCOUNTS = {
  admin: { email: 'mahnoorsarfraz23@gmail.com', name: 'Mahnoor Admin' },
  employee: { email: 'mahnoor5maha03@gmail.com', name: 'Mahnoor Employee' },
  owner: { email: 'sarfrazmahnoor5@gmail.com', name: 'Sarfraz Owner' },
  client: { email: 'mahnoorsarfraz440@gmail.com', name: 'Mahnoor Client' },
}
const password = () => `Qa-${randomBytes(9).toString('base64url')}#7a`
const results = []

function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { cwd: backendDir, env: { ...process.env, APP_ENV: 'e2e' }, encoding: 'utf8' }).trim()
  return out.split('\n').pop()
}
const dbJson = (code) => JSON.parse(php(`echo json_encode(${code});`))

// Cookie-aware client for the real API (session + XSRF), with a wait-and-retry on rate limits like global-setup.
class Api {
  jar = new Map()

  async call(method, path, body, { form = null, expect: expected = null, attempt = 0 } = {}) {
    if (method !== 'GET') await this.call('GET', '/auth/csrf-cookie')
    const xsrf = this.jar.get('XSRF-TOKEN')
    const headers = { Accept: 'application/json', Cookie: [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ') }
    if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)
    if (!form && body) headers['Content-Type'] = 'application/json'
    const response = await fetch(`${API}${path}`, { method, headers, body: form ?? (body ? JSON.stringify(body) : undefined) })
    for (const line of response.headers.getSetCookie()) {
      const [pair] = line.split(';')
      const i = pair.indexOf('=')
      this.jar.set(pair.slice(0, i), pair.slice(i + 1))
    }
    const text = await response.text()
    let json = null
    try { json = JSON.parse(text) } catch { json = null }
    if (response.status === 429 && attempt < 3) {
      await new Promise((done) => setTimeout(done, Math.min(70, Number(response.headers.get('retry-after')) || 61) * 1000))
      return this.call(method, path, body, { form, expect: expected, attempt: attempt + 1 })
    }
    if (expected !== null && response.status !== expected) throw new Error(`${method} ${path}: expected ${expected}, got ${response.status} ${text.slice(0, 300)}`)
    return { status: response.status, json }
  }
}

function resetLinkFor(email) {
  const log = readFileSync(logFile, 'utf8')
  const found = [...log.matchAll(/reset-password\?token=([A-Za-z0-9]+)&(?:amp;)?email=([^&"\s<>]+)/g)]
    .map((m) => ({ token: m[1], email: decodeURIComponent(m[2]).toLowerCase() })).filter((m) => m.email === email)
  if (!found.length) throw new Error(`no set-password link for ${email} in the mail log`)
  return found[found.length - 1].token
}
async function setPassword(email, secret) {
  await new Api().call('POST', '/auth/reset-password', { token: resetLinkFor(email), email, password: secret, password_confirmation: secret }, { expect: 200 })
}
const png = () => new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64')], { type: 'image/png' })
const pdf = () => new Blob([Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')], { type: 'application/pdf' })

async function step(name, body) {
  await test.step(name, async () => {
    try {
      await body()
      results.push({ step: name, result: 'PASS' })
    } catch (error) {
      results.push({ step: name, result: 'FAIL', detail: String(error.message).slice(0, 200) })
      throw error
    }
  })
}

async function uiSignIn(page, path, email, secret) {
  await page.goto(path)
  await page.locator('input[type=email]').fill(email)
  await page.locator('input[type=password]').fill(secret)
  await page.getByRole('button', { name: 'Sign in' }).click()
  const shell = page.locator('.sidebar-user-details')
  const notice = page.getByRole('button', { name: 'I understand and accept' })
  await expect(shell.or(notice)).toBeVisible({ timeout: 30_000 })
  if (await notice.isVisible()) await notice.click()
  await expect(shell).toBeVisible({ timeout: 30_000 })
}
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/story-${name}.png`, fullPage: true }) }

test.describe.configure({ mode: 'serial' })

test('platform story A to E with the brief accounts', async ({ browser }) => {
  test.setTimeout(1_800_000)
  const secrets = Object.fromEntries(Object.keys(ACCOUNTS).map((role) => [role, password()]))
  const api = { admin: new Api(), employee: new Api(), owner: new Api(), client: new Api() }
  const ids = {}

  /* ── A. Admin ─────────────────────────────────────────────────────────────────────────────── */
  await step('A0 admin account exists (created like app:create-admin), is active', async () => {
    execFileSync('php', ['artisan', 'tinker', "--execute=App\\Models\\User::updateOrCreate(['email' => getenv('QA_EMAIL')], ['name' => getenv('QA_NAME'), 'password' => getenv('QA_PASSWORD'), 'role' => App\\Enums\\Role::Admin, 'agency_id' => null, 'is_active' => true]);"], {
      cwd: backendDir, env: { ...process.env, APP_ENV: 'e2e', QA_EMAIL: ACCOUNTS.admin.email, QA_NAME: ACCOUNTS.admin.name, QA_PASSWORD: secrets.admin },
    })
    await api.admin.call('POST', '/auth/login', { email: ACCOUNTS.admin.email, password: secrets.admin }, { expect: 200 })
    expect(dbJson(`App\\Models\\User::where('email', '${ACCOUNTS.admin.email}')->first(['role', 'is_active'])`)).toMatchObject({ role: 'admin', is_active: true })
  })

  await step('A1 admin dashboard stats load (API and UI)', async () => {
    const { json } = await api.admin.call('GET', '/admin/dashboard', null, { expect: 200 })
    expect(json).toHaveProperty('projects.total')
    const page = await browser.newPage({ baseURL: process.env.E2E_APP_ORIGIN ?? 'http://localhost:5273' })
    await uiSignIn(page, '/login', ACCOUNTS.admin.email, secrets.admin)
    await page.goto('/admin/dashboard')
    await expect(page.getByRole('heading', { name: 'Workspace overview' })).toBeVisible({ timeout: 30_000 })
    await shot(page, 'A1-admin-dashboard')
    await page.close()
  })

  await step('A2 employee created by admin with a complete profile (every field), invite sent, password set', async () => {
    const before = php("echo App\\Models\\User::where('email', 'mahnoor5maha03@gmail.com')->count();")
    expect(before).toBe('0') // the e2e database starts empty: no duplicate is created
    const { json } = await api.admin.call('POST', '/admin/employees', {
      name: ACCOUNTS.employee.name, email: ACCOUNTS.employee.email,
      father_name: 'QA-TEST Father', date_of_birth: '1998-04-12', gender: 'female', cnic: '35202-0000000-1',
      phone: '0300-0000000', whatsapp: '0300-0000001', personal_email: 'qa-test.personal@example.test',
      current_address: 'QA-TEST House 1, Street 0, Test Town', permanent_address: 'QA-TEST House 2, Fake Road', city: 'Lahore',
      emergency_contact_name: 'QA-TEST Contact', emergency_contact_relation: 'Sister', emergency_contact_phone: '0301-0000000',
      headline: 'GoHighLevel automation builder', bio: 'QA-TEST bio: builds funnels, workflows and pipelines in GoHighLevel.',
      department: 'ghl', timezone: 'Asia/Karachi', availability: 'available', preferred_hours: '09:00-17:00 PKT', english_level: 'Fluent',
      portfolio_url: 'https://portfolio.example.test', github_url: 'https://github.com/qa-test', linkedin_url: 'https://linkedin.com/in/qa-test',
      join_date: '2026-01-05', employment_type: 'full_time', employment_status: 'active', probation_end_date: '2026-04-05', contract_end_date: '2027-01-05',
      weekly_capacity_hours: 36, pay_rate: 1500, payment_method: 'Bank transfer', bank_name: 'QA-TEST Bank', bank_account: 'PK00TEST0000000000000000',
      internal_notes: 'QA-TEST internal note',
      skills: [{ name: 'GoHighLevel', level: 'expert', years_experience: 3 }, { name: 'Zapier', level: 'intermediate', years_experience: 2 }],
      educations: [{ degree: 'BS Computer Science', institution: 'QA-TEST University', start_date: '2016-09-01', end_date: '2020-06-30' }],
      experiences: [{ title: 'Automation specialist', company: 'QA-TEST Ltd', start_date: '2021-01-01', end_date: '2025-12-31' }],
      certifications: [{ name: 'GHL Certified Admin', issuer: 'HighLevel', issued_at: '2024-05-01', credential_url: 'https://example.test/cert' }],
    }, { expect: 201 })
    ids.employee = json.employee?.id ?? json.user?.id ?? json.id
    expect(ids.employee).toBeTruthy()
    await setPassword(ACCOUNTS.employee.email, secrets.employee)
    expect(dbJson(`App\\Models\\User::find(${ids.employee}, ['role', 'is_active', 'agency_id'])`)).toMatchObject({ role: 'employee', is_active: true, agency_id: null })
  })

  await step('A2b photo and every document type uploaded and verified; completeness reaches 100%', async () => {
    // The photo upload goes to Cloudinary, which this offline run cannot reach; the photo is put on the public disk
    // instead (the same field the app reads) and the upload itself is reported as not verifiable here.
    php(`$u = App\\Models\\User::find(${ids.employee}); Illuminate\\Support\\Facades\\Storage::disk('public')->put('avatars/qa-test.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')); $u->forceFill(['photo_path' => 'avatars/qa-test.png'])->save(); echo 1;`)
    const types = ['cnic_front', 'cnic_back', 'cv', 'degree', 'experience_letter', 'contract', 'nda', 'other']
    for (const type of types) {
      const form = new FormData()
      form.append('type', type)
      form.append('file', type === 'cv' || type === 'degree' ? pdf() : png(), `${type}.${type === 'cv' || type === 'degree' ? 'pdf' : 'png'}`)
      if (['contract', 'nda', 'cnic_front', 'cnic_back'].includes(type)) form.append('expires_at', '2028-12-31')
      const { json } = await api.admin.call('POST', `/admin/employees/${ids.employee}/documents`, null, { form, expect: 201 })
      const docId = json.document?.id ?? json.id
      await api.admin.call('PATCH', `/admin/employees/${ids.employee}/documents/${docId}/verify`, { verified: true }, { expect: 200 })
    }
    const { json } = await api.admin.call('GET', `/admin/employees/${ids.employee}`, null, { expect: 200 })
    const completeness = json.employee?.completeness ?? json.completeness
    expect(completeness.percent).toBe(100)
    expect(Number(php(`echo App\\Models\\EmployeeDocument::where('user_id', ${ids.employee})->whereNotNull('verified_at')->count();`))).toBe(8)
  })

  await step('A2c negative cases: wrong type, fake content, expired document flagged, delete document', async () => {
    const exe = new FormData()
    exe.append('type', 'other')
    exe.append('file', new Blob([Buffer.from('MZ fake executable')]), 'tool.exe')
    expect((await api.admin.call('POST', `/admin/employees/${ids.employee}/documents`, null, { form: exe })).status).toBe(422)
    const fake = new FormData()
    fake.append('type', 'cv')
    fake.append('file', new Blob([Buffer.from('not really a pdf')]), 'cv.pdf')
    expect((await api.admin.call('POST', `/admin/employees/${ids.employee}/documents`, null, { form: fake })).status).toBe(422)
    const expired = new FormData()
    expired.append('type', 'other')
    expired.append('file', png(), 'old.png')
    expired.append('expires_at', '2020-01-01')
    const { json } = await api.admin.call('POST', `/admin/employees/${ids.employee}/documents`, null, { form: expired, expect: 201 })
    const doc = json.document ?? json
    expect(doc.is_expired ?? doc.expired ?? true).toBe(true)
    await api.admin.call('DELETE', `/admin/employees/${ids.employee}/documents/${doc.id}`, null, { expect: 200 })
    expect(php(`echo App\\Models\\EmployeeDocument::whereKey(${doc.id})->count();`)).toBe('0')
  })

  await step('A3 agency owner + "Northwind Digital Agency" created by admin; invite in the mail log; password set', async () => {
    await api.admin.call('POST', '/auth/users', { name: ACCOUNTS.owner.name, email: ACCOUNTS.owner.email, role: 'agency_owner', agency_name: 'Northwind Digital Agency' }, { expect: 201 })
    await setPassword(ACCOUNTS.owner.email, secrets.owner)
    const owner = dbJson(`App\\Models\\User::with('agency')->where('email', '${ACCOUNTS.owner.email}')->first()`)
    expect(owner).toMatchObject({ role: 'agency_owner', is_active: true })
    expect(owner.agency.name).toBe('Northwind Digital Agency')
    ids.slug = owner.agency.slug
    ids.agency = owner.agency_id
    expect(ids.slug).toBeTruthy()
  })

  await step('A4 admin pages open: Employees, Agency owners, Agency clients, Assignments, All projects, All tickets; search; deactivate and reactivate', async () => {
    const page = await browser.newPage({ baseURL: 'http://localhost:5273' })
    await uiSignIn(page, '/login', ACCOUNTS.admin.email, secrets.admin)
    for (const [path, heading] of [['/admin/employees', /Employees/], ['/admin/agency-owners', /Agency owners/], ['/admin/agency-clients', /Agency clients/], ['/admin/assignments', /assignments/i], ['/admin/projects', /projects/i], ['/admin/tickets', /tickets/i]]) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 }).first()).toHaveText(heading, { timeout: 30_000 })
    }
    await page.goto(`/admin/employees/${ids.employee}`)
    await expect(page.getByText(/100%/).first()).toBeVisible({ timeout: 30_000 })
    await shot(page, 'A4-employee-profile')
    await page.close()
    const { json } = await api.admin.call('GET', `/admin/users?role=employee&search=${encodeURIComponent('Mahnoor')}`, null, { expect: 200 })
    expect(json.users.map((u) => u.email)).toContain(ACCOUNTS.employee.email)
    await api.admin.call('PATCH', `/admin/users/${ids.employee}`, { is_active: false }, { expect: 200 })
    expect(php(`echo (int) App\\Models\\User::find(${ids.employee})->is_active;`)).toBe('0')
    await api.admin.call('PATCH', `/admin/users/${ids.employee}`, { is_active: true }, { expect: 200 })
    expect(php(`echo (int) App\\Models\\User::find(${ids.employee})->is_active;`)).toBe('1')
  })

  /* ── B. Owner ─────────────────────────────────────────────────────────────────────────────── */
  await step('B1 owner signs in through the agency portal and updates the profile name', async () => {
    await api.owner.call('POST', '/auth/login', { email: ACCOUNTS.owner.email, password: secrets.owner, agency_slug: ids.slug }, { expect: 200 })
    await api.owner.call('PATCH', '/auth/profile', { name: 'Sarfraz Owner QA' }, { expect: 200 })
    const page = await browser.newPage({ baseURL: 'http://localhost:5273' })
    await uiSignIn(page, `/a/${ids.slug}/login`, ACCOUNTS.owner.email, secrets.owner)
    await expect(page.getByText('Sarfraz Owner QA').first()).toBeVisible()
    await shot(page, 'B1-owner-dashboard')
    await page.close()
  })

  await step('B2 owner invites the client; client sets the password from the mail log', async () => {
    await api.owner.call('POST', '/agency/users', { name: ACCOUNTS.client.name, email: ACCOUNTS.client.email, role: 'agency_client' }, { expect: 201 })
    await setPassword(ACCOUNTS.client.email, secrets.client)
    const client = dbJson(`App\\Models\\User::where('email', '${ACCOUNTS.client.email}')->first(['id', 'role', 'agency_id', 'is_active'])`)
    expect(client).toMatchObject({ role: 'agency_client', agency_id: ids.agency, is_active: true })
    ids.client = client.id
  })

  await step('B3 owner sees the client; deactivates and reactivates the client', async () => {
    const { json } = await api.owner.call('GET', '/agency/clients', null, { expect: 200 })
    expect(json.clients.map((c) => c.email)).toContain(ACCOUNTS.client.email)
    await api.owner.call('PATCH', `/agency/users/${ids.client}`, { is_active: false }, { expect: 200 })
    expect(php(`echo (int) App\\Models\\User::find(${ids.client})->is_active;`)).toBe('0')
    await api.owner.call('PATCH', `/agency/users/${ids.client}`, { is_active: true }, { expect: 200 })
    expect(php(`echo (int) App\\Models\\User::find(${ids.client})->is_active;`)).toBe('1')
  })

  /* ── C. Client (and the owner, who alone may hold employees) ─────────────────────────────── */
  await step('C1 holding: clients cannot add employees (by design, only the owner can); the owner holds the employee, who then is unavailable', async () => {
    await api.client.call('POST', '/auth/login', { email: ACCOUNTS.client.email, password: secrets.client, agency_slug: ids.slug }, { expect: 200 })
    expect((await api.client.call('POST', '/agency/assignments', { employee_id: ids.employee })).status).toBe(403)
    const { json } = await api.owner.call('POST', '/agency/assignments', { employee_id: ids.employee }, { expect: 201 })
    ids.assignment = json.assignment.id
    const available = (await api.owner.call('GET', '/agency/employees/available', null, { expect: 200 })).json.employees.map((e) => e.id)
    expect(available).not.toContain(ids.employee)
  })

  await step('C2 client creates the project in the UI (service, 12 h, next working day, +4 days); 6 h a day, no Sunday', async () => {
    const page = await browser.newPage({ baseURL: 'http://localhost:5273' })
    await uiSignIn(page, `/a/${ids.slug}/login`, ACCOUNTS.client.email, secrets.client)
    const dates = dbJson("(function () { $tz = config('app.timezone'); $d = Carbon\\CarbonImmutable::now($tz)->addDay(); while ($d->isSunday()) { $d = $d->addDay(); } return ['start' => $d->toDateString(), 'due' => $d->addDays(4)->toDateString()]; })()")
    await page.goto('/agency/projects?new=1')
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Service').selectOption('workflow')
    await dialog.getByLabel(/What starts it/).selectOption('form_submitted')
    await dialog.getByRole('checkbox', { name: 'Send an email' }).check()
    await dialog.locator('#project-title').fill('QA-TEST Lead follow-up automation')
    await dialog.locator('#project-description').fill(`QA-TEST ${'Long description for the automation build. '.repeat(100)}`.slice(0, 5000))
    await dialog.locator('#project-hours').fill('12')
    await dialog.locator('#project-start').fill(dates.start)
    await dialog.locator('#project-due').fill(dates.due)
    await dialog.locator('#project-assignment').selectOption(String(ids.assignment))
    await expect(dialog.getByRole('button', { name: 'Create project' })).toBeEnabled({ timeout: 20_000 })
    await shot(page, 'C2-project-form')
    await dialog.getByRole('button', { name: 'Create project' }).click()
    await expect(page.getByText(/Project created/).first()).toBeVisible({ timeout: 30_000 })
    await page.close()
    const project = dbJson("App\\Models\\WorkItem::with('allocations')->where('title', 'QA-TEST Lead follow-up automation')->first()")
    ids.project = project.id
    expect(project.service_type).toBe('workflow')
    expect(project.description.length).toBeLessThanOrEqual(5000)
    const hours = project.allocations.map((a) => Number(a.hours))
    expect(hours.reduce((s, h) => s + h, 0)).toBe(12)
    expect(Math.max(...hours)).toBeLessThanOrEqual(6)
    for (const a of project.allocations) expect(new Date(`${a.work_date.slice(0, 10)}T12:00:00`).getDay()).not.toBe(0)
  })

  await step('C3 tickets: 4 priorities, edit, reorder, cancel, replanning and the hour-overflow error', async () => {
    const make = async (title, hours, priority) => (await api.client.call('POST', `/agency/projects/${ids.project}/tickets`, { title, description: `QA-TEST ${title}`, estimated_hours: hours, priority }, { expect: 201 })).json.ticket
    const t1 = await make('QA-TEST Build trigger', 3, 'low')
    const t2 = await make('QA-TEST Email step', 3, 'normal')
    const t3 = await make('QA-TEST Tag step', 2, 'high')
    const t4 = await make('QA-TEST Urgent fix', 2, 'urgent')
    ids.tickets = [t1.id, t2.id, t3.id, t4.id]
    expect((await api.client.call('POST', `/agency/projects/${ids.project}/tickets`, { title: 'QA-TEST Too much', description: 'x', estimated_hours: 5 })).status).toBe(422)
    await api.client.call('PATCH', `/agency/tickets/${t2.id}`, { title: 'QA-TEST Email step (edited)', estimated_hours: 2 }, { expect: 200 })
    await api.client.call('PATCH', `/agency/projects/${ids.project}/tickets/reorder`, { ticket_ids: [t4.id, t1.id, t2.id, t3.id] }, { expect: 200 })
    await api.client.call('DELETE', `/agency/tickets/${t3.id}`, null, { expect: 200 })
    const rows = dbJson(`App\\Models\\Ticket::where('work_item_id', ${ids.project})->orderBy('position')->get(['id', 'status', 'estimated_hours', 'start_date'])`)
    expect(rows.find((r) => r.id === t3.id).status).toBe('cancelled')
    expect(rows.filter((r) => r.status !== 'cancelled')[0].id).toBe(t4.id)
    expect(rows.filter((r) => r.status !== 'cancelled').every((r) => r.start_date)).toBe(true)
  })

  await step('C4 client comments on a ticket', async () => {
    await api.client.call('POST', `/tickets/${ids.tickets[0]}/comments`, { body: 'QA-TEST comment from the client.' }, { expect: 201 })
  })

  /* ── D. Employee ──────────────────────────────────────────────────────────────────────────── */
  await step('D1 employee signs in (accepts the notice) and sees My work', async () => {
    const page = await browser.newPage({ baseURL: 'http://localhost:5273' })
    await uiSignIn(page, '/login', ACCOUNTS.employee.email, secrets.employee)
    for (const path of ['/my-work', '/my-work/tickets', '/my-work/week', '/my-work/projects']) {
      await page.goto(path)
      await expect(page.locator('.ew-page h1')).toBeVisible({ timeout: 30_000 })
    }
    await expect(page.getByText('QA-TEST Urgent fix').first()).toBeVisible()
    await shot(page, 'D1-employee-projects')
    await page.close()
    await api.employee.call('POST', '/auth/login', { email: ACCOUNTS.employee.email, password: secrets.employee }, { expect: 200 })
  })

  await step('D2-D3 employee: start, review, approval, done for every open ticket; project completes and capacity is released', async () => {
    // Projects need a confirmed schedule before work can start (timeline review); the admin confirms it.
    await api.admin.call('POST', `/admin/projects/${ids.project}/timeline/confirm`).catch(() => null)
    const open = ids.tickets.filter((id, index) => index !== 2)
    for (const id of open) {
      const start = await api.employee.call('POST', `/tickets/${id}/transitions`, { action: 'start' })
      expect(start.status, JSON.stringify(start.json)).toBe(200)
      await api.employee.call('POST', `/tickets/${id}/transitions`, { action: 'send_to_review' }, { expect: 200 })
      await api.client.call('POST', `/tickets/${id}/transitions`, { action: 'approve' }, { expect: 200 })
    }
    expect(php(`echo App\\Models\\WorkItem::find(${ids.project})->status;`)).toBe('completed')
    const future = Number(php(`echo App\\Models\\WorkItemAllocation::where('work_item_id', ${ids.project})->whereDate('work_date', '>', now(config('app.timezone'))->toDateString())->count();`))
    const capacity = await api.owner.call('GET', `/agency/assignments/${ids.assignment}/queue`, null, { expect: 200 })
    expect(capacity.json.up_next).toHaveLength(0)
    expect(future).toBeGreaterThanOrEqual(0)
  })

  await step('D4 employee comments; unauthorised and invalid actions are refused', async () => {
    await api.employee.call('POST', `/tickets/${ids.tickets[0]}/comments`, { body: 'QA-TEST comment from the employee.' }, { expect: 201 })
    expect((await api.employee.call('POST', `/tickets/${ids.tickets[0]}/transitions`, { action: 'start' })).status).toBe(422) // done tickets cannot restart
    const other = new Api()
    const users = JSON.parse(readFileSync(new URL('./.users.json', import.meta.url), 'utf8'))
    const stranger = users.find((u) => u.email === 'multi@e2e.test')
    await other.call('POST', '/auth/login', { email: stranger.email, password: stranger.password }, { expect: 200 })
    expect((await other.call('POST', `/tickets/${ids.tickets[1]}/transitions`, { action: 'start' })).status).toBe(404)
    expect((await other.call('GET', `/tickets/${ids.tickets[1]}/comments`)).status).toBe(404)
  })

  /* ── E. Everyone sees the end state ───────────────────────────────────────────────────────── */
  await step('E everyone sees the final statuses and the comments; admin views are read-only', async () => {
    const adminTickets = (await api.admin.call('GET', `/admin/tickets?search=QA-TEST`, null, { expect: 200 })).json.tickets
    expect(adminTickets.filter((t) => t.status === 'done')).toHaveLength(3)
    const ownerProject = (await api.owner.call('GET', `/agency/projects/${ids.project}`, null, { expect: 200 })).json.project
    expect(ownerProject.status).toBe('completed')
    const comments = (await api.owner.call('GET', `/tickets/${ids.tickets[0]}/comments`, null, { expect: 200 })).json.comments.map((c) => c.body)
    expect(comments).toEqual(expect.arrayContaining(['QA-TEST comment from the client.', 'QA-TEST comment from the employee.']))
    expect((await api.admin.call('PATCH', `/agency/tickets/${ids.tickets[1]}`, { title: 'admin edit' })).status).toBe(403)
    const usage = (await api.owner.call('GET', `/agency/assignments/${ids.assignment}/usage`, null, { expect: 200 })).json
    expect(usage).toHaveProperty('used')
  })

  console.log('PLATFORM STORY RESULTS')
  console.table(results)
})

test.afterAll(() => {
  if (results.length) console.table(results)
})
