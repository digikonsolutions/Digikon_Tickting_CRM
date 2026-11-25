import { useCallback, useState } from 'react'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { ApiErrorMessage } from '../auth/components/ApiErrorMessage'
import { Alert, Badge, Button, ChipInput, CopyButton, FormActions, Input, ProgressBadge, Select, Skeleton, Textarea } from '../../components/ui'
import { LinkOrText } from './BriefView'
import { REF_TYPES, SECTION_TITLES, WAITING_ON } from './workOrder'
import { useAuth } from '../../auth/useAuth'
import './workorder.css'

const filled = (value) => !(value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0))
const typeLabel = (type) => REF_TYPES.find((item) => item.value === type)?.label ?? type

// Small facts for a ticket card: work type, checklist x/y, a blocked marker and how complete the details are.
export function WorkOrderBadges({ summary }) {
  if (!summary) return null
  return (
    <span className="tk-badges">
      {summary.work_type_label && <Badge variant="info">{summary.work_type_label}</Badge>}
      {summary.checklist_total > 0 && <ProgressBadge done={summary.checklist_done} total={summary.checklist_total} label="Checklist" />}
      {summary.blocked && <span className="tk-blocked">Blocked</span>}
      {summary.completeness < 100 && summary.work_type && <ProgressBadge percent={summary.completeness} label="Details" />}
    </span>
  )
}

function Group({ title, children }) {
  // A child is shown when it has something to show: Line has a value, Links has items, anything else is real content.
  const hasContent = (node) => (node.props?.value !== undefined ? filled(node.props.value) : node.props?.items !== undefined ? filled(node.props.items) : true)
  const items = [].concat(children).filter(Boolean).filter(hasContent)
  if (items.length === 0) return null
  return <section><h4>{title}</h4>{items}</section>
}

function Line({ label, value, copy }) {
  if (!filled(value)) return null
  return <p><strong>{label}: </strong><span className="brief-text">{Array.isArray(value) ? value.join(', ') : String(value)}</span>{copy && <CopyButton value={value} />}</p>
}

function Links({ label, items }) {
  if (!filled(items)) return null
  return <div><strong>{label}</strong><ul>{items.map((item) => <li key={item}><LinkOrText value={item} /></li>)}</ul></div>
}

// A ticket's work order, read-only, grouped by section with empty ones hidden. `staff` adds the internal notes.
export function WorkOrderView({ order }) {
  if (!order) return null
  const { requirements: r = {}, ghl = {}, inputs: i = {}, dependencies: d = {}, environment: env = {}, testing: t = {}, delivery = {}, review = {} } = order
  const yn = (value) => (value === true ? 'Yes' : value === false ? 'No' : null)
  return (
    <div className="tk-order">
      <Group title={SECTION_TITLES.requirements}>
        {[<Line key="i" label="Instructions" value={r.instructions} />, <Line key="a" label="Acceptance criteria" value={r.acceptance_criteria} />, <Line key="b" label="Expected behavior" value={r.expected_behavior} />, <Line key="o" label="Out of scope" value={r.out_of_scope} />, <Links key="u" label="References" items={r.reference_urls} />, filled(r.loom_url) && <p key="l"><strong>Recording: </strong><LinkOrText value={r.loom_url} /></p>].filter((node) => node && (node.props?.value === undefined || filled(node.props.value)))}
      </Group>
      <Group title={SECTION_TITLES.ghl}>
        {[<Line key="loc" label="Location ID" value={ghl.location_id} copy />, ...(ghl.refs ?? []).map((ref, index) => (
          <p key={index} className="tk-ref"><Badge>{typeLabel(ref.type)}</Badge><strong>{ref.name}</strong>{ref.ghl_id && <><code>{ref.ghl_id}</code><CopyButton value={ref.ghl_id} /></>}{ref.url && <><LinkOrText value={ref.url} /><CopyButton value={ref.url} /></>}</p>
        ))].filter((node) => node.props.value === undefined || filled(node.props.value))}
      </Group>
      <Group title={SECTION_TITLES.inputs}>
        {[<Line key="s" label="Client supplies" value={i.client_supplies} />, <Links key="a" label="Assets" items={i.asset_urls} />, <Line key="r" label="Inputs ready" value={yn(i.inputs_ready)} />, <Line key="m" label="Still missing" value={i.missing} />, <Line key="x" label="Access needed" value={i.access_needed} />]}
      </Group>
      <Group title={SECTION_TITLES.dependencies}>
        {[<Line key="w" label="Waiting on" value={WAITING_ON.find((item) => item.value === d.waiting_on)?.label} />, <Line key="d" label="Depends on tickets" value={d.depends_on?.map((id) => `#${id}`)} />]}
      </Group>
      <Group title={SECTION_TITLES.environment}>
        {[<Line key="e" label="Build in" value={env.environment} />, <Line key="l" label="Affects live traffic" value={yn(env.affects_live_traffic)} />, <Line key="p" label="Needs approval before publishing" value={yn(env.requires_approval)} />, <Line key="r" label="Rollback" value={env.rollback_note} />]}
      </Group>
      <Group title={SECTION_TITLES.testing}>
        {[<Line key="s" label="Steps" value={t.steps} />, <Line key="d" label="Test data" value={t.test_data} />, <Line key="v" label="Devices" value={t.devices} />, <Line key="e" label="Expected" value={t.expected} />, <Line key="q" label="QA checklist" value={t.qa_checklist} />]}
      </Group>
      <Group title="Notes">{[<Line key="c" label="Client notes" value={order.notes?.client} />, <Line key="n" label="Internal notes (staff only)" value={order.notes?.internal} />]}</Group>
      <Group title="Delivery">
        {[<Line key="d" label="What was done" value={delivery.done} />, <Links key="l" label="Delivered work" items={delivery.links} />, <Line key="g" label="Changes in GoHighLevel" value={delivery.ghl_changes} />, <Line key="m" label="How to use and maintain" value={delivery.maintain} />, <Line key="k" label="Known limitations" value={delivery.limitations} />, <Line key="f" label="Follow-up suggestions" value={delivery.followups} />, <Line key="t" label="Time note" value={delivery.time_note} />]}
      </Group>
      <Group title="Review">{[<Line key="f" label="Feedback" value={review.feedback} />, <Line key="a" label="Approved" value={yn(review.approved)} />, <Line key="r" label="Rework reason" value={review.rework_reason} />]}</Group>
    </div>
  )
}

