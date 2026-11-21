import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Avatar, Badge, Button, Card, FormActions, Modal, PageHeader, Skeleton, Tabs, useToast } from '../../../components/ui'
import { AccountPanel, EmploymentPanel, PersonalPanel, ProfessionalPanel } from '../employee/EmployeeFormPanels'
import { SavedDocuments } from '../employee/DocumentsPanel'
import {
  AVAILABILITY, AVAILABILITY_VARIANT, DEPARTMENTS, EMPLOYMENT_STATUSES, STATUS_VARIANT,
  buildPayload, formFromEmployee, labelOf, tabsWithErrors,
} from '../employee/employeeForm'
import '../employee/employee.css'

const TAB_IDS = ['account', 'personal', 'professional', 'employment', 'documents']
const TAB_LABELS = { account: 'Account', personal: 'Personal', professional: 'Professional', employment: 'Employment', documents: 'Documents' }
const REVEAL_SECONDS = 30

export function AdminEmployeeProfilePage() {
  const { id } = useParams()
  const load = useCallback(() => authService.getAdminEmployee(id), [id])
  const { data, error, isLoading, reload } = useAsync(load)

  if (isLoading && !data) {
    return (
      <section className="page emp-page">
        <PageHeader eyebrow="Administrator" title="Employee profile" />
        <Skeleton variant="card" />
        <Skeleton variant="card" />
      </section>
    )
  }
  if (error || !data?.employee) {
    return (
      <section className="page emp-page">
        <PageHeader eyebrow="Administrator" title="Employee profile" action={<Link className="ui-button ui-button-secondary ui-button-sm" to="/admin/employees">Back to employees</Link>} />
        <Alert variant="error" title="Could not load this employee">
          {error?.status === 404 ? 'No employee with that id.' : (error?.message ?? 'Something went wrong.')}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      </section>
    )
  }
  // Keyed by id: opening a different employee starts from that employee's saved values.
  return <ProfileEditor key={data.employee.id} initial={data.employee} />
}

