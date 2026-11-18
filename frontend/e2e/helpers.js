import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))

// Users created by global-setup (read lazily: the file only exists once setup has run).
export function loadUsers() {
  return JSON.parse(readFileSync(resolve(here, '.users.json'), 'utf8'))
}

export async function signIn(page, email, password) {
  await page.goto('/login')
  await page.locator('input[type=email]').fill(email)
  await page.locator('input[type=password]').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

export async function signInExpectingSuccess(page, email, password) {
  await signIn(page, email, password)
  // Employees must accept the work activity notice before the app opens (once per policy version).
  const shell = page.locator('.sidebar-user-details')
  const notice = page.getByRole('button', { name: 'I understand and accept' })
  await expect(shell.or(notice)).toBeVisible()
  if (await notice.isVisible()) await notice.click()
  await expect(shell).toBeVisible()
}

export async function signOut(page) {
  await page.locator('.sidebar-logout-button').click()
  await expect(page).toHaveURL(/\/login/)
}

// Every identity surface in the shell: sidebar name, sidebar avatar, header avatar and the account menu.
export async function expectShellName(page, name) {
  await expect(page.locator('.sidebar-user-details > strong')).toHaveText(name)
  await expect(page.locator('.sidebar-user-avatar')).toHaveText(name.charAt(0).toUpperCase())
  await expect(page.locator('.header-menu-avatar')).toHaveText(name.charAt(0).toUpperCase())
  await page.locator('details.header-user-menu > summary').click()
  await expect(page.locator('.header-user-menu-popover > strong')).toHaveText(name)
  await page.locator('details.header-user-menu > summary').click()
}

// getByRole uses the accessible name; getByLabel's exact match would include the required-field marker.
export const nameField = (page) => page.getByRole('textbox', { name: 'Name', exact: true })
export const currentPasswordField = (page) => page.getByRole('textbox', { name: 'Current password', exact: true })
export const newPasswordField = (page) => page.getByRole('textbox', { name: 'New password', exact: true })
export const confirmPasswordField = (page) => page.getByRole('textbox', { name: 'Confirm new password', exact: true })