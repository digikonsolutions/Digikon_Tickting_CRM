import { Button, FieldRow, ProgressBadge, RepeatableList } from '../../components/ui'
import { BRIEF_SECTIONS } from './briefSchema'
import { FieldInput } from './FieldInput'
import { hasErrorUnder } from './objectPath'
import './workorder.css'

// The detailed GoHighLevel brief as an accordion, every section collapsed until opened (a section that holds a
// server error opens by itself). `brief` is the object from useProjectBrief; `errors` is a 422's `errors`.
export function BriefForm({ brief, errors, idPrefix = 'brief' }) {
  const { values, setField, percent } = brief
  return (
    <div className="brief-form">
      <div className="brief-form-head">
        <p className="ui-field-help">Everything here is optional. Give it once and your tickets start from it, so the team asks you less.</p>
        <ProgressBadge percent={percent} label="Brief" />
      </div>
      <p className="brief-form-warning ui-field-help">Never type passwords or API keys. Say how you will share access instead, for example &ldquo;password manager invite&rdquo;.</p>
      {BRIEF_SECTIONS.map((section, index) => (
        <details key={section.key} className="brief-section" open={hasErrorUnder(errors, `ghl_brief.${section.key}`) || undefined}>
          <summary>{index + 1}. {section.title}</summary>
          <div className="brief-section-body">
            {section.rows ? (
              <RepeatableList
                items={values[section.key]}
                onChange={(rows) => setField(section.key, null, rows)}
                max={section.maxRows}
                blank={Object.fromEntries(section.fields.map((field) => [field.path, field.type === 'chips' ? [] : '']))}
                addLabel={section.addLabel}
                error={errors?.[`ghl_brief.${section.key}`]?.[0]}
                renderRow={(row, update, rowIndex) => (
                  <>
                    <FieldRow>
                      {section.fields.filter((field) => ['text', 'textarea'].includes(field.type) && field.path !== 'value_rules').map((field) => (
                        <FieldInput key={field.path} field={field} value={row[field.path]} onChange={(next) => update({ [field.path]: next })} errors={errors} errorPrefix={`ghl_brief.${section.key}.${rowIndex}`} idPrefix={`${idPrefix}-${section.key}-${rowIndex}`} />
                      ))}
                    </FieldRow>
                    {section.fields.filter((field) => !['text'].includes(field.type)).map((field) => (
                      <FieldInput key={field.path} field={field} value={row[field.path]} onChange={(next) => update({ [field.path]: next })} errors={errors} errorPrefix={`ghl_brief.${section.key}.${rowIndex}`} idPrefix={`${idPrefix}-${section.key}-${rowIndex}`} />
                    ))}
                  </>
                )}
              />
            ) : (
              <div className="brief-fields">
                {section.fields.map((field) => (
                  <FieldInput key={field.path} field={field} value={field.path.split('.').reduce((value, key) => value?.[key], values[section.key])} onChange={(next) => setField(section.key, field.path, next)} errors={errors} errorPrefix={`ghl_brief.${section.key}`} idPrefix={`${idPrefix}-${section.key}`} />
                ))}
              </div>
            )}
            {section.key === 'ghl' && <p className="ui-field-help">Tip: the Location ID is in GoHighLevel under Settings &rarr; Business Profile.</p>}
          </div>
        </details>
      ))}
      <Button type="button" variant="ghost" size="sm" onClick={() => document.querySelectorAll('.brief-section[open]').forEach((node) => node.removeAttribute('open'))}>Collapse all</Button>
    </div>
  )
}
