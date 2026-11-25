import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Card, EmptyState, FieldRow, FormActions, Icon, Input, Select, Skeleton, Textarea, useToast } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'

const LIST_NAMES = { ghl_crm: 'GoHighLevel CRM', ghl_workflows: 'GoHighLevel workflows', ai_agent: 'AI agent', website_funnel: 'Website or funnel', integration: 'n8n, API and integrations', regression: 'Regression' }

// Bug details, the QA checklists that suit this ticket, and the history of QA runs. Staff only (QA notes are private).
export function QaTab({ ticket, meta, onChanged }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getTicketQa(ticket.ref), [ticket.ref])
  const { data, error, isLoading, reload } = useAsync(load)
  const [answers, setAnswers] = useState({})
  const [form, setForm] = useState({ note: '', evidence_url: '', resolution_summary: '', resolution_category: '' })
  const [bug, setBug] = useState(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState(null)
  if (error && !data) return <Alert variant="error" title="Could not load QA">{error.message}<div><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div></Alert>
  if (isLoading && !data) return <Skeleton variant="card" />

  const currentBug = bug ?? data.bug
  const items = Object.entries(data.library).flatMap(([list, rows]) => rows.map((row) => ({ list, label: row.label })))
  const key = (item) => `${item.list}|${item.label}`
  const answered = items.filter((item) => answers[key(item)])
  const anyFail = answered.some((item) => answers[key(item)] === 'fail')
  const canRun = ticket.status === 'qa'

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setFailure(null)
    try {
      await authService.recordTicketQa(ticket.ref, { items: answered.map((item) => ({ ...item, result: answers[key(item)] })), ...Object.fromEntries(Object.entries(form).filter(([, value]) => value)) })
      showToast(anyFail ? 'Sent back to the builder.' : 'QA passed.')
      onChanged()
    } catch (reason) {
      setFailure(reason)
    } finally {
      setBusy(false)
    }
  }
  async function saveBug(patch) {
    const next = { ...currentBug, ...patch }
    setBug(next)
    try { await authService.saveTicketBug(ticket.ref, patch) } catch { showToast('Could not save the bug details.', 'error') }
  }

  return (
    <div className="sup-qa">
      <p className="sup-note-label"><Icon name="lock" size={15} /> Staff only. QA notes are never shown to the agency.</p>
      <Card title="Bug details">
        <FieldRow columns={3}>
          <Select label="Severity" value={currentBug.severity ?? ''} onChange={(event) => saveBug({ severity: event.target.value || null })}><option value="">Not set</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></Select>
          <Select label="Component" value={currentBug.component ?? ''} onChange={(event) => saveBug({ component: event.target.value || null })}><option value="">Not set</option>{['workflow', 'crm', 'ai', 'website', 'integration', 'other'].map((name) => <option key={name} value={name}>{name}</option>)}</Select>
          <Select label="Bug status" value={currentBug.bug_status} onChange={(event) => saveBug({ bug_status: event.target.value })}>{['open', 'in_progress', 'fixed', 'retest', 'closed'].map((name) => <option key={name} value={name}>{name.replace('_', ' ')}</option>)}</Select>
        </FieldRow>
      </Card>

      {canRun ? (
        <form className="ui-form" onSubmit={submit} noValidate aria-label="Record the QA result">
          {Object.entries(data.library).map(([list, rows]) => (
            <fieldset key={list} className="sup-qa-list"><legend>{LIST_NAMES[list] ?? list}</legend>
              {rows.map((row) => {
                const name = `${list}|${row.label}`
                return (
                  <div key={row.id} className="sup-qa-row" role="radiogroup" aria-label={row.label}>
                    <span>{row.label}</span>
                    {['pass', 'fail', 'na'].map((result) => <label key={result} className="ui-checkgroup-option"><input type="radio" name={name} checked={answers[name] === result} onChange={() => setAnswers({ ...answers, [name]: result })} /><span>{result === 'na' ? 'N/A' : result[0].toUpperCase() + result.slice(1)}</span></label>)}
                  </div>
                )
              })}
            </fieldset>
          ))}
          <Textarea label={anyFail ? 'What failed? (required)' : 'Note'} rows={3} maxLength={5000} value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} error={failure?.errors?.note?.[0]} />
          <Input label="Evidence link" type="url" placeholder="https://" value={form.evidence_url} onChange={(event) => setForm({ ...form, evidence_url: event.target.value })} error={failure?.errors?.evidence_url?.[0]} />
          {!anyFail && (
            <>
              <Textarea label="Resolution summary (written for the agency)" rows={3} maxLength={5000} value={form.resolution_summary} onChange={(event) => setForm({ ...form, resolution_summary: event.target.value })} error={failure?.errors?.resolution_summary?.[0]} />
              <Select label="Resolution category" value={form.resolution_category} onChange={(event) => setForm({ ...form, resolution_category: event.target.value })} error={failure?.errors?.resolution_category?.[0]}>
                <option value="">Choose…</option>{(meta?.resolution_categories ?? []).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
              </Select>
            </>
          )}
          <ApiErrorMessage error={failure && !failure.errors ? failure : null} />
          <FormActions submitLabel={anyFail ? 'Fail and send back' : 'Pass and resolve'} variant={anyFail ? 'danger' : 'primary'} loadingLabel="Saving…" loading={busy} disabled={answered.length === 0} disabledReason="Answer at least one checklist item." />
        </form>
      ) : <Alert variant="info">QA can be recorded while the ticket is in QA.</Alert>}

      <h3>QA history</h3>
      {data.runs.length === 0 ? <EmptyState title="No QA runs yet">Results of independent checks show up here.</EmptyState> : (
        <ol className="sup-thread">
          {data.runs.map((run) => (
            <li key={run.id} className="sup-internal-card"><header><Icon name="lock" size={14} /><strong>{run.result === 'pass' ? 'Passed' : 'Failed'}</strong><span>{run.reviewer}</span></header>
              {run.note && <p>{run.note}</p>}
              {run.evidence_url && <p><a href={run.evidence_url} target="_blank" rel="noopener noreferrer">Evidence</a></p>}
              <p className="ui-field-help">{run.items.filter((item) => item.result === 'pass').length} passed, {run.items.filter((item) => item.result === 'fail').length} failed, {run.items.filter((item) => item.result === 'na').length} not applicable</p>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
