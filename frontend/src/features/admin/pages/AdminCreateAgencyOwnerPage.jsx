import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Stepper, useToast } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { AgencyFields } from '../agency-onboarding/AgencyFields'
import { ContractFields } from '../agency-onboarding/ContractFields'
import { OwnerFields } from '../agency-onboarding/OwnerFields'
import { ReviewStep } from '../agency-onboarding/ReviewStep'
import { SetupFields } from '../agency-onboarding/SetupFields'
import {
  DOCUMENT_RULES, LOGO_RULES, STEPS, buildPayload, clearDraft, emptyWizard, formatBytes, hasContent, loadDraft, saveDraft,
  stepOfKey, stepsWithErrors, validateStep,
} from '../agency-onboarding/onboardingForm'
import '../agency-onboarding/onboarding.css'

const loadPlans = () => authService.listPlans({ activeOnly: true })
const FORM_STEPS = STEPS.slice(0, 4)
const stepIndex = (id) => STEPS.findIndex((step) => step.id === id)

function withoutStep(errors, id) {
  return Object.fromEntries(Object.entries(errors).filter(([key]) => stepOfKey(key) !== id))
}

function mergeDraft(values) {
  const base = emptyWizard()
  return {
    ...base,
    ...values,
    owner: { ...base.owner, ...values.owner },
    agency: { ...base.agency, ...values.agency },
    contract: { ...base.contract, ...values.contract },
    setup: { ...base.setup, ...values.setup },
  }
}

const checkFile = (file, { extensions, maxBytes, label }) => {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (extensions && !extensions.includes(extension)) return `${file.name}: only ${label} files are allowed.`
  if (file.size === 0) return `${file.name} is empty.`
  if (file.size > maxBytes) return `${file.name} is larger than ${formatBytes(maxBytes)}.`
  return null
}

