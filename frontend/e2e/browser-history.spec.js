import { execFileSync } from 'node:child_process'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { chromium, expect, test } from '@playwright/test'
import { loadUsers, signInExpectingSuccess } from './helpers.js'
import { API_ORIGIN, APP_ORIGIN, backendDir } from './support/prepare.mjs'

// The real extension (unpacked, in Microsoft Edge: the bundled Chromium cannot start on this machine) next to a
// signed-in employee. Browser history must be recorded only while the ticket is in progress, linked to it, visible
// to the admin, and stop as soon as the ticket is sent for review.
const SHOTS = process.env.E2E_SHOTS
const CHANNEL = process.env.E2E_EXTENSION_CHANNEL ?? 'msedge'

function php(code) {
  return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { cwd: backendDir, env: { ...process.env, APP_ENV: 'e2e' }, encoding: 'utf8' }).trim()
}
const historyUrls = (ticketId) => JSON.parse(php(`echo json_encode(App\\Models\\BrowserHistoryEvent::where('ticket_id', ${ticketId})->where('domain', 'like', 'qa-site-%')->distinct()->orderBy('url')->pluck('url'));`).split('\n').pop())
const strayRows = () => Number(php(`echo App\\Models\\BrowserHistoryEvent::where('url', 'like', '%qa-site-4%')->count();`).split('\n').pop())

