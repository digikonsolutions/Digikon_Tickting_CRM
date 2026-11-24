import { CopyButton, ProgressBadge } from '../../components/ui'
import { BRIEF_SECTIONS } from './briefSchema'
import { getIn } from './objectPath'
import { safeUrl } from '../briefs/briefData'
import './workorder.css'

const isBlank = (value) => value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)

function label(field, value) {
  if (field.type === 'bool') return value ? 'Yes' : 'No'
  if (field.type === 'select' || field.type === 'multi') {
    const names = (Array.isArray(value) ? value : [value]).map((item) => field.options.find((option) => option.value === item)?.label ?? item)
    return names.join(', ')
  }
  return value
}

export function LinkOrText({ value }) {
  const href = safeUrl(String(value))
  return href ? <a href={href} target="_blank" rel="noopener noreferrer">{value}</a> : <span>{value}</span>
}

function Value({ field, value }) {
  if (field.type === 'chips') return <ul className="brief-chips">{value.map((item) => <li key={item}>{item}</li>)}</ul>
  if (field.type === 'urls') return <ul>{value.map((item) => <li key={item}><LinkOrText value={item} /></li>)}</ul>
  if (field.type === 'url') return <LinkOrText value={value} />
  if (field.type === 'rows') {
    return (
      <ul>
        {value.map((row, index) => (
          <li key={index}>{field.sub.map((sub) => row[sub.path]).filter((cell) => !isBlank(cell)).map((cell) => (Array.isArray(cell) ? cell.join(', ') : cell)).join(' · ')}</li>
        ))}
      </ul>
    )
  }
  return <span className="brief-text">{label(field, value)}</span>
}

function Row({ field, value }) {
  if (isBlank(value)) return null
  return (
    <div className="brief-row">
      <dt>{field.label}</dt>
      <dd><Value field={field} value={value} />{field.copy && typeof value === 'string' && <CopyButton value={value} />}</dd>
    </div>
  )
}

// Read-only brief, grouped by section. Empty sections are hidden and a project without a brief renders nothing.
// `brief` is the API object (`ghl_brief`), including `completeness` and, for staff, `notes.internal`.
export function GhlBriefView({ brief, onlySections }) {
  if (!brief) return null
  const sections = BRIEF_SECTIONS.filter((section) => !onlySections || onlySections.includes(section.key))
  const shown = sections.map((section) => {
    if (section.rows) {
      const rows = brief[section.key] ?? []
      return { section, body: rows.length ? rows.map((row, index) => (
        <div className="brief-subgroup" key={index}>
          <h5>{row.name || `${section.rowTitle} ${index + 1}`}</h5>
          <dl>{section.fields.filter((field) => field.path !== 'name').map((field) => <Row key={field.path} field={field} value={row[field.path]} />)}</dl>
        </div>
      )) : null }
    }
    const rows = section.fields.map((field) => <Row key={field.path} field={field} value={getIn(brief[section.key], field.path)} />).filter((row) => row.props.value !== undefined && !isBlank(row.props.value))
    return { section, body: rows.length ? <dl>{rows}</dl> : null }
  }).filter((item) => item.body)
  const internal = brief.notes?.internal

  if (shown.length === 0 && !internal) return null
  return (
    <div className="brief-ghl-view">
      {brief.completeness && <p className="brief-ghl-progress"><ProgressBadge percent={brief.completeness.percent} label="Brief" />{brief.completeness.missing?.length > 0 && <span className="ui-field-help"> Missing: {brief.completeness.missing.slice(0, 4).join(', ')}{brief.completeness.missing.length > 4 ? '…' : ''}</span>}</p>}
      {shown.map(({ section, body }) => (
        <section key={section.key} className="brief-ghl-section" aria-label={section.title}>
          <h4>{section.title}</h4>
          {body}
        </section>
      ))}
      {internal && (
        <section className="brief-ghl-section brief-ghl-internal" aria-label="Internal notes">
          <h4>Internal notes <span className="ui-field-help">(staff only)</span></h4>
          <p className="brief-text">{internal}</p>
        </section>
      )}
    </div>
  )
}