function Checklist({ ticket, order, canTick, onOrder }) {
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const items = order.checklist ?? []
  if (items.length === 0) return null
  async function toggle(item) {
    setBusy(item.id)
    setError(null)
    try {
      const result = await authService.toggleChecklistItem(ticket.id, item.id, !item.done)
      onOrder(result.work_order)
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(null)
    }
  }
  return (
    <section>
      <h4>Checklist <ProgressBadge done={items.filter((item) => item.done).length} total={items.length} /></h4>
      <ul className="tk-checklist">
        {items.map((item) => (
          <li key={item.id} className={item.done ? 'is-done' : undefined}>
            <label>
              <input type="checkbox" checked={item.done} disabled={!canTick || busy === item.id} onChange={() => toggle(item)} />
              <span>{item.text}</span>
            </label>
          </li>
        ))}
      </ul>
      {!canTick && <p className="tk-readonly-note">Ticking steps does not change the status or the hours.</p>}
      <ApiErrorMessage error={error} />
    </section>
  )
}

function DeliveryForm({ ticket, order, onOrder }) {
  const d = order.delivery ?? {}
  const [values, setValues] = useState({ done: d.done ?? '', links: d.links ?? [], ghl_changes: d.ghl_changes ?? '', maintain: d.maintain ?? '', limitations: d.limitations ?? '', followups: d.followups ?? '', time_note: d.time_note ?? '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [saved, setSaved] = useState(false)
  const set = (key) => (event) => { setValues((current) => ({ ...current, [key]: event.target.value })); setSaved(false) }
  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const result = await authService.updateTicketDelivery(ticket.id, values)
      onOrder(result.work_order)
      setSaved(true)
    } catch (reason) {
      setError(reason)
    } finally {
      setSaving(false)
    }
  }
  const err = (key) => error?.errors?.[key]?.[0]
  return (
    <form className="tk-delivery" onSubmit={submit} noValidate aria-label="Delivery">
      <h4>Delivery</h4>
      <p className="tk-readonly-note">Fill this in before you send the ticket for review: what you did, and at least one link.</p>
      <Textarea id={`delivery-done-${ticket.id}`} label="What was done" rows={3} maxLength={4000} value={values.done} onChange={set('done')} error={err('done')} />
      <ChipInput label="Links to the delivered work" value={values.links} onChange={(next) => { setValues((current) => ({ ...current, links: next })); setSaved(false) }} max={20} maxLength={500} placeholder="https://… then Enter" error={err('links') ?? err('links.0')} />
      <Textarea id={`delivery-ghl-${ticket.id}`} label="Changes made in GoHighLevel" rows={2} maxLength={3000} value={values.ghl_changes} onChange={set('ghl_changes')} error={err('ghl_changes')} />
      <Textarea id={`delivery-maintain-${ticket.id}`} label="How to use and maintain it" rows={2} maxLength={3000} value={values.maintain} onChange={set('maintain')} error={err('maintain')} />
      <Textarea id={`delivery-limits-${ticket.id}`} label="Known limitations" rows={2} maxLength={2000} value={values.limitations} onChange={set('limitations')} error={err('limitations')} />
      <Textarea id={`delivery-follow-${ticket.id}`} label="Follow-up suggestions" rows={2} maxLength={2000} value={values.followups} onChange={set('followups')} error={err('followups')} />
      <Input label="Time note (information only)" maxLength={500} value={values.time_note} onChange={set('time_note')} error={err('time_note')} />
      <ApiErrorMessage error={error && !error.errors ? error : null} />
      {saved && <Alert variant="success">Delivery saved.</Alert>}
      <FormActions submitLabel="Save delivery" loadingLabel="Saving…" loading={saving} />
    </form>
  )
}

