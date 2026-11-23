import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loadUsers, signInExpectingSuccess, signOut } from './helpers.js'
import { backendDir } from './support/prepare.mjs'

// Real servers, isolated e2e database: an owner with a held employee and three queued projects goes through the
// queue, a reorder with preview, an urgent request, the admin's quote, the owner's acceptance, and the charge.
// Screenshots go to E2E_SHOTS when it is set (for looking at the layout by hand).

const SHOTS = process.env.E2E_SHOTS

function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { cwd: backendDir, env: { ...process.env, APP_ENV: 'e2e' }, encoding: 'utf8' })
}

const dateIn = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)

test.describe.configure({ mode: 'serial' })

test('team queue, reorder preview, urgent request, quote, accept and charge', async ({ page }) => {
  test.setTimeout(240_000) // six sign-ins and several page loads in one story
  const users = loadUsers()
  const as = (email) => users.find((user) => user.email === email)
  const owner = as('owner@e2e.test')
  const admin = as('admin@e2e.test')
  const employee = as('multi@e2e.test')

  // Owner holds Max Multi and has three queued projects (what the app does through its own forms and planner).
  tinker(`
    $owner = App\\Models\\User::where('email', 'owner@e2e.test')->first();
    $emp = App\\Models\\User::where('email', 'multi@e2e.test')->first();
    $assignment = App\\Models\\EmployeeAssignment::create(['holder_id' => $owner->id, 'agency_id' => $owner->agency_id, 'employee_id' => $emp->id, 'status' => 'active', 'source' => 'owner', 'active_employee_id' => $emp->id, 'started_at' => now()]);
    $planner = app(App\\Services\\EmployeeQueuePlanner::class);
    foreach ([['QA-TEST-A', 12], ['QA-TEST-B', 6], ['QA-TEST-C', 6]] as [$title, $hours]) {
      App\\Models\\WorkItem::create(['agency_id' => $owner->agency_id, 'client_id' => $owner->id, 'assignment_id' => $assignment->id, 'employee_id' => $emp->id, 'title' => $title, 'description' => 'Queue e2e.', 'estimated_hours' => $hours, 'start_date' => now()->toDateString(), 'due_date' => now()->addDays(21)->toDateString(), 'status' => 'scheduled', 'queue_position' => $planner->nextPosition($emp)]);
      $planner->replan($emp);
    }
  `)

  await test.step('owner sees the card with now, next and free from', async () => {
    await signInExpectingSuccess(page, owner.email, owner.password)
    await page.getByRole('link', { name: 'Team queue' }).click()
    await expect(page.getByRole('heading', { name: 'Team queue', level: 1 })).toBeVisible()
    const card = page.locator('.queue-card', { hasText: 'Max Multi' })
    await expect(card.getByText('Now working on')).toBeVisible()
    await expect(card.getByText(/Free from/)).toBeVisible()
    const items = card.locator('.queue-item')
    await expect(items).toHaveCount(3)
    await expect(items.first()).toContainText('QA-TEST-A')
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/queue-desktop.png`, fullPage: true })
  })

  await test.step('reorder: move, preview, apply', async () => {
    const card = page.locator('.queue-card', { hasText: 'Max Multi' })
    await card.getByRole('button', { name: 'Reorder' }).click()
    await card.getByRole('button', { name: 'Move QA-TEST-C up' }).click()
    await card.getByRole('button', { name: 'Move QA-TEST-C up' }).click()
    await card.getByRole('button', { name: 'Preview impact' }).click()
    await expect(card.getByText('Every project still fits')).toBeVisible()
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/queue-preview.png`, fullPage: true })
    await card.getByRole('button', { name: 'Apply' }).click()
    await expect(card.locator('.queue-item').first()).toContainText('QA-TEST-C')
    await expect(card.getByRole('button', { name: 'Reorder' })).toBeVisible()
  })

  await test.step('layout holds on a phone', async () => {
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(page.locator('.queue-card').first()).toBeVisible()
    await page.waitForTimeout(800) // let the sidebar finish collapsing into its drawer
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow).toBeLessThanOrEqual(1)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/queue-mobile.png` })
    // Nothing in the card may be cut off at the left edge.
    const left = await page.locator('.queue-card').first().evaluate((node) => node.getBoundingClientRect().left)
    expect(left).toBeGreaterThanOrEqual(0)
    await page.setViewportSize({ width: 1280, height: 800 })
  })

  await test.step('owner asks for urgent help', async () => {
    await page.locator('.queue-card', { hasText: 'Max Multi' }).getByRole('button', { name: 'Request urgent help' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(/Nothing is charged/)).toBeVisible()
    await dialog.getByRole('button', { name: 'Request a quote' }).click()
    await expect(dialog.getByText('Give the request a short title.')).toBeVisible()
    await dialog.locator('#rush-title').fill('QA-TEST-Rush')
    await dialog.locator('#rush-description').fill('Checkout is broken.')
    await dialog.locator('#rush-hours').fill('3')
    await dialog.locator('#rush-needed').fill(dateIn(3))
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/rush-dialog.png` })
    await dialog.getByRole('button', { name: 'Request a quote' }).click()
    await expect(page.getByText('Waiting for a quote')).toBeVisible({ timeout: 30_000 })
    await signOut(page)
  })

  await test.step('admin quotes it', async () => {
    await signInExpectingSuccess(page, admin.email, admin.password)
    await expect(page.getByRole('link', { name: /Urgent requests, 1 waiting/ })).toBeVisible()
    await page.getByRole('link', { name: /Urgent requests/ }).click()
    const card = page.locator('.queue-rush-card', { hasText: 'QA-TEST-Rush' })
    await card.getByRole('button', { name: 'Quote' }).click()
    await page.locator('#quote-amount').fill('250.00')
    await page.getByRole('dialog').getByRole('button', { name: 'Send quote' }).click()
    await expect(page.getByText('Quote sent to the agency owner.')).toBeVisible()
    await expect(page.locator('.queue-rush-card', { hasText: 'QA-TEST-Rush' }).getByText('250.00 USD')).toBeVisible()
    await signOut(page)
  })

  await test.step('owner accepts the quote', async () => {
    await signInExpectingSuccess(page, owner.email, owner.password)
    await page.goto('/agency/queue?tab=rush')
    const card = page.locator('.queue-rush-card', { hasText: 'QA-TEST-Rush' })
    await expect(card.getByText('250.00 USD').first()).toBeVisible({ timeout: 30_000 })
    await expect(card.getByText(/No other project would pass its due date/)).toBeVisible({ timeout: 30_000 })
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/quote-panel.png`, fullPage: true })
    await card.getByRole('button', { name: 'Accept quote' }).click()
    await expect(card.getByText('Scheduled', { exact: true })).toBeVisible()
    await page.getByRole('tab', { name: /Team queue/ }).click()
    await expect(page.locator('.queue-card', { hasText: 'Max Multi' }).locator('.queue-item').first()).toContainText('QA-TEST-Rush')
    await signOut(page)
  })

  await test.step('admin sees the charge', async () => {
    await signInExpectingSuccess(page, admin.email, admin.password)
    await page.goto('/admin/rush-requests')
    await page.getByRole('tab', { name: 'Charges' }).click()
    await expect(page.getByText('250.00 USD').first()).toBeVisible()
    await expect(page.locator('table').getByText('unbilled')).toBeVisible()
    await signOut(page)
  })

  await test.step('employee sees a read-only queue without prices', async () => {
    await signInExpectingSuccess(page, employee.email, employee.password)
    await page.goto('/my-work')
    const section = page.locator('.my-queue')
    await expect(section.getByRole('heading', { name: 'My queue' })).toBeVisible({ timeout: 45_000 })
    await expect(section.getByText('QA-TEST-Rush').first()).toBeVisible()
    await expect(section.getByRole('button')).toHaveCount(0)
    expect(await section.innerText()).not.toMatch(/USD|\$|quote/i)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/my-queue.png`, fullPage: true })
  })
})