// The 5-step "Create agency owner" wizard. Only the owner's name and email and the agency name are required, and
// the admin can save a draft (nothing is sent and the owner cannot sign in) or create and invite in one go.
export function AdminCreateAgencyOwnerPage() {
  const [values, setValues] = useState(emptyWizard)
  const [stepId, setStepId] = useState('owner')
  const [visited, setVisited] = useState(() => new Set(['owner']))
  const [errors, setErrors] = useState({})
  const [apiError, setApiError] = useState(null)
  const [submitting, setSubmitting] = useState(null)
  const [logo, setLogo] = useState(null)
  const [logoNotice, setLogoNotice] = useState(null)
  const [contractDoc, setContractDoc] = useState(null)
  const [docNotice, setDocNotice] = useState(null)
  const [known, setKnown] = useState({})
  const [result, setResult] = useState(null)
  const [resume, setResume] = useState(() => loadDraft())
  const plans = useAsync(loadPlans)

  const index = stepIndex(stepId)
  const seats = values.contractEnabled && values.contract.employees !== '' ? Number(values.contract.employees) : null
  const errorSteps = stepsWithErrors(errors)

  // Keep a recoverable copy while the admin works, until the agency is created.
  useEffect(() => {
    if (!result && hasContent(values)) saveDraft({ values, step: stepId })
  }, [values, stepId, result])

  // After a failed submit or Next, put the keyboard on the first invalid field of the step now showing.
  const [focusInvalid, setFocusInvalid] = useState(0)
  useEffect(() => {
    if (focusInvalid === 0) return
    document.querySelector('.onb-page [aria-invalid="true"]')?.focus()
  }, [focusInvalid, stepId])

  const section = (name) => (next) => setValues((current) => ({ ...current, [name]: next }))
  const onKnown = useCallback((employees) => setKnown((current) => ({ ...current, ...Object.fromEntries(employees.map((employee) => [employee.id, employee])) })), [])

  const status = (id) => {
    if (id === stepId) return 'current'
    if (errorSteps.has(id)) return 'error'
    if (visited.has(id) && id !== 'review' && Object.keys(validateStep(id, values)).length === 0 && stepIndex(id) < index) return 'done'
    return 'todo'
  }
  const stepperSteps = STEPS.map((step) => ({ ...step, status: status(step.id) }))

  function goTo(id) {
    setVisited((current) => new Set(current).add(id))
    setStepId(id)
    window.scrollTo?.({ top: 0 })
  }

  function next() {
    const found = validateStep(stepId, values)
    setErrors((current) => ({ ...withoutStep(current, stepId), ...found }))
    if (Object.keys(found).length > 0) {
      setFocusInvalid((tick) => tick + 1)
      return
    }
    goTo(STEPS[index + 1].id)
  }

  function chooseFile(file, rules, set, setNotice) {
    const problem = file ? checkFile(file, rules) : null
    setNotice(problem)
    if (!problem) set(file)
  }

  async function submit(mode) {
    if (submitting) return
    const all = FORM_STEPS.reduce((found, step) => ({ ...found, ...validateStep(step.id, values) }), {})
    if (Object.keys(all).length > 0) {
      setErrors(all)
      setFocusInvalid((tick) => tick + 1)
      goTo(Object.keys(all).map(stepOfKey).sort((a, b) => stepIndex(a) - stepIndex(b))[0])
      return
    }
    setSubmitting(mode)
    setApiError(null)
    try {
      const created = await authService.createAgencyOwner(buildPayload(values, mode))
      clearDraft()
      setErrors({})
      setResult({ ...created, mode })
    } catch (reason) {
      if (reason.status === 422 && Object.keys(reason.errors ?? {}).length > 0) {
        setErrors(reason.errors)
        setFocusInvalid((tick) => tick + 1)
        goTo(Object.keys(reason.errors).map(stepOfKey).sort((a, b) => stepIndex(a) - stepIndex(b))[0])
      } else {
        setApiError(reason)
      }
    } finally {
      setSubmitting(null)
    }
  }

  function reset() {
    setValues(emptyWizard())
    setStepId('owner')
    setVisited(new Set(['owner']))
    setErrors({})
    setLogo(null)
    setContractDoc(null)
    setResult(null)
    setResume(null)
    clearDraft()
  }

  if (result) {
    return (
      <section className="page onb-page">
        <PageHeader eyebrow="Administrator" title={result.mode === 'draft' ? 'Draft saved' : 'Agency created'} subtitle={result.agency.name} />
        <CreatedSummary result={result} logo={logo} contractDoc={contractDoc} onAnother={reset} />
      </section>
    )
  }

  const expectedSeatsError = errors['setup.employee_ids']

  return (
    <section className="page onb-page">
      <PageHeader
        eyebrow="Administrator"
        title="Create agency owner"
        subtitle="Set up the owner, the agency and its plan. Only the owner's name and email and the agency name are required; you can finish the rest later."
      />

      {resume && !hasContent(values) && (
        <Alert variant="info" title="Resume your draft?">
          You started a new agency owner earlier in this tab{resume.savedAt ? ` (${new Date(resume.savedAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })})` : ''}.
          <div className="ui-retry">
            <Button type="button" size="sm" onClick={() => { setValues(mergeDraft(resume.values)); setStepId(resume.step ?? 'owner'); setVisited(new Set(STEPS.map((step) => step.id).slice(0, stepIndex(resume.step ?? 'owner') + 1))); setResume(null) }}>Resume draft</Button>
            <Button type="button" size="sm" variant="secondary" onClick={() => { clearDraft(); setResume(null) }}>Start fresh</Button>
          </div>
        </Alert>
      )}

      <Card className="onb-card">
        <form className="ui-form" noValidate onSubmit={(event) => { event.preventDefault(); if (stepId !== 'review') next() }}>
          <Stepper steps={stepperSteps} current={stepId} label="Agency setup progress" onSelect={(id) => (stepIndex(id) <= index || visited.has(id) ? goTo(id) : undefined)} />

          {errorSteps.size > 0 && (
            <Alert variant="error" title="Some details need attention">
              Check the highlighted fields on: {STEPS.filter((step) => errorSteps.has(step.id)).map((step) => step.label).join(', ')}.
            </Alert>
          )}

          <div className="onb-panel" role="group" aria-label={STEPS[index].label}>
            {stepId === 'owner' && <OwnerFields value={values.owner} onChange={section('owner')} errors={errors} />}
            {stepId === 'agency' && <AgencyFields value={values.agency} onChange={section('agency')} errors={errors} />}
            {stepId === 'contract' && (
              <>
                <label className="onb-check onb-toggle">
                  <input type="checkbox" checked={values.contractEnabled} onChange={(event) => setValues((current) => ({ ...current, contractEnabled: event.target.checked }))} />
                  <span><strong>Add contract details now</strong><small>Skip this and the agency has no plan, seat or hour limits until you add a contract.</small></span>
                </label>
                {values.contractEnabled
                  ? (
                    <ContractFields
                      value={values.contract}
                      onChange={section('contract')}
                      errors={errors}
                      plans={plans.data?.plans ?? null}
                      plansLoading={plans.isLoading}
                      plansError={plans.error}
                      document={contractDoc}
                      onDocument={(file) => chooseFile(file, { ...DOCUMENT_RULES, label: 'PDF, JPG and PNG' }, setContractDoc, setDocNotice)}
                      documentNotice={docNotice}
                    />
                  )
                  : <EmptyState icon={<Icon name="file" size={22} />} title="No contract for now">You can add a plan and contract from the agency's page at any time.</EmptyState>}
              </>
            )}
            {stepId === 'setup' && (
              <SetupFields
                setup={values.setup}
                onSetup={section('setup')}
                agency={values.agency}
                onAgency={section('agency')}
                errors={errors}
                seats={seats}
                logo={logo}
                onLogo={(file) => chooseFile(file, { ...LOGO_RULES, extensions: ['png', 'jpg', 'jpeg', 'webp'], label: 'PNG, JPG and WEBP' }, setLogo, setLogoNotice)}
                logoNotice={logoNotice}
                onKnownEmployees={onKnown}
              />
            )}
            {stepId === 'review' && (
              <ReviewStep values={values} plans={plans.data?.plans} employees={known} logo={logo} document={values.contractEnabled ? contractDoc : null} onEdit={goTo} />
            )}
          </div>

          <ApiErrorMessage error={apiError} />
          {expectedSeatsError && stepId === 'review' && <Alert variant="error">{expectedSeatsError[0]}</Alert>}

          <div className="onb-footer">
            <Button type="button" variant="ghost" disabled={index === 0 || Boolean(submitting)} onClick={() => goTo(STEPS[index - 1].id)}>Back</Button>
            <div className="onb-footer-main">
              {stepId === 'review' ? (
                <>
                  <Button type="button" variant="secondary" loading={submitting === 'draft'} loadingText="Saving…" disabled={Boolean(submitting)} onClick={() => submit('draft')}>Save as draft</Button>
                  <Button type="button" loading={submitting === 'create'} loadingText="Creating…" disabled={Boolean(submitting)} onClick={() => submit('create')}>
                    {values.setup.send_invite ? 'Create & send invite' : 'Create agency'}
                  </Button>
                </>
              ) : (
                <Button type="submit">Next</Button>
              )}
            </div>
          </div>
        </form>
      </Card>
    </section>
  )
}

// After a successful create: what happened, the warnings, the uploads (each with a retry) and what to do next.
function CreatedSummary({ result, logo, contractDoc, onAnother }) {
  const { showToast } = useToast()
  const agencyId = result.agency.id
  const [agencyStatus, setAgencyStatus] = useState(result.agency.status)
  const [activating, setActivating] = useState(false)
  const [activateError, setActivateError] = useState(null)
  const [uploads, setUploads] = useState(() => ({
    document: contractDoc && result.contract ? { state: 'pending', progress: 0 } : null,
    logo: logo ? { state: 'pending', progress: 0 } : null,
  }))

  const run = useCallback(async (kind) => {
    const patch = (value) => setUploads((current) => ({ ...current, [kind]: { ...current[kind], ...value } }))
    patch({ state: 'uploading', progress: 0, error: null })
    try {
      const onProgress = (progress) => patch({ progress })
      if (kind === 'document') await authService.uploadContractDocument(agencyId, { file: contractDoc, onProgress })
      else await authService.uploadAgencyLogo(agencyId, logo, { onProgress })
      patch({ state: 'done', progress: 100 })
    } catch (reason) {
      patch({ state: 'failed', error: reason.errors?.file?.[0] ?? reason.message })
    }
  }, [agencyId, contractDoc, logo])

  // Start the uploads once, one after the other, as soon as the agency exists.
  const started = useRef(false)
  useEffect(() => {
    if (started.current) return
    started.current = true
    ;(async () => {
      if (contractDoc && result.contract) await run('document')
      if (logo) await run('logo')
    })()
  }, [run, contractDoc, logo, result.contract])

  async function activate() {
    setActivating(true)
    setActivateError(null)
    try {
      await authService.activateAgency(agencyId)
      setAgencyStatus('active')
      showToast(`${result.agency.name} is active and ${result.owner.email} was invited.`)
    } catch (reason) {
      setActivateError(reason)
    } finally {
      setActivating(false)
    }
  }

  const labels = { document: 'Contract file', logo: 'Logo' }
  const files = { document: contractDoc, logo }
  const busy = Object.values(uploads).some((upload) => upload?.state === 'uploading' || upload?.state === 'pending')
  const warnings = result.warnings.filter((warning) => !(agencyStatus === 'active' && warning.startsWith('Saved as a draft')))

  return (
    <Card className="onb-card">
      <div className="onb-done">
        <span className="onb-done-icon" aria-hidden="true"><Icon name="check" size={28} /></span>
        <h2>{result.agency.name} {agencyStatus === 'draft' ? 'was saved as a draft' : 'is ready'}</h2>
        <p>
          Owner: <strong>{result.owner.name}</strong> ({result.owner.email}) · <Badge variant={agencyStatus === 'draft' ? 'slate' : 'success'}>{agencyStatus === 'draft' ? 'Draft' : 'Active'}</Badge>
        </p>
      </div>

      {warnings.length > 0 && (
        <Alert variant="warning" title="Worth knowing">
          <ul className="onb-warnings">{warnings.map((warning) => <li key={warning}>{warning}</li>)}</ul>
        </Alert>
      )}

      {Object.entries(uploads).filter(([, upload]) => upload).length > 0 && (
        <ul className="onb-uploads" aria-label="Uploads" aria-live="polite">
          {Object.entries(uploads).filter(([, upload]) => upload).map(([kind, upload]) => (
            <li key={kind}>
              <span><strong>{labels[kind]}</strong> <span className="adm-muted">{files[kind].name}</span></span>
              {upload.state === 'done' && <Badge variant="success">Uploaded</Badge>}
              {(upload.state === 'uploading' || upload.state === 'pending') && <progress value={upload.progress} max="100" aria-label={`Uploading ${labels[kind].toLowerCase()}`} />}
              {upload.state === 'failed' && (
                <span className="onb-upload-failed">
                  <span className="ui-field-error" role="alert">{upload.error}</span>
                  <Button type="button" size="sm" variant="secondary" onClick={() => run(kind)}>Retry</Button>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
      {Object.values(uploads).some((upload) => upload?.state === 'failed') && (
        <p className="adm-muted">The agency was created either way. You can retry now, or add the file later from the agency page.</p>
      )}

      <ApiErrorMessage error={activateError} />
      <div className="onb-footer">
        <Button type="button" variant="ghost" disabled={busy} onClick={onAnother}>Create another</Button>
        <div className="onb-footer-main">
          {agencyStatus === 'draft' && <Button type="button" variant="secondary" loading={activating} loadingText="Activating…" onClick={activate}>Activate &amp; send invite</Button>}
          <Link className="ui-button ui-button-primary ui-button-md" to={`/admin/agencies/${agencyId}`}>View agency</Link>
        </div>
      </div>
    </Card>
  )
}
