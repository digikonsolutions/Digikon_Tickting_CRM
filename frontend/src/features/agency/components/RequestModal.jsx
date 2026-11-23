import { useCallback, useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { useAsync } from '../../../hooks/useAsync'
import { Button, FieldRow, Input, Modal, Select, Textarea, useToast } from '../../../components/ui'
import { FilePicker } from '../../briefs/components/FilePicker'
import { uploadErrorMessage } from '../../briefs/briefData'

const REQUEST_COPY = {
  more_hours: { title: 'Request more hours', hint: 'Tell us how many hours you need and for what.' },
  employee: { title: 'Request an employee', hint: 'Start date, tools or anything else that helps.' },
  upgrade: { title: 'Request a plan upgrade', hint: 'Tell us what you need: more seats, hours, projects or client accounts.' },
  renewal: { title: 'Request a renewal', hint: 'Anything we should know about your renewal?' },
}

// One request to your support team, with notes and optional files. Mount it with a `key` so it starts empty each time.
export function RequestModal({ type, onClose, onSent }) {
  const { showToast } = useToast()
  // "Request an employee" asks for a role, hours per week and how long, instead of free text.
  const isEmployee = type === 'employee'
  const loadCatalog = useCallback(() => (isEmployee ? authService.getServiceCatalog() : Promise.resolve(null)), [isEmployee])
  const catalog = useAsync(loadCatalog).data
  const limits = catalog?.employee_request ?? { roles: {}, max_hours_per_week: 36, max_duration_weeks: 52 }
  const [role, setRole] = useState('')
  const [hoursPerWeek, setHoursPerWeek] = useState('20')
  const [durationWeeks, setDurationWeeks] = useState('4')
  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])
  const [notice, setNotice] = useState(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState(null)
  const copy = REQUEST_COPY[type] ?? REQUEST_COPY.more_hours

  async function send(event) {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      await authService.sendAgencyRequest({
        type, message: message.trim(), files,
        details: isEmployee ? { role, hours_per_week: Number(hoursPerWeek), duration_weeks: Number(durationWeeks) } : null,
      })
      showToast('Request sent. We will get back to you.')
      onSent?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSending(false)
    }
  }

  const hoursOk = Number.isInteger(Number(hoursPerWeek)) && Number(hoursPerWeek) >= 1 && Number(hoursPerWeek) <= limits.max_hours_per_week
  const weeksOk = Number.isInteger(Number(durationWeeks)) && Number(durationWeeks) >= 1 && Number(durationWeeks) <= limits.max_duration_weeks
  const employeeReady = !isEmployee || (role !== '' && hoursOk && weeksOk)
  const detailError = (field) => error?.errors?.[`details.${field}`]?.[0]
  const fileError = error?.errors && Object.entries(error.errors).find(([key]) => key.startsWith('files'))?.[1]?.[0]

  return (
    <Modal
      open={Boolean(type)}
      onClose={() => !sending && onClose()}
      title={copy.title}
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={sending} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-request-form" loading={sending} disabled={!employeeReady} loadingText="Sending…">Send request</Button>
        </>
      )}
    >
      <form id="agency-request-form" className="ui-form" onSubmit={send}>
        {isEmployee && (
          <>
            <Select id="request-role" label="What kind of team member?" required value={role} onChange={(event) => setRole(event.target.value)} error={detailError('role')}>
              <option value="">Choose a role</option>
              {Object.entries(limits.roles).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
            <FieldRow columns={2}>
              <Input id="request-hours" label="Hours per week" type="number" inputMode="numeric" min="1" max={limits.max_hours_per_week} step="1" required value={hoursPerWeek} onChange={(event) => setHoursPerWeek(event.target.value)} error={detailError('hours_per_week') ?? (hoursOk ? undefined : `Enter 1 to ${limits.max_hours_per_week}.`)} />
              <Input id="request-weeks" label="For how many weeks" type="number" inputMode="numeric" min="1" max={limits.max_duration_weeks} step="1" required value={durationWeeks} onChange={(event) => setDurationWeeks(event.target.value)} error={detailError('duration_weeks') ?? (weeksOk ? undefined : `Enter 1 to ${limits.max_duration_weeks}.`)} />
            </FieldRow>
          </>
        )}
        <Textarea
          id="agency-request-message"
          label="Notes (optional)"
          rows="4"
          maxLength="1000"
          value={message}
          helperText={`${copy.hint} ${message.length} of 1000 characters.`}
          onChange={(event) => setMessage(event.target.value)}
          error={error?.errors?.message?.[0]}
        />
        <FilePicker files={files} onChange={setFiles} disabled={sending} notice={notice ?? (fileError ? uploadErrorMessage(error) : null)} onNotice={setNotice} />
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
      </form>
    </Modal>
  )
}
