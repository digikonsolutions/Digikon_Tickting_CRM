import { expect, test } from '@playwright/test'
import {
  confirmPasswordField, currentPasswordField, expectShellName, loadUsers, nameField, newPasswordField,
  signIn, signInExpectingSuccess, signOut,
} from './helpers.js'
import { MULTI_USER, ROLE_USERS } from './support/users.mjs'

const NEW_PASSWORD = 'Rotated-E2E-Pass#900'
const WEAK_PASSWORD = 'weakpass'

// One real user per role, one browser session each (login counts are limited to five a minute per user,
// so each role uses four sign-ins at most).
for (const roleUser of ROLE_USERS) {
  test(`profile and password: ${roleUser.role}`, async ({ page }) => {
    const user = loadUsers().find((candidate) => candidate.email === roleUser.email)
    const renamed = `${roleUser.label} Renamed`

    await test.step('sign in and reach /profile from the sidebar', async () => {
      await signInExpectingSuccess(page, user.email, user.password)
      await page.locator('.sidebar-user-profile').click()
      await expect(page).toHaveURL(/\/profile$/)
      await expect(page.locator('main').getByRole('heading', { name: 'My profile' })).toBeVisible()
      await expect(page.getByLabel('Email address')).toHaveValue(user.email)
      await expect(page.getByLabel('Email address')).toBeDisabled()
    })

    await test.step('name validation errors appear next to the field', async () => {
      const save = page.getByRole('button', { name: 'Save profile' })
      await expect(save).toBeDisabled()

      await nameField(page).fill('')
      await save.click()
      await expect(page.getByText('Enter your name.').first()).toBeVisible()

      await nameField(page).fill('x'.repeat(121))
      await save.click()
      await expect(page.getByText('Your name may not be longer than 120 characters.')).toBeVisible()
      // Nothing was saved by the failed attempts.
      await expect(page.locator('.sidebar-user-details > strong')).toHaveText(user.name)
    })

    await test.step('change the name: success message and every shell surface update at once', async () => {
      await nameField(page).fill(`  ${roleUser.label}    Renamed  `)
      await page.getByRole('button', { name: 'Save profile' }).click()
      await expect(page.getByText('Your profile was updated.')).toBeVisible()
      await expectShellName(page, renamed)
      await expect(nameField(page)).toHaveValue(renamed) // whitespace was collapsed
    })

    await test.step('it persists across a reload', async () => {
      await page.reload()
      await expect(page.locator('.sidebar-user-details')).toBeVisible()
      await expectShellName(page, renamed)
    })

    await test.step('it persists across sign out and sign in', async () => {
      await signOut(page)
      await signInExpectingSuccess(page, user.email, user.password)
      await expectShellName(page, renamed)
      await page.goto('/profile')
      await expect(nameField(page)).toHaveValue(renamed)
    })

    await test.step('password errors appear next to their fields', async () => {
      const change = page.getByRole('button', { name: 'Change password' })
      await expect(change).toBeDisabled()

      await currentPasswordField(page).fill('Definitely-Wrong-1!')
      await newPasswordField(page).fill(NEW_PASSWORD)
      await confirmPasswordField(page).fill(NEW_PASSWORD)
      await change.click()
      await expect(page.getByText('The current password is incorrect.')).toBeVisible()

      await currentPasswordField(page).fill(user.password)
      await newPasswordField(page).fill(WEAK_PASSWORD)
      await confirmPasswordField(page).fill(WEAK_PASSWORD)
      await change.click()
      await expect(page.locator('#new-password-error, [id$="new-password-error"]')).toBeVisible()
      await expect(page.locator('.ui-field-error').filter({ hasText: /password/i }).first()).toBeVisible()

      await newPasswordField(page).fill(NEW_PASSWORD)
      await confirmPasswordField(page).fill(`${NEW_PASSWORD}-different`)
      await expect(page.getByText('The new password and its confirmation do not match.').first()).toBeVisible()
      await expect(change).toBeDisabled()
    })

    await test.step('change the password: success, fields cleared, still signed in', async () => {
      await currentPasswordField(page).fill(user.password)
      await newPasswordField(page).fill(NEW_PASSWORD)
      await confirmPasswordField(page).fill(NEW_PASSWORD)
      await page.getByRole('button', { name: 'Change password' }).click()
      await expect(page.getByText('Your password was changed. Other devices have been signed out.')).toBeVisible()
      await expect(currentPasswordField(page)).toHaveValue('')
      await expect(newPasswordField(page)).toHaveValue('')
      await expect(confirmPasswordField(page)).toHaveValue('')
      // Still signed in on this device: a reload keeps the session.
      await page.reload()
      await expect(page.locator('.sidebar-user-details')).toBeVisible()
      await expect(page).toHaveURL(/\/profile$/)
    })

    await test.step('after signing out the old password fails and the new one works', async () => {
      await signOut(page)
      await signIn(page, user.email, user.password)
      await expect(page.getByText('The provided credentials are incorrect.')).toBeVisible()
      await expect(page).toHaveURL(/\/login/)
      await signInExpectingSuccess(page, user.email, NEW_PASSWORD)
      await expectShellName(page, renamed)
    })
  })
}

test('changing the password in one browser signs the same user out in another', async ({ browser }) => {
  const user = loadUsers().find((candidate) => candidate.email === MULTI_USER.email)
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  const pageA = await contextA.newPage()
  const pageB = await contextB.newPage()

  await signInExpectingSuccess(pageA, user.email, user.password)
  await signInExpectingSuccess(pageB, user.email, user.password)
  await pageB.goto('/profile')
  await expect(pageB.locator('main').getByRole('heading', { name: 'My profile' })).toBeVisible()

  await pageA.goto('/profile')
  await currentPasswordField(pageA).fill(user.password)
  await newPasswordField(pageA).fill(NEW_PASSWORD)
  await confirmPasswordField(pageA).fill(NEW_PASSWORD)
  await pageA.getByRole('button', { name: 'Change password' }).click()
  await expect(pageA.getByText('Your password was changed. Other devices have been signed out.')).toBeVisible()
  // The device that changed it stays signed in.
  await pageA.reload()
  await expect(pageA.locator('.sidebar-user-details')).toBeVisible()

  // The other device's session no longer exists: its next request sends it to the sign-in page.
  await pageB.reload()
  await expect(pageB).toHaveURL(/\/login/)
  await expect(pageB.locator('.sidebar-user-details')).toHaveCount(0)

  await contextA.close()
  await contextB.close()
})