function BlockedForm({ ticket, order, onOrder }) {
  const b = order.blocked ?? {}
  const [blocked, setBlocked] = useState(Boolean(b.blocked))
  const [reason, setReason] = useState(b.reason ?? '')
  const [waitingOn, setWaitingOn] = useState(b.waiting_on ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const result = await authService.updateTicketBlocked(ticket.id, { blocked, reason: blocked ? reason : null, waiting_on: waitingOn || null })
      onOrder(result.work_order)
    } catch (failure) {
      setError(failure)
    } finally {
      setSaving(false)
    }
  }
  return (
    <form className="tk-delivery" onSubmit={submit} noValidate aria-label="Blocked">
      <h4>Blocked?</h4>
      <label className="ui-checkgroup-option"><input type="checkbox" checked={blocked} onChange={(event) => setBlocked(event.target.checked)} /><span>I cannot continue without something</span></label>
      {blocked && (
        <>
          <Textarea id={`blocked-reason-${ticket.id}`} label="What is blocking you" rows={2} maxLength={1000} required value={reason} onChange={(event) => setReason(event.target.value)} error={error?.errors?.reason?.[0]} />
          <Select id={`blocked-wait-${ticket.id}`} label="Waiting on" value={waitingOn} onChange={(event) => setWaitingOn(event.target.value)}>
            <option value="">Not specified</option>
            {WAITING_ON.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </Select>
        </>
      )}
      <ApiErrorMessage error={error && !error.errors ? error : null} />
      <p className="tk-readonly-note">This is a note for the team. It does not change the ticket status.</p>
      <FormActions submitLabel="Save" loadingLabel="Saving…" loading={saving} />
    </form>
  )
}

// The work order of one ticket for whoever is looking: read-only for everyone, a checklist the assigned employee and
// the agency can tick, and the delivery and blocked forms for the assigned employee only. Fetches its own data.
export function WorkOrderPanel({ ticket, projectEmployeeId, mine = false }) {
  const { user } = useAuth()
  const load = useCallback(() => authService.getTicketByKey(ticket.key), [ticket.key])
  const { data, error, isLoading, reload } = useAsync(load)
  const [override, setOverride] = useState(null)
  const order = override ?? data?.work_order
  const closed = ['done', 'cancelled'].includes(ticket.status)
  const isAssigned = user.role === 'employee' && (mine || projectEmployeeId === user.id)
  const isAgency = ['agency_owner', 'agency_client'].includes(user.role)
  const canTick = !closed && (isAssigned || isAgency)

  if (isLoading && !data) return <Skeleton variant="card" />
  if (error) return <Alert variant="error">Could not load the work order. <Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></Alert>
  if (!order) return isAssigned ? <p className="tk-readonly-note">This ticket has no work order yet.</p> : null
  const onOrder = (next) => setOverride(next)

  return (
    <div className="tk-order">
      {order.completeness?.missing?.length > 0 && !isAssigned && <p className="tk-missing">Details {order.completeness.percent}% complete.</p>}
      <WorkOrderView order={order} />
      <Checklist ticket={ticket} order={order} canTick={canTick} onOrder={onOrder} />
      {isAssigned && !closed && <BlockedForm key={`b${order.blocked?.blocked}`} ticket={ticket} order={order} onOrder={onOrder} />}
      {isAssigned && !closed && <DeliveryForm ticket={ticket} order={order} onOrder={onOrder} />}
    </div>
  )
}