test('browser history: recorded only while the ticket is in progress, linked to it, seen by the admin', async ({ browser }) => {
  test.setTimeout(240_000)
  const users = loadUsers()
  const employee = users.find((user) => user.email === 'employee@e2e.test')
  const admin = users.find((user) => user.email === 'admin@e2e.test')

  const ticketId = Number(php(`
    $owner = App\\Models\\User::where('email', 'owner@e2e.test')->first();
    $emp = App\\Models\\User::where('email', 'employee@e2e.test')->first();
    $assignment = App\\Models\\EmployeeAssignment::firstOrCreate(['employee_id' => $emp->id, 'status' => 'active'], ['holder_id' => $owner->id, 'agency_id' => $owner->agency_id, 'source' => 'owner', 'active_employee_id' => $emp->id, 'started_at' => now()]);
    $today = Carbon\\CarbonImmutable::now(config('app.timezone'))->startOfDay();
    $item = App\\Models\\WorkItem::create(['agency_id' => $owner->agency_id, 'client_id' => $owner->id, 'assignment_id' => $assignment->id, 'employee_id' => $emp->id, 'title' => 'QA-TEST-History project', 'description' => 'Browser history e2e.', 'estimated_hours' => 12, 'start_date' => $today->toDateString(), 'due_date' => $today->addDays(5)->toDateString(), 'status' => 'scheduled', 'timeline_status' => 'confirmed']);
    $ticket = App\\Models\\Ticket::create(['agency_id' => $owner->agency_id, 'work_item_id' => $item->id, 'created_by' => $owner->id, 'number' => 700, 'title' => 'QA-TEST-History ticket', 'description' => 'x', 'estimated_hours' => 2, 'priority' => 'high', 'status' => 'todo', 'position' => 1, 'start_date' => $today->toDateString(), 'due_date' => $today->addDays(2)->toDateString(), 'timeline_status' => 'confirmed']);
    app(App\\Services\\EmployeeQueuePlanner::class)->replan($emp);
    app(App\\Services\\TicketPlanner::class)->plan($item->fresh());
    echo $ticket->id;
  `).split('\n').pop())

  const extensionPath = resolve(process.cwd(), '..', 'extension')
  const context = await chromium.launchPersistentContext(mkdtempSync(join(tmpdir(), 'clienthub-ext-')), {
    channel: CHANNEL,
    baseURL: APP_ORIGIN,
    args: [`--disable-extensions-except=${extensionPath}`, `--load-extension=${extensionPath}`],
  })
  // Pretend websites the employee browses to.
  await context.route(/http:\/\/qa-site-\d\.test\/.*/, (route) => {
    const site = new URL(route.request().url()).hostname
    route.fulfill({ contentType: 'text/html', body: `<!doctype html><title>${site} page</title><h1>${site}</h1>` })
  })
  const worker = context.serviceWorkers()[0] ?? await context.waitForEvent('serviceworker')
  const extensionId = new URL(worker.url()).host
  const extensionPage = await context.newPage()
  await extensionPage.goto(`chrome-extension://${extensionId}/popup/popup.html`)
  const syncNow = () => extensionPage.evaluate(() => chrome.runtime.sendMessage({ type: 'sync-now' }))
  const extensionState = () => extensionPage.evaluate(async () => ({ ...(await chrome.storage.local.get(['status', 'queue'])), badge: await chrome.action.getBadgeText({}) }))

  const page = await context.newPage()
  await test.step('employee signs in; the extension is pointed at the API and checks in', async () => {
    await signInExpectingSuccess(page, employee.email, employee.password)
    await extensionPage.evaluate((apiBase) => chrome.storage.local.set({ settings: { enabled: true, apiBase } }), `${API_ORIGIN}/api`)
    await syncNow()
    const state = await extensionState()
    expect(state.status.signedIn).toBe(true)
    expect(state.status.allowed).toBe(true)
    expect(state.status.recording).toBe(false) // nothing in progress yet
    expect(state.badge).toBe('')
  })

  await test.step('employee presses Start: the app and the extension both say recording is on', async () => {
    await page.goto('/my-work/tickets')
    await page.getByRole('button', { name: 'Start' }).first().click()
    await expect(page.getByText(/start done/i).first()).toBeVisible({ timeout: 20_000 })
    await syncNow()
    const state = await extensionState()
    expect(state.status.recording).toBe(true)
    expect(state.status.activeWork.ticket_id).toBe(ticketId)
    expect(state.badge).toBe('REC')
    await page.goto('/my-work')
    await expect(page.getByText(/Browser activity is being recorded for .*QA-TEST-History ticket/)).toBeVisible({ timeout: 70_000 })
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/history-recording.png` })
  })

  await test.step('three websites while the ticket is in progress are stored, linked to the ticket', async () => {
    const browsing = await context.newPage()
    for (const n of [1, 2, 3]) {
      await browsing.goto(`http://qa-site-${n}.test/work?secret=token`)
      await browsing.waitForTimeout(1200)
    }
    await browsing.close()
    await syncNow()
    await expect.poll(() => historyUrls(ticketId), { timeout: 30_000 }).toEqual([
      'http://qa-site-1.test/work', 'http://qa-site-2.test/work', 'http://qa-site-3.test/work',
    ])
  })

  await test.step('after Send to review recording stops and nothing more is stored', async () => {
    await page.goto('/my-work/tickets')
    await page.getByRole('button', { name: 'Send to review' }).first().click()
    await expect(page.getByText(/send to review done/i).first()).toBeVisible({ timeout: 20_000 })
    await syncNow()
    const state = await extensionState()
    expect(state.status.recording).toBe(false)
    expect(state.badge).toBe('')
    const browsing = await context.newPage()
    await browsing.goto('http://qa-site-4.test/after')
    await browsing.waitForTimeout(1500)
    await browsing.close()
    await syncNow()
    expect(strayRows()).toBe(0)
    expect(historyUrls(ticketId)).toHaveLength(3)
  })
  await context.close()

  await test.step('the admin sees the three websites on the ticket', async () => {
    const adminContext = await browser.newContext({ baseURL: APP_ORIGIN })
    const adminPage = await adminContext.newPage()
    await signInExpectingSuccess(adminPage, admin.email, admin.password)
    await adminPage.goto(`/admin/history?ticket_id=${ticketId}`)
    await expect(adminPage.getByText(/Showing what was recorded while ticket/)).toBeVisible({ timeout: 30_000 })
    for (const n of [1, 2, 3]) await expect(adminPage.getByText(`qa-site-${n}.test page`).first()).toBeVisible()
    await expect(adminPage.getByText('qa-site-4.test')).toHaveCount(0)
    if (SHOTS) await adminPage.screenshot({ path: `${SHOTS}/history-admin.png`, fullPage: true })
    await adminContext.close()
  })
})
