import { Card } from '../../components/ui'
import { StarRating } from './Stars'

// The headline numbers of an employee's feedback: the average, how many people would work with them again, the spread
// of ratings and the five categories.
export function FeedbackSummary({ summary, title = 'Overall' }) {
  if (!summary || summary.count === 0) return null
  const max = Math.max(1, ...summary.distribution.map((row) => row.count))
  return (
    <Card className="fb-summary">
      <div className="fb-summary-head">
        <div className="fb-score">
          <span className="fb-score-number">{summary.average.toFixed(1)}</span>
          <StarRating value={summary.average} size="lg" showNumber={false} />
          <small>{title} · {summary.count} {summary.count === 1 ? 'review' : 'reviews'}</small>
        </div>
        <div className="fb-again" aria-label={`${summary.would_again_percent} percent would work with them again`}>
          <strong>{summary.would_again_percent}%</strong>
          <small>would work with them again</small>
          <div className="fb-again-bar" aria-hidden="true">
            <i className="is-yes" style={{ flex: summary.would_again.yes }} /><i className="is-maybe" style={{ flex: summary.would_again.maybe }} /><i className="is-no" style={{ flex: summary.would_again.no }} />
          </div>
        </div>
        <ul className="fb-distribution" aria-label="Ratings given">
          {summary.distribution.map((row) => (
            <li key={row.stars}><span>{row.stars}★</span><span className="fb-meter" aria-hidden="true"><i style={{ width: `${(row.count / max) * 100}%` }} /></span><span className="fb-count">{row.count}</span></li>
          ))}
        </ul>
      </div>
      <ul className="fb-categories">
        {summary.categories.map((category) => (
          <li key={category.key}>
            <span>{category.label}</span>
            <span className="fb-meter fb-meter-lg" aria-hidden="true"><i style={{ width: `${((category.average ?? 0) / 5) * 100}%` }} /></span>
            <strong>{category.average != null ? category.average.toFixed(1) : '—'}</strong>
          </li>
        ))}
      </ul>
    </Card>
  )
}
