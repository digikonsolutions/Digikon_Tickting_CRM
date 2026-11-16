import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAuth } from '../../../auth/useAuth'
import { Alert, Button, Card, Skeleton } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'

// Blocks the whole app for an employee until they accept the current monitoring notice. The text and
// version come from the server (the same text as the invite email); acceptance is stored server-side.
export function MonitoringConsent() {
  const { updateUser, signOut } = useAuth()
  const load = useCallback(() => authService.getMonitoringPolicy(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState(null)
  const policy = data?.policy

  async function accept() {
    setSaving(true)
    setSaveError(null)
    try {
      await authService.acceptMonitoringPolicy(policy.version)
      updateUser({ monitoring_consent_required: false })
    } catch (reason) {
      setSaveError(reason)
      setSaving(false)
    }
  }

  return (
    <main className="monitoring-consent" aria-labelledby="monitoring-consent-title">
      <Card>
        {isLoading && !policy && <Skeleton variant="card" />}
        {error && (
          <Alert variant="error" title="Could not load the notice">
            {error.message}
            <div className="ui-comments-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
          </Alert>
        )}
        {policy && (
          <div className="monitoring-consent-body">
            <h1 id="monitoring-consent-title">{policy.title}</h1>
            <p>{policy.summary}</p>
            <h2>What is recorded</h2>
            <ul>
              {policy.categories.map((category) => (
                <li key={category.key}><strong>{category.label}.</strong> {category.description}</li>
              ))}
            </ul>
            <h2>What is never recorded</h2>
            <ul>{policy.not_collected.map((item) => <li key={item}>{item}</li>)}</ul>
            <h2>Who can see it</h2>
            <p>{policy.viewers}</p>
            <p>{policy.retention}</p>
            <p className="monitoring-consent-version">Notice version {policy.version}</p>
            {saveError && <Alert variant="error">{saveError.message}</Alert>}
            <div className="monitoring-consent-actions">
              <Button type="button" loading={saving} loadingText="Saving…" onClick={accept}>I understand and accept</Button>
              <Button type="button" variant="secondary" disabled={saving} onClick={() => signOut()}>Sign out</Button>
            </div>
          </div>
        )}
      </Card>
    </main>
  )
}
