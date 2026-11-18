import { Link } from 'react-router-dom'

const BAND_LABELS = { low: 'Under 50%', healthy: 'Healthy', high: 'Over 90%' }

function StatsBlock({ block }) {
  return (
    <dl className="as-stats">
      {block.items.map((item) => (
        <div key={item.label} className={`as-stat${item.tone ? ` is-${item.tone}` : ''}`}>
          <dt>{item.label}</dt>
          <dd>{item.value}</dd>
          {item.hint && <span className="as-stat-hint">{item.hint}</span>}
        </div>
      ))}
    </dl>
  )
}

function Row({ item, onNavigate }) {
  const content = (
    <>
      <span className="as-row-main">
        <strong>{item.title}</strong>
        {item.subtitle && <span>{item.subtitle}</span>}
      </span>
      {item.meta && <span className={`as-row-meta${item.tone ? ` is-${item.tone}` : ''}`}>{item.meta}</span>}
    </>
  )
  return item.href
    ? <Link className="as-row is-link" to={item.href} onClick={onNavigate}>{content}</Link>
    : <div className="as-row">{content}</div>
}

function ListBlock({ block, onNavigate }) {
  if (!block.items.length) return block.empty ? <p className="as-muted">{block.empty}</p> : null
  return (
    <ul className="as-list">
      {block.items.map((item, index) => <li key={`${item.title}-${index}`}><Row item={item} onNavigate={onNavigate} /></li>)}
    </ul>
  )
}

function ProgressBlock({ block }) {
  if (!block.items.length) return block.empty ? <p className="as-muted">{block.empty}</p> : null
  return (
    <ul className="as-progress-list">
      {block.items.map((item) => {
        const percent = item.percent ?? 0
        return (
          <li key={item.label} className={`as-progress is-${item.band ?? 'healthy'}`}>
            <div className="as-progress-head">
              <strong>{item.label}</strong>
              <span>{item.used} h of {item.capacity} h · {item.percent === null ? '—' : `${percent}%`}</span>
            </div>
            <div
              className="as-progress-track"
              role="meter"
              aria-label={`${item.label}: ${item.used} of ${item.capacity} hours scheduled`}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.min(100, Math.round(percent))}
              aria-valuetext={`${percent}%, ${BAND_LABELS[item.band] ?? ''}`}
            >
              <span style={{ '--as-fill': `${Math.min(100, percent)}%` }} />
            </div>
            {item.band && item.band !== 'healthy' && <span className="as-band">{BAND_LABELS[item.band]}</span>}
          </li>
        )
      })}
    </ul>
  )
}

function TableBlock({ block }) {
  if (!block.rows.length) return block.empty ? <p className="as-muted">{block.empty}</p> : null
  return (
    <div className="as-table-wrap" tabIndex={0} role="region" aria-label={block.title}>
      <table className="as-table">
        <thead><tr>{block.columns.map((column) => <th key={column} scope="col">{column}</th>)}</tr></thead>
        <tbody>
          {block.rows.map((row, r) => (
            <tr key={r}>{row.map((cell, c) => (c === 0 ? <th key={c} scope="row">{cell}</th> : <td key={c}>{cell}</td>))}</tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Renders the typed blocks an assistant answer is made of. */
export function AnswerBlocks({ answer, onNavigate }) {
  const hasContent = answer.blocks.some((block) =>
    block.type === 'text' || block.type === 'stats' || (block.items ?? block.rows ?? []).length > 0)

  return (
    <div className="as-answer-body">
      {answer.blocks.map((block, index) => (
        <section key={`${block.type}-${index}`} className="as-block">
          {block.title && block.type !== 'text' && <h4 className="as-block-title">{block.title}</h4>}
          {block.type === 'stats' && <StatsBlock block={block} />}
          {block.type === 'list' && <ListBlock block={block} onNavigate={onNavigate} />}
          {block.type === 'progress' && <ProgressBlock block={block} />}
          {block.type === 'table' && <TableBlock block={block} />}
          {block.type === 'text' && <p className={`as-text is-${block.tone ?? 'info'}`}>{block.text}</p>}
        </section>
      ))}
      {answer.empty_message && (!hasContent || answer.blocks.length === 0) && <p className="as-muted">{answer.empty_message}</p>}
    </div>
  )
}
