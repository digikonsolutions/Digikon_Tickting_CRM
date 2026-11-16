import { Input, Select, Textarea } from '../../../components/ui'
import { DUE_PREFERENCES, SEVERITIES } from '../briefData'

const TICKET_BRIEF_FIELDS = ['steps_to_reproduce', 'expected_result', 'actual_result', 'affected_url', 'environment', 'severity', 'due_preference', 'related_ticket_ids']

// Optional extra detail for a ticket (bug reports, change requests). `otherTickets` are the project's
// other tickets, offered as related-ticket choices.
export function TicketBriefFields({ errors = {}, defaults = {}, otherTickets = [] }) {
  const error = (field) => errors?.[field]?.[0]
  const hasError = Object.keys(errors ?? {}).some((field) => TICKET_BRIEF_FIELDS.includes(field.split('.')[0]))
  const related = (defaults.related_ticket_ids ?? []).map(String)
  const relatedError = error('related_ticket_ids') ?? Object.entries(errors ?? {}).find(([field]) => field.startsWith('related_ticket_ids.'))?.[1]?.[0]

  return (
    <details className="brief-fields" open={hasError || undefined}>
      <summary>More details <span>(optional)</span></summary>
      <div className="brief-fields-grid">
        <Textarea name="steps_to_reproduce" label="Steps to reproduce" rows="3" maxLength="5000" defaultValue={defaults.steps_to_reproduce ?? ''} error={error('steps_to_reproduce')} />
        <Textarea name="expected_result" label="Expected result" rows="2" maxLength="3000" defaultValue={defaults.expected_result ?? ''} error={error('expected_result')} />
        <Textarea name="actual_result" label="Actual result" rows="2" maxLength="3000" defaultValue={defaults.actual_result ?? ''} error={error('actual_result')} />
        <Input name="affected_url" label="Affected page URL" type="url" maxLength="500" placeholder="https://" defaultValue={defaults.affected_url ?? ''} error={error('affected_url')} />
        <Input name="environment" label="Environment or browser" maxLength="255" placeholder="Chrome 130, macOS" defaultValue={defaults.environment ?? ''} error={error('environment')} />
        <Select name="severity" label="Severity" defaultValue={defaults.severity ?? ''} error={error('severity')}>
          <option value="">Not specified</option>
          {SEVERITIES.map((severity) => <option key={severity} value={severity}>{severity}</option>)}
        </Select>
        <Select name="due_preference" label="Due preference" defaultValue={defaults.due_preference ?? ''} error={error('due_preference')} helperText="A hint only. The schedule is still planned automatically.">
          {DUE_PREFERENCES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
        {otherTickets.length > 0 && (
          <Select name="related_ticket_ids" label="Related tickets" multiple size={Math.min(5, otherTickets.length)} defaultValue={related} error={relatedError} helperText="Hold Ctrl or Cmd to pick more than one.">
            {otherTickets.map((ticket) => <option key={ticket.id} value={ticket.id}>{ticket.key} · {ticket.title}</option>)}
          </Select>
        )}
      </div>
    </details>
  )
}
