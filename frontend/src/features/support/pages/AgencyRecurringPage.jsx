import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Card, EmptyState, FormActions, Input, PageHeader, Select, Skeleton, Textarea, useToast } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import '../support.css'

// Tickets the agency wants created for it on a schedule, such as a weekly health check. The same questions as a normal
// ticket decide what a schedule must contain; this form covers the types that need only a few answers.
export function AgencyRecurringPage() {
  const { showToast } = useToast()
  const load = useCallback(() => authService.listRecurringTickets(), [])
  const { data, error, isLoading, reload } = useAsync(load)
  const form = useAsync(useCallback(() => authService.getSupportForm(), []))
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const [values, setValues] = useState(() => ({ type: 'technical_support', title: '', description: '', expected_result: '', frequency: 'weekly', start_on: new Date().toISOString().slice(0, 10), details: {} }))
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState(null)
  const type = (form.data?.types ?? []).find((item) => item.key === values.type)
  const err = (key) => failure?.errors?.[key]?.[0]

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setFailure(null)
    try {
      await authService.createRecurringTicket(values)
      showToast('Scheduled.')
      setValues({ ...values, title: '', description: '', expected_result: '', details: {} })
      await reload()
    } catch (reason) {
      setFailure(reason)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="page">
      <PageHeader eyebrow={<Link to="/agency/support">Support</Link>} title="Recurring tickets" subtitle="We create these for you on the schedule you choose." />
      <ApiErrorMessage error={error} />
      {isLoading && !data ? <Skeleton variant="card" /> : (data?.schedules ?? []).length === 0
        ? <EmptyState title="Nothing scheduled">Add a weekly or monthly ticket below.</EmptyState>
        : (
          <ul className="sup-list" aria-label="Scheduled tickets">
            {data.schedules.map((item) => (
              <li className="sup-row" key={item.id}><span className="sup-ref">{item.frequency}</span>
                <div><strong>{item.title}</strong><div className="sup-row-meta"><span>Next on {formatDate(item.next_run_on)}</span></div></div>
                <Button type="button" variant="ghost" size="sm" aria-label={`Stop ${item.title}`} onClick={async () => { await authService.deleteRecurringTicket(item.id); reload() }}>Stop</Button>
              </li>
            ))}
          </ul>
        )}
      <Card title="Add a recurring ticket">
        <form className="ui-form" onSubmit={submit} noValidate>
          <Select label="Type" value={values.type} onChange={(event) => setValues({ ...values, type: event.target.value, details: {} })}>{(form.data?.types ?? []).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</Select>
          <Input label="Subject" required maxLength={160} value={values.title} onChange={(event) => setValues({ ...values, title: event.target.value })} error={err('title')} />
          <Textarea label="Description" required rows={3} value={values.description} onChange={(event) => setValues({ ...values, description: event.target.value })} error={err('description')} />
          <Textarea label="Expected result" required rows={2} value={values.expected_result} onChange={(event) => setValues({ ...values, expected_result: event.target.value })} error={err('expected_result')} />
          {(type?.fields ?? []).filter((field) => field.required).map((field) => <Input key={field.key} label={field.label} required value={values.details[field.key] ?? ''} onChange={(event) => setValues({ ...values, details: { ...values.details, [field.key]: event.target.value } })} error={err(`details.${field.key}`)} />)}
          <Select label="Repeats" value={values.frequency} onChange={(event) => setValues({ ...values, frequency: event.target.value })}><option value="weekly">Every week</option><option value="monthly">Every month</option></Select>
          <Input label="First ticket on" type="date" min={today} value={values.start_on} onChange={(event) => setValues({ ...values, start_on: event.target.value })} error={err('start_on')} />
          <ApiErrorMessage error={failure && !failure.errors ? failure : null} />
          <FormActions submitLabel="Schedule" loadingLabel="Saving…" loading={busy} disabled={!values.title.trim() || !values.description.trim()} disabledReason="Add a subject and a description." />
        </form>
      </Card>
    </section>
  )
}
