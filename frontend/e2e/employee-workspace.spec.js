import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loadUsers, signInExpectingSuccess } from './helpers.js'
import { backendDir } from './support/prepare.mjs'

// The employee workspace on real servers: an overdue ticket, a blocked one and one planned today, at desktop width
// and at 360px. Screenshots go to E2E_SHOTS when it is set.
const SHOTS = process.env.E2E_SHOTS

function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { cwd: backendDir, env: { ...process.env, APP_ENV: 'e2e' }, encoding: 'utf8' })
}

test('employee workspace: overview, tickets, week, projects; keyboard and 360px', async ({ page }) => {
  test.setTimeout(180_000)
  const employee = loadUsers().find((user) => user.email === 'employee@e2e.test')

  tinker(`
    $owner = App\\Models\\User::where('email', 'owner@e2e.test')->first();
    $emp = App\\Models\\User::where('email', 'employee@e2e.test')->first();
    $assignment = App\\Models\\EmployeeAssignment::create(['holder_id' => $owner->id, 'agency_id' => $owner->agency_id, 'employee_id' => $emp->id, 'status' => 'active', 'source' => 'owner', 'active_employee_id' => $emp->id, 'started_at' => now()]);
    $today = Carbon\\CarbonImmutable::now(config('app.timezone'))->startOfDay();
    $item = App\\Models\\WorkItem::create(['agency_id' => $owner->agency_id, 'client_id' => $owner->id, 'assignment_id' => $assignment->id, 'employee_id' => $emp->id, 'title' => 'QA-TEST-Website', 'description' => 'Employee workspace e2e.', 'estimated_hours' => 30, 'start_date' => $today->subDays(5)->toDateString(), 'due_date' => $today->addDays(5)->toDateString(), 'status' => 'in_progress', 'timeline_status' => 'confirmed']);
    $rows = [['QA-TEST-Overdue footer', 'todo', $today->subDays(2), $today->subDays(1)], ['QA-TEST-Blocked header', 'blocked', $today, $today->addDays(3)], ['QA-TEST-Today hero', 'todo', $today, $today->addDays(2)]];
    foreach ($rows as $i => [$title, $status, $start, $due]) {
      $ticket = App\\Models\\Ticket::create(['agency_id' => $owner->agency_id, 'work_item_id' => $item->id, 'created_by' => $owner->id, 'number' => 100 + $i, 'title' => $title, 'description' => 'x', 'estimated_hours' => 2, 'priority' => $i === 1 ? 'urgent' : 'normal', 'status' => $status, 'position' => $i + 1, 'start_date' => $start->toDateString(), 'due_date' => $due->toDateString(), 'timeline_status' => 'confirmed']);
      App\\Models\\TicketAllocation::create(['ticket_id' => $ticket->id, 'work_date' => $start->toDateString(), 'hours' => 2]);
    }
  `)

  await signInExpectingSuccess(page, employee.email, employee.password)
  await page.goto('/my-work')

  await test.step('overview shows each ticket once in the right section', async () => {
    await expect(page.getByRole('heading', { name: 'My work', level: 1 })).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('region', { name: 'Needs your attention' }).getByText('QA-TEST-Blocked header')).toBeVisible()
    await expect(page.getByRole('region', { name: 'Overdue or carried over' }).getByText('QA-TEST-Overdue footer')).toBeVisible()
    await expect(page.getByRole('region', { name: 'Today' }).getByText('QA-TEST-Today hero')).toBeVisible()
    await expect(page.getByText('QA-TEST-Overdue footer')).toHaveCount(1)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/ew-overview.png`, fullPage: true })
  })

  await test.step('keyboard only: open a ticket, close it, focus comes back', async () => {
    const open = page.getByRole('button', { name: /Open .*QA-TEST-Today hero/ })
    await open.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('dialog')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(open).toBeFocused()
  })

  await test.step('tickets page filters are kept in the URL', async () => {
    await page.getByRole('link', { name: 'Tickets', exact: true }).click()
    await page.getByLabel('Show', { exact: true }).selectOption('blocked')
    await expect(page).toHaveURL(/flag=blocked/)
    await expect(page.getByText('QA-TEST-Blocked header')).toBeVisible()
    await expect(page.getByText('QA-TEST-Today hero')).toHaveCount(0)
    await page.reload()
    await expect(page.getByText('QA-TEST-Blocked header')).toBeVisible({ timeout: 30_000 })
  })

  await test.step('week planner moves between weeks', async () => {
    await page.getByRole('link', { name: 'Week', exact: true }).click()
    const label = page.locator('.ew-week-label')
    const before = await label.innerText()
    await page.getByRole('button', { name: 'Next week' }).click()
    await expect(label).not.toHaveText(before)
    await page.getByRole('button', { name: 'This week' }).click()
    await expect(label).toHaveText(before)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/ew-week.png`, fullPage: true })
  })

  await test.step('projects list and detail', async () => {
    await page.getByRole('link', { name: 'Projects', exact: true }).click()
    await page.getByRole('link', { name: 'QA-TEST-Website' }).click()
    await expect(page.getByRole('heading', { name: 'QA-TEST-Website', level: 1 })).toBeVisible()
    await expect(page.getByRole('list', { name: 'Tickets in this project' }).locator('h3')).toHaveCount(3)
  })

  await test.step('360px: nothing overflows sideways', async () => {
    await page.setViewportSize({ width: 360, height: 780 })
    for (const path of ['/my-work', '/my-work/tickets', '/my-work/week', '/my-work/projects']) {
      await page.goto(path)
      await expect(page.locator('.ew-page h1')).toBeVisible({ timeout: 30_000 })
      await page.waitForTimeout(500)
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
      expect(overflow, `${path} overflows by ${overflow}px`).toBeLessThanOrEqual(1)
    }
    await page.goto('/my-work')
    await expect(page.locator('.ew-page h1')).toBeVisible({ timeout: 30_000 })
    await page.waitForTimeout(500)
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/ew-overview-360.png`, fullPage: true })
  })
})