function ProfileEditor({ initial }) {
  const { showToast } = useToast()
  const location = useLocation()
  const failedUploads = location.state?.failedUploads ?? []
  const [employee, setEmployee] = useState(initial)
  const [values, setValues] = useState(() => formFromEmployee(initial))
  const [tab, setTab] = useState('account')
  const [errors, setErrors] = useState({})
  const [apiError, setApiError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [photoBusy, setPhotoBusy] = useState(false)
  const [reveal, setReveal] = useState(null)

  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const profile = employee.profile
  const completeness = employee.completeness
  const errorTabs = tabsWithErrors(errors)
  const errorCount = (tabId) => Object.keys(errors).filter((key) => tabsWithErrors({ [key]: errors[key] })[0] === tabId).length

  async function save(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setErrors({})
    setApiError(null)
    try {
      const result = await authService.updateAdminEmployee(employee.id, { name: values.name.trim(), ...buildPayload(values, 'update') })
      setEmployee(result.employee)
      setValues(formFromEmployee(result.employee))
      showToast('Employee profile saved.')
    } catch (reason) {
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

  async function changePhoto(file) {
    setPhotoBusy(true)
    try {
      const result = await authService.uploadEmployeePhoto(employee.id, file)
      setEmployee((current) => ({ ...current, photo_url: result.employee.photo_url, completeness: result.employee.completeness }))
      showToast('Photo updated.')
    } catch (reason) {
      showToast(reason.errors?.photo?.[0] ?? reason.message, 'error')
    } finally {
      setPhotoBusy(false)
    }
  }

  async function removePhoto() {
    setPhotoBusy(true)
    try {
      const result = await authService.deleteEmployeePhoto(employee.id)
      setEmployee((current) => ({ ...current, photo_url: null, completeness: result.employee.completeness }))
      showToast('Photo removed.')
    } catch (reason) {
      showToast(reason.message, 'error')
    } finally {
      setPhotoBusy(false)
    }
  }

  // The full CNIC is only fetched after an explicit confirm, shown briefly, then dropped from memory.
  async function revealCnic() {
    setReveal({ stage: 'loading' })
    try {
      const result = await authService.revealEmployeeCnic(employee.id)
      setReveal({ stage: 'shown', cnic: result.cnic, secondsLeft: REVEAL_SECONDS })
    } catch (reason) {
      setReveal({ stage: 'error', message: reason.message })
    }
  }
  const revealStage = reveal?.stage
  useEffect(() => {
    if (revealStage !== 'shown') return undefined
    const timer = setInterval(() => {
      setReveal((current) => {
        if (current?.stage !== 'shown') return current
        return current.secondsLeft <= 1 ? null : { ...current, secondsLeft: current.secondsLeft - 1 }
      })
    }, 1000)
    return () => clearInterval(timer)
  }, [revealStage])

  const panelProps = { values, set, errors, mode: 'edit', employee }

  return (
    <section className="page emp-page">
      <PageHeader
        eyebrow="Administrator"
        title={employee.name}
        subtitle={`${profile.employee_code ?? 'No code yet'} · ${employee.email}`}
        action={(
          <div className="project-header-actions">
            <Link className="ui-button ui-button-secondary ui-button-sm" to={`/admin/employees/${employee.id}/activity`}>Work activity</Link>
            <Link className="ui-button ui-button-secondary ui-button-sm" to="/admin/employees">Back to employees</Link>
          </div>
        )}
      />

      {failedUploads.length > 0 && (
        <Alert variant="warning" title="Account created, but some documents did not upload">
          {failedUploads.join(' ')} Add them again on the Documents tab.
        </Alert>
      )}

      <Card>
        <div className="emp-summary">
          <Avatar name={employee.name} src={employee.photo_url} size="xl" />
          <div>
            <h2>{profile.headline || 'No headline yet'}</h2>
            <p>{[profile.city, profile.timezone].filter(Boolean).join(' · ') || 'No location yet'}</p>
            <div className="emp-summary-badges">
              {profile.department && <Badge variant="info">{labelOf(DEPARTMENTS, profile.department)}</Badge>}
              <Badge variant={STATUS_VARIANT[profile.employment_status] ?? 'slate'}>{labelOf(EMPLOYMENT_STATUSES, profile.employment_status)}</Badge>
              <Badge variant={AVAILABILITY_VARIANT[profile.availability] ?? 'slate'}>{labelOf(AVAILABILITY, profile.availability)}</Badge>
              {!employee.is_active && <Badge variant="danger">Deactivated</Badge>}
            </div>
          </div>
          <div className="emp-complete">
            <strong><span>Profile completeness</span><span>{completeness.percent}%</span></strong>
            <progress value={completeness.percent} max="100" aria-label={`Profile ${completeness.percent}% complete`} />
            {completeness.missing.length > 0 && (
              <details>
                <summary>{completeness.missing.length} item{completeness.missing.length === 1 ? '' : 's'} missing</summary>
                <ul>{completeness.missing.map((item) => <li key={item}>{item}</li>)}</ul>
              </details>
            )}
          </div>
        </div>
      </Card>

      <Card className="ui-form-card">
        <form className="ui-form" onSubmit={save}>
          <Tabs
            tabs={TAB_IDS.map((tabId) => ({ id: tabId, label: TAB_LABELS[tabId], count: tabId === 'documents' ? employee.documents.length : (errorCount(tabId) || undefined) }))}
            activeTab={tab}
            onChange={setTab}
            label="Employee profile sections"
          />
          {errorTabs.length > 0 && (
            <Alert variant="error" title="Some details need attention">
              Check the highlighted fields on: {errorTabs.map((tabId) => TAB_LABELS[tabId]).join(', ')}.
            </Alert>
          )}
          <div className="emp-tabpanel" role="tabpanel" aria-label={TAB_LABELS[tab]}>
            {tab === 'account' && (
              <AccountPanel {...panelProps} photoUrl={employee.photo_url} photoBusy={photoBusy} onPhotoUpload={changePhoto} onPhotoRemove={removePhoto} />
            )}
            {tab === 'personal' && <PersonalPanel {...panelProps} onReveal={() => setReveal({ stage: 'confirm' })} />}
            {tab === 'professional' && <ProfessionalPanel {...panelProps} />}
            {tab === 'employment' && <EmploymentPanel {...panelProps} />}
            {tab === 'documents' && (
              <SavedDocuments
                employeeId={employee.id}
                documents={employee.documents}
                onChange={(documents) => setEmployee((current) => ({ ...current, documents }))}
                onCompleteness={(next) => setEmployee((current) => ({ ...current, completeness: next }))}
              />
            )}
          </div>
          <ApiErrorMessage error={apiError} />
          {tab === 'documents'
            ? <p className="ui-field-help">Documents save automatically. The other tabs save with the button below.</p>
            : <FormActions submitLabel="Save changes" loadingLabel="Saving…" loading={saving} />}
        </form>
      </Card>

      <Modal
        open={Boolean(reveal)}
        onClose={() => reveal?.stage !== 'loading' && setReveal(null)}
        title={reveal?.stage === 'shown' ? 'CNIC' : 'Reveal CNIC?'}
        footer={(
          <>
            {reveal?.stage === 'confirm' && (
              <>
                <Button type="button" variant="secondary" onClick={() => setReveal(null)}>Cancel</Button>
                <Button type="button" onClick={revealCnic}>Reveal CNIC</Button>
              </>
            )}
            {reveal?.stage !== 'confirm' && <Button type="button" variant="secondary" disabled={reveal?.stage === 'loading'} onClick={() => setReveal(null)}>Close</Button>}
          </>
        )}
      >
        {reveal?.stage === 'confirm' && <p>The full CNIC of <strong>{employee.name}</strong> will be shown on screen. This is recorded in the audit log with your name and the time.</p>}
        {reveal?.stage === 'loading' && <p role="status">Fetching…</p>}
        {reveal?.stage === 'error' && <Alert variant="error">{reveal.message}</Alert>}
        {reveal?.stage === 'shown' && (
          <>
            <output className="emp-revealed" aria-live="polite">{reveal.cnic}</output>
            <p className="ui-field-help">Hidden again in {reveal.secondsLeft} seconds. This view was recorded in the audit log.</p>
          </>
        )}
      </Modal>
    </section>
  )
}
