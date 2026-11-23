import { FieldRow, FormSection, Input } from '../../../components/ui'

// Start and due dates of a project. Only a role that may set the schedule ever gets this section (see lib/permissions):
// agency owners and clients do not see it, and the server ignores dates from them anyway.
export function ScheduleFields({ values, onChange, errors = {}, min }) {
  const invalid = Boolean(values.start_date && values.due_date && values.due_date < values.start_date)
  return (
    <FormSection title="Schedule" description="Hours are scheduled earliest-first, Monday to Saturday.">
      <FieldRow>
        <Input id="project-start" label="Start date" type="date" min={min} required value={values.start_date ?? ''} onChange={onChange('start_date')} error={errors.start_date} />
        <Input id="project-due" label="Due date" type="date" min={values.start_date || min} required value={values.due_date ?? ''} onChange={onChange('due_date')} error={errors.due_date ?? (invalid ? 'Must be on or after the start date.' : undefined)} />
      </FieldRow>
    </FormSection>
  )
}
