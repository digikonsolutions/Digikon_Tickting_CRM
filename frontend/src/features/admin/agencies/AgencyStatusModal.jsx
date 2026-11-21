import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Modal, useToast } from '../../../components/ui'

// Suspend or activate an agency, with a confirm step that says what suspending does.
// `agency` is { id, name, status } or null; `onDone` runs after a successful change (reload the data there).
export function AgencyStatusModal({ agency, onClose, onDone }) {
  const { showToast } = useToast()
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const suspending = agency?.status === 'active'

  async function confirm() {
    if (!agency || saving) return
    setSaving(true)
    setError(null)
    try {
      await authService.updateAdminAgency(agency.id, suspending ? 'suspended' : 'active')
      showToast(`${agency.name} was ${suspending ? 'suspended' : 'activated'}.`)
      onDone?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={Boolean(agency)}
      onClose={() => !saving && onClose()}
      title={suspending ? 'Suspend agency?' : 'Activate agency?'}
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Cancel</Button>
          <Button type="button" variant={suspending ? 'danger' : 'primary'} loading={saving} loadingText="Saving…" onClick={confirm}>
            {suspending ? 'Suspend agency' : 'Activate agency'}
          </Button>
        </>
      )}
    >
      {agency && (suspending
        ? <p><strong>{agency.name}</strong> will be suspended. All of its users (the owner and every client) are <strong>signed out immediately</strong> and cannot sign in until you activate the agency again. Their projects and held employees are kept.</p>
        : <p>Users of <strong>{agency.name}</strong> will be able to sign in again.</p>)}
      <ApiErrorMessage error={error} />
    </Modal>
  )
}
