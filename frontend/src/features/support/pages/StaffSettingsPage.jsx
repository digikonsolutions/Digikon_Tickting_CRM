import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Alert, Card, ChipInput, FieldRow, FormActions, Input, PageHeader, Skeleton, useToast } from '../../../components/ui'
import '../support.css'

const PRIORITIES = [['urgent', 'P1 Critical'], ['high', 'P2 High'], ['normal', 'P3 Normal'], ['low', 'P4 Low']]
const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']]

// SLA numbers, support hours, holidays and the windows. Admin only. Saved values replace the defaults in config/support.php.
export function StaffSettingsPage() {
  const { showToast } = useToast()
  const load = useCallback(() => authService.getSupportSettings(), [])
  const { data, error, isLoading } = useAsync(load)
  const [draft, setDraft] = useState(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState(null)
  const s = draft ?? data?.settings
  if (error) return <section className="page"><Alert variant="error" title="Settings are for administrators">{error.message}</Alert></section>
  if (isLoading || !s) return <section className="page"><Skeleton variant="card" /></section>
  const set = (patch) => setDraft({ ...s, ...patch })
  const err = (path) => failure?.errors?.[path]?.[0]

  async function save(event) {
    event.preventDefault()
    setBusy(true)
    setFailure(null)
    try {
      const result = await authService.saveSupportSettings(s)
      setDraft(result.settings)
      showToast('Settings saved. New tickets use them straight away.')
    } catch (reason) {
      setFailure(reason)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="page">
      <PageHeader eyebrow="Staff" title="Support settings" subtitle="Targets are in business minutes, inside the support hours below. Changes apply to new tickets." />
      <form className="ui-form" onSubmit={save} noValidate>
        <Card title="SLA targets (business minutes)">
          <table className="sup-queue"><thead><tr><th scope="col">Priority</th><th scope="col">First response</th><th scope="col">Resolution</th></tr></thead>
            <tbody>{PRIORITIES.map(([key, label]) => (
              <tr key={key}><th scope="row">{label}</th>
                {['response', 'resolution'].map((kind) => <td key={kind}><Input aria-label={`${label} ${kind}`} type="number" min="1" value={s.sla[key][kind]} onChange={(event) => set({ sla: { ...s.sla, [key]: { ...s.sla[key], [kind]: Number(event.target.value) } } })} error={err(`sla.${key}.${kind}`)} /></td>)}
              </tr>))}</tbody></table>
          <Input label="At risk after (share of the window used)" type="number" step="0.05" min="0.1" max="0.99" value={s.at_risk_ratio} onChange={(event) => set({ at_risk_ratio: Number(event.target.value) })} error={err('at_risk_ratio')} />
          <label className="ui-checkgroup-option"><input type="checkbox" checked={s.pause_while_waiting_for_agency} onChange={(event) => set({ pause_while_waiting_for_agency: event.target.checked })} /><span>Pause the clock while waiting for the agency</span></label>
        </Card>
        <Card title="Support hours">
          <fieldset className="ui-checkgroup"><legend className="ui-field-label">Working days</legend>
            <div className="ui-checkgroup-options">{DAYS.map(([value, name]) => <label key={value} className="ui-checkgroup-option"><input type="checkbox" checked={s.hours.days.includes(value)} onChange={(event) => set({ hours: { ...s.hours, days: event.target.checked ? [...s.hours.days, value].sort() : s.hours.days.filter((day) => day !== value) } })} /><span>{name}</span></label>)}</div>
          </fieldset>
          <FieldRow columns={3}>
            <Input label="From" type="time" value={s.hours.start} onChange={(event) => set({ hours: { ...s.hours, start: event.target.value } })} error={err('hours.start')} />
            <Input label="To" type="time" value={s.hours.end} onChange={(event) => set({ hours: { ...s.hours, end: event.target.value } })} error={err('hours.end')} />
            <Input label="Timezone" value={s.timezone} onChange={(event) => set({ timezone: event.target.value })} error={err('timezone')} helperText="For example Asia/Karachi" />
          </FieldRow>
          <ChipInput label="Holidays (YYYY-MM-DD)" value={s.holidays} onChange={(next) => set({ holidays: next })} max={100} maxLength={10} placeholder="2026-12-25 then Enter" error={err('holidays.0') ?? err('holidays')} />
        </Card>
        <Card title="Windows and escalation">
          <FieldRow columns={3}>
            <Input label="Reopen window (days)" type="number" min="1" value={s.reopen_days} onChange={(event) => set({ reopen_days: Number(event.target.value) })} error={err('reopen_days')} />
            <Input label="Auto-close resolved after (days)" type="number" min="1" value={s.auto_close_days} onChange={(event) => set({ auto_close_days: Number(event.target.value) })} error={err('auto_close_days')} />
            <Input label="Unassigned escalation (business minutes)" type="number" min="1" value={s.unassigned_escalation_minutes.default} onChange={(event) => set({ unassigned_escalation_minutes: { ...s.unassigned_escalation_minutes, default: Number(event.target.value) } })} />
          </FieldRow>
          <FieldRow>
            <Input label="Remind a waiting agency after (business days)" type="number" min="1" value={s.waiting_agency_reminder_business_days} onChange={(event) => set({ waiting_agency_reminder_business_days: Number(event.target.value) })} />
            <Input label="Escalate a stalled ticket after (business days)" type="number" min="1" value={s.waiting_agency_stalled_business_days} onChange={(event) => set({ waiting_agency_stalled_business_days: Number(event.target.value) })} />
          </FieldRow>
        </Card>
        <ApiErrorMessage error={failure && !failure.errors ? failure : null} />
        <FormActions submitLabel="Save settings" loadingLabel="Saving…" loading={busy} />
      </form>
    </section>
  )
}
