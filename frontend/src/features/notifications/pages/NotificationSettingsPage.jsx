import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Card, PageHeader, Skeleton, useToast } from '../../../components/ui'

// One row per notification type with in-app and email switches. Changes save immediately. Rows marked
// `locked` always notify in the app (they are the things that need the user), so that switch is disabled.
export function NotificationSettingsPage() {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getNotificationPreferences(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const [saving, setSaving] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const rows = data?.preferences ?? []

  async function change(row, field, value) {
    setSaving(`${row.type}:${field}`)
    setSaveError(null)
    try {
      await authService.updateNotificationPreferences([{ type: row.type, [field]: value }])
      await reload()
      showToast('Notification settings saved.')
    } catch (reason) {
      setSaveError(reason)
    } finally {
      setSaving(null)
    }
  }

  return (
    <section className="page notification-settings">
      <PageHeader eyebrow="Account" title="Notification settings" subtitle="Choose how you want to hear about your tickets." />
      {(error || saveError) && <Alert variant="error">{(saveError ?? error).message}</Alert>}
      <Card>
        {isLoading && !data
          ? <div role="status" aria-label="Loading settings"><Skeleton variant="row" /><Skeleton variant="row" /></div>
          : (
            <table className="notification-settings-table">
              <thead>
                <tr><th scope="col">Notify me about</th><th scope="col">In the app</th><th scope="col">By email</th></tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.type}>
                    <th scope="row">
                      <strong>{row.label}</strong>
                      <span>{row.description}</span>
                      {row.locked && <span className="notification-locked-note">Always shown in the app because it needs your action.</span>}
                    </th>
                    <td>
                      <label className="ui-switch">
                        <input
                          type="checkbox"
                          checked={row.in_app}
                          disabled={row.locked || saving === `${row.type}:in_app`}
                          onChange={(event) => change(row, 'in_app', event.target.checked)}
                        />
                        <span className="sr-only">{row.label} in the app{row.locked ? ' (always on)' : ''}</span>
                      </label>
                    </td>
                    <td>
                      <label className="ui-switch">
                        <input
                          type="checkbox"
                          checked={row.email}
                          disabled={saving === `${row.type}:email`}
                          onChange={(event) => change(row, 'email', event.target.checked)}
                        />
                        <span className="sr-only">{row.label} by email</span>
                      </label>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        <p className="notification-settings-note">Repeated updates on the same ticket within ten minutes are grouped into one notification, and only the first one sends an email.</p>
        <Button type="button" variant="ghost" size="sm" onClick={reload}>Refresh</Button>
      </Card>
    </section>
  )
}
