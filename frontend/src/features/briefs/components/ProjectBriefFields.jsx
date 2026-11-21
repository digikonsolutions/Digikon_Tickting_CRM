import { Input, Select, Textarea } from '../../../components/ui'
import { PLATFORMS, PRIORITIES } from '../briefData'

// Optional project brief. Collapsed by default so the quick path (title, hours, dates) stays short.
export function ProjectBriefFields({ errors = {}, defaults = {} }) {
  const error = (field) => errors?.[field]?.[0]
  const hasError = Object.keys(errors ?? {}).some((field) => field.split('.')[0] in PROJECT_BRIEF_FIELDS)

  return (
    <details className="brief-fields" open={hasError || undefined}>
      <summary>Project brief <span>(optional)</span></summary>
      <div className="brief-fields-grid">
        <Textarea name="objective" label="Objective" rows="3" maxLength="3000" defaultValue={defaults.objective ?? ''} error={error('objective')} helperText="What should this project achieve?" />
        <Textarea name="deliverables" label="Deliverables checklist" rows="4" defaultValue={(defaults.deliverables ?? []).join('\n')} error={error('deliverables') ?? error('deliverables.0')} helperText="One deliverable per line (up to 25)." />
        <Textarea name="acceptance_criteria" label="Acceptance criteria" rows="3" maxLength="3000" defaultValue={defaults.acceptance_criteria ?? ''} error={error('acceptance_criteria')} />
        <Select name="platform" label="Platform" defaultValue={defaults.platform ?? ''} error={error('platform')}>
          {PLATFORMS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
        <Input name="tech_stack" label="Tech stack" maxLength="255" defaultValue={defaults.tech_stack ?? ''} error={error('tech_stack')} />
        <Input name="target_url" label="Live site URL" type="url" maxLength="500" placeholder="https://" defaultValue={defaults.target_url ?? ''} error={error('target_url')} />
        <Input name="staging_url" label="Staging URL" type="url" maxLength="500" placeholder="https://" defaultValue={defaults.staging_url ?? ''} error={error('staging_url')} />
        <Input name="repo_url" label="Repository link" type="url" maxLength="500" placeholder="https://" defaultValue={defaults.repo_url ?? ''} error={error('repo_url')} />
        <Input name="brand_assets_url" label="Brand assets link" type="url" maxLength="500" placeholder="https://" defaultValue={defaults.brand_assets_url ?? ''} error={error('brand_assets_url')} />
        <Textarea name="reference_links" label="Reference links" rows="3" defaultValue={(defaults.reference_links ?? []).join('\n')} error={error('reference_links') ?? error('reference_links.0')} helperText="One web link per line (up to 10)." />
        <Textarea
          name="access_details"
          label="Login or access details"
          rows="3"
          maxLength="3000"
          autoComplete="off"
          defaultValue={defaults.access_details ?? ''}
          error={error('access_details')}
          helperText="Stored encrypted. Visible only to your agency, the assigned employee and administrators."
        />
        <Input name="budget" label="Budget" maxLength="100" defaultValue={defaults.budget ?? ''} error={error('budget')} />
        <Select name="brief_priority" label="Project priority" defaultValue={defaults.brief_priority ?? ''} error={error('brief_priority')}>
          <option value="">Not specified</option>
          {PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority}</option>)}
        </Select>
        <Input name="contact_name" label="Contact person" maxLength="120" defaultValue={defaults.contact_name ?? ''} error={error('contact_name')} />
        <Input name="contact_email" label="Contact email" type="email" maxLength="254" defaultValue={defaults.contact_email ?? ''} error={error('contact_email')} />
      </div>
    </details>
  )
}

const PROJECT_BRIEF_FIELDS = Object.fromEntries([
  'objective', 'deliverables', 'target_url', 'staging_url', 'repo_url', 'platform', 'tech_stack', 'brand_assets_url',
  'access_details', 'reference_links', 'acceptance_criteria', 'budget', 'brief_priority', 'contact_name', 'contact_email',
].map((field) => [field, true]))
