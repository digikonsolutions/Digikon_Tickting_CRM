import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Button, Card, FormActions, Modal, PageHeader, Tabs, useToast } from '../../../components/ui'
import { AccountPanel, EmploymentPanel, PersonalPanel, ProfessionalPanel } from '../employee/EmployeeFormPanels'
import { QueuedDocuments } from '../employee/DocumentsPanel'
import { buildPayload, emptyForm, tabsWithErrors } from '../employee/employeeForm'
import '../employee/employee.css'

const TAB_IDS = ['account', 'personal', 'professional', 'employment', 'documents']
const TAB_LABELS = { account: 'Account', personal: 'Personal', professional: 'Professional', employment: 'Employment', documents: 'Documents' }

// Create an employee. Only the Account tab is required: "Create employee" is available from every tab, so the
// admin can skip steps now and finish the profile later (the next screen shows what is still missing).
export function AdminCreateEmployeePage() {
  const navigate = useNavigate()
  const { showToast } = useToast()
  const [values, setValues] = useState(emptyForm)
  const [queue, setQueue] = useState([])
  const [tab, setTab] = useState('account')
  const [errors, setErrors] = useState({})
  const [apiError, setApiError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [uploading, setUploading] = useState(null)

  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const canCreate = values.name.trim() !== '' && values.email.trim() !== ''
  const errorTabs = tabsWithErrors(errors)
  const errorCount = (id) => Object.keys(errors).filter((key) => tabsWithErrors({ [key]: errors[key] })[0] === id).length
  const index = TAB_IDS.indexOf(tab)

  async function submit(event) {
    event.preventDefault()
    if (!canCreate || saving) return
    setSaving(true)
    setErrors({})
    setApiError(null)
    try {
      const { employee } = await authService.createEmployee({
        name: values.name.trim(),
        email: values.email.trim(),
        ...buildPayload(values, 'create'),
      })

      const failed = []
      for (let i = 0; i < queue.length; i += 1) {
        const item = queue[i]
        setUploading({ position: i + 1, total: queue.length, name: item.file.name, percent: 0 })
        try {
          await authService.uploadEmployeeDocument(employee.id, {
            file: item.file,
            type: item.type,
            expiresAt: item.expires_at,
            onProgress: (percent) => setUploading((current) => current && { ...current, percent }),
          })
        } catch (reason) {
          failed.push(`${item.file.name}: ${reason.errors?.file?.[0] ?? reason.message}`)
        }
      }
      setUploading(null)
      showToast(`Invite email sent to ${values.email.trim()}`)
      navigate(`/admin/employees/${employee.id}`, { replace: true, state: { failedUploads: failed } })
    } catch (reason) {
      setUploading(null)
      if (reason.status === 422 && Object.keys(reason.errors ?? {}).length) {
        setErrors(reason.errors)
        const first = tabsWithErrors(reason.errors)[0]
        if (first) setTab(first)
      } else {
        setApiError(reason)
      }
    } finally {
      setSaving(false)
    }
  }

  const panelProps = { values, set, errors, mode: 'create' }

  return (
    <section className="page emp-page">
      <PageHeader
        eyebrow="Administrator"
        title="Create employee"
        subtitle="Only the name and email are required. Add the rest now, or finish the profile later."
      />
      <Card className="ui-form-card">
        <form className="ui-form" onSubmit={submit} noValidate={false}>
          <Tabs
            tabs={TAB_IDS.map((id) => ({ id, label: TAB_LABELS[id], count: errorCount(id) || undefined }))}
            activeTab={tab}
            onChange={setTab}
            label="Employee profile sections"
          />
          {errorTabs.length > 0 && (
            <Alert variant="error" title="Some details need attention">
              Check the highlighted fields on: {errorTabs.map((id) => TAB_LABELS[id]).join(', ')}.
            </Alert>
          )}
          <div className="emp-tabpanel" role="tabpanel" aria-label={TAB_LABELS[tab]}>
            {tab === 'account' && <AccountPanel {...panelProps} />}
            {tab === 'personal' && <PersonalPanel {...panelProps} />}
            {tab === 'professional' && <ProfessionalPanel {...panelProps} />}
            {tab === 'employment' && <EmploymentPanel {...panelProps} />}
            {tab === 'documents' && <QueuedDocuments queue={queue} onChange={setQueue} />}
          </div>
          <ApiErrorMessage error={apiError} />
          <FormActions
            submitLabel="Create employee"
            loadingLabel="Creating…"
            loading={saving}
            disabled={!canCreate}
            disabledReason="Enter a name and email on the Account tab to create the employee."
          >
            <Button type="button" variant="ghost" disabled={saving || index === 0} onClick={() => setTab(TAB_IDS[index - 1])}>Back</Button>
            <Button type="button" variant="secondary" disabled={saving || index === TAB_IDS.length - 1} onClick={() => setTab(TAB_IDS[index + 1])}>Next</Button>
          </FormActions>
        </form>
      </Card>

      <Modal open={Boolean(uploading)} onClose={() => {}} title="Uploading documents">
        {uploading && (
          <div role="status" aria-live="polite">
            <p>The account was created. Uploading file {uploading.position} of {uploading.total}: <strong>{uploading.name}</strong></p>
            <progress value={uploading.percent} max="100" aria-label="Upload progress" style={{ width: '100%' }} />
          </div>
        )}
      </Modal>
    </section>
  )
}
