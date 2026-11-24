import { useId } from 'react'
import { Alert, ChecklistEditor, ChipInput, FieldRow, Input, MultiSelect, RepeatableList, Select, Skeleton, Textarea, UrlInput } from '../../components/ui'
import { GhlBriefView } from './BriefView'
import { hasErrorUnder } from './objectPath'
import { missingFields, REF_TYPES, SECTION_TITLES, WAITING_ON } from './workOrder'
import './workorder.css'

const P = 'work_order'
const triSelect = (value) => (value === true ? 'true' : value === false ? 'false' : value ?? '')

function Section({ id, title, errors, children }) {
  return (
    <details className="tk-section" open={hasErrorUnder(errors, `${P}.${id}`) || undefined}>
      <summary>{title}</summary>
      <div className="tk-section-body">{children}</div>
    </details>
  )
}

function YesNo({ id, label, value, onChange, error }) {
  return (
    <Select id={id} label={label} value={triSelect(value)} onChange={(event) => onChange(event.target.value)} error={error}>
      <option value="">Not specified</option>
      <option value="true">Yes</option>
      <option value="false">No</option>
    </Select>
  )
}

// The work type, with its template applied when it changes. Lives next to the title and hours at the top of the form.
export function WorkTypeField({ details, errors, disabled }) {
  const { state, templates, chooseType, template } = details
  const missing = missingFields(state, template)
  return (
    <div className="tk-worktype">
      <Select id="ticket-work-type" label="Work type" value={state.work_type} disabled={disabled} onChange={(event) => chooseType(event.target.value)} error={errors?.[`${P}.work_type`]?.[0]}
        helperText="Optional. Choosing one adds a starting checklist and acceptance criteria you can change.">
        <option value="">Not specified</option>
        {templates.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
      </Select>
      {template && missing.length > 0 && <p className="tk-missing" aria-live="polite">Missing: {missing.join(', ')}. You can still save.</p>}
    </div>
  )
}

// What the project's brief already says about this kind of work, read-only, so the ticket does not repeat it.
export function BriefContext({ brief, section }) {
  if (!brief) return null
  const key = { workflow_build: 'automations' }[section] ?? section
  if (!key || !brief[key] || (Array.isArray(brief[key]) ? brief[key].length === 0 : Object.keys(brief[key]).length === 0)) return null
  return (
    <details className="tk-context">
      <summary>From the project brief</summary>
      <GhlBriefView brief={brief} onlySections={[key]} />
    </details>
  )
}

// The work-order sections of the ticket form: requirements, GHL targets, inputs, dependencies, environment, testing and
// the checklist. Everything is optional; none of it changes the hours or the schedule.
export function WorkOrderFields({ details, errors, siblings = [], brief, disabled }) {
  const { state, setField, isLoading, template } = details
  const uid = useId()
  const e = (path) => errors?.[`${P}.${path}`]?.[0]
  if (isLoading) return <Skeleton variant="card" />
  const fieldset = { disabled }

  return (
    <fieldset className="tk-form" {...fieldset}>
      <BriefContext brief={brief} section={template?.brief_section ?? state.brief_section} />

      <Section id="requirements" title={SECTION_TITLES.requirements} errors={errors}>
        <Textarea id={`${uid}-instructions`} label="Detailed instructions" rows={5} maxLength={8000} value={state.requirements.instructions} onChange={(event) => setField('requirements.instructions', event.target.value)} error={e('requirements.instructions')} helperText="Plain text. What exactly should be built or changed." />
        <Textarea id={`${uid}-acceptance`} label="Acceptance criteria" rows={4} maxLength={4000} value={state.requirements.acceptance_criteria} onChange={(event) => setField('requirements.acceptance_criteria', event.target.value)} error={e('requirements.acceptance_criteria')} helperText="How you will know it is done." />
        <ChipInput label="Expected behavior, step by step" value={state.requirements.expected_behavior} onChange={(next) => setField('requirements.expected_behavior', next)} max={30} maxLength={400} error={e('requirements.expected_behavior')} />
        <Textarea id={`${uid}-oos`} label="Out of scope for this ticket" rows={2} maxLength={2000} value={state.requirements.out_of_scope} onChange={(event) => setField('requirements.out_of_scope', event.target.value)} error={e('requirements.out_of_scope')} />
        <ChipInput label="Example or reference links" value={state.requirements.reference_urls} onChange={(next) => setField('requirements.reference_urls', next)} max={10} maxLength={500} placeholder="https://… then Enter" error={e('requirements.reference_urls')} />
        <UrlInput label="Loom or screen recording" value={state.requirements.loom_url} onChange={(event) => setField('requirements.loom_url', event.target.value)} error={e('requirements.loom_url')} />
      </Section>

      <Section id="ghl" title={SECTION_TITLES.ghl} errors={errors}>
        <Input label="Location ID" maxLength={80} value={state.ghl.location_id} onChange={(event) => setField('ghl.location_id', event.target.value)} error={e('ghl.location_id')} helperText="Taken from the project brief when it has one." />
        <RepeatableList legend="What this ticket touches" items={state.ghl.refs} onChange={(next) => setField('ghl.refs', next)} max={30} addLabel="Add a target" blank={{ type: 'workflow', name: '', ghl_id: '', url: '' }} error={e('ghl.refs')}
          renderRow={(row, update, index) => (
            <>
              <FieldRow>
                <Select label="Type" value={row.type} onChange={(event) => update({ type: event.target.value })} error={e(`ghl.refs.${index}.type`)}>{REF_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</Select>
                <Input label="Name" maxLength={160} value={row.name} onChange={(event) => update({ name: event.target.value })} error={e(`ghl.refs.${index}.name`)} />
              </FieldRow>
              <FieldRow>
                <Input label="GoHighLevel ID" maxLength={80} value={row.ghl_id ?? ''} onChange={(event) => update({ ghl_id: event.target.value })} error={e(`ghl.refs.${index}.ghl_id`)} />
                <UrlInput label="Link" value={row.url ?? ''} onChange={(event) => update({ url: event.target.value })} error={e(`ghl.refs.${index}.url`)} />
              </FieldRow>
            </>
          )} />
      </Section>

      <Section id="inputs" title={SECTION_TITLES.inputs} errors={errors}>
        <ChipInput label="What the client must supply" value={state.inputs.client_supplies} onChange={(next) => setField('inputs.client_supplies', next)} max={30} error={e('inputs.client_supplies')} helperText="Copy, images, logins, approvals." />
        <ChipInput label="Asset links" value={state.inputs.asset_urls} onChange={(next) => setField('inputs.asset_urls', next)} max={20} maxLength={500} placeholder="https://… then Enter" error={e('inputs.asset_urls')} />
        <FieldRow>
          <YesNo id={`${uid}-ready`} label="Everything needed is ready" value={state.inputs.inputs_ready} onChange={(next) => setField('inputs.inputs_ready', next)} error={e('inputs.inputs_ready')} />
          <Input label="Access or permission needed" maxLength={500} value={state.inputs.access_needed} onChange={(event) => setField('inputs.access_needed', event.target.value)} error={e('inputs.access_needed')} helperText="Never a password. Say how it will be shared." />
        </FieldRow>
        {state.inputs.inputs_ready === 'false' || state.inputs.inputs_ready === false
          ? <ChipInput label="Still missing" value={state.inputs.missing} onChange={(next) => setField('inputs.missing', next)} max={30} error={e('inputs.missing')} />
          : null}
      </Section>

      <Section id="dependencies" title={SECTION_TITLES.dependencies} errors={errors}>
        {siblings.length > 0
          ? <MultiSelect legend="Depends on these tickets" options={siblings.map((item) => ({ value: item.id, label: `${item.key} ${item.title}` }))} value={state.dependencies.depends_on} onChange={(next) => setField('dependencies.depends_on', next)} error={e('dependencies.depends_on')} />
          : <p className="ui-field-help">No other tickets in this project yet.</p>}
        <Select id={`${uid}-waiting`} label="Waiting on" value={state.dependencies.waiting_on} onChange={(event) => setField('dependencies.waiting_on', event.target.value)} error={e('dependencies.waiting_on')}>
          <option value="">Nobody</option>
          {WAITING_ON.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
        </Select>
      </Section>

      <Section id="environment" title={SECTION_TITLES.environment} errors={errors}>
        <FieldRow columns={3}>
          <Select id={`${uid}-env`} label="Build in" value={state.environment.environment} onChange={(event) => setField('environment.environment', event.target.value)} error={e('environment.environment')}>
            <option value="">Not specified</option><option value="test">Test or draft</option><option value="live">Live</option>
          </Select>
          <YesNo id={`${uid}-live`} label="Affects live traffic" value={state.environment.affects_live_traffic} onChange={(next) => setField('environment.affects_live_traffic', next)} error={e('environment.affects_live_traffic')} />
          <YesNo id={`${uid}-approval`} label="Needs your approval before publishing" value={state.environment.requires_approval} onChange={(next) => setField('environment.requires_approval', next)} error={e('environment.requires_approval')} />
        </FieldRow>
        <Textarea id={`${uid}-rollback`} label="Rollback note" rows={2} maxLength={2000} value={state.environment.rollback_note} onChange={(event) => setField('environment.rollback_note', event.target.value)} error={e('environment.rollback_note')} />
      </Section>

      <Section id="testing" title={SECTION_TITLES.testing} errors={errors}>
        <Textarea id={`${uid}-steps`} label="Test steps" rows={3} maxLength={4000} value={state.testing.steps} onChange={(event) => setField('testing.steps', event.target.value)} error={e('testing.steps')} />
        <FieldRow>
          <Input label="Test contact or data" maxLength={1000} value={state.testing.test_data} onChange={(event) => setField('testing.test_data', event.target.value)} error={e('testing.test_data')} />
          <Input label="Devices and browsers" maxLength={300} value={state.testing.devices} onChange={(event) => setField('testing.devices', event.target.value)} error={e('testing.devices')} />
        </FieldRow>
        <Textarea id={`${uid}-expected`} label="Expected results" rows={2} maxLength={2000} value={state.testing.expected} onChange={(event) => setField('testing.expected', event.target.value)} error={e('testing.expected')} />
        <ChipInput label="QA checklist" value={state.testing.qa_checklist} onChange={(next) => setField('testing.qa_checklist', next)} max={30} maxLength={300} error={e('testing.qa_checklist')} />
      </Section>

      <Section id="checklist" title={SECTION_TITLES.checklist} errors={errors}>
        <ChecklistEditor items={state.checklist} onChange={(next) => setField('checklist', next)} errors={errors} />
      </Section>

      <Section id="notes" title={SECTION_TITLES.notes} errors={errors}>
        <Textarea id={`${uid}-notes`} label="Notes" rows={3} maxLength={5000} value={state.notes.client} onChange={(event) => setField('notes.client', event.target.value)} error={e('notes.client')} helperText="Visible to you, your team and the employee on this ticket." />
      </Section>
      {errors && Object.keys(errors).some((key) => key.startsWith(`${P}.`)) && <Alert variant="error">Some work order fields need attention. The sections with a problem are open.</Alert>}
    </fieldset>
  )
}
