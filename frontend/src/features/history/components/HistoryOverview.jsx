import { Card, EmptyState, KpiCard, Skeleton } from '../../../components/ui'
import { formatShortDate } from '../../../lib/format'
import { formatDuration } from '../historyData'
import { SiteIcon } from './SiteIcon'

// Overview cards, most visited websites and a daily visits chart. Numbers come from the server for the
// current filters, so they cover the whole range, not just the page of events on screen.
export function HistoryOverview({ summary, isLoading }) {
  if (isLoading && !summary) {
    return <div className="history-kpis"><Skeleton variant="card" /><Skeleton variant="card" /><Skeleton variant="card" /><Skeleton variant="card" /></div>
  }
  if (!summary) return null
  const { totals, top_domains: top, daily } = summary
  const maxVisits = Math.max(1, ...daily.map((day) => day.visits))
  const maxTop = Math.max(1, ...top.map((site) => site.visits))

  return (
    <>
      <div className="history-kpis">
        <KpiCard hero icon="info" title="Total visits" value={totals.visits} subtext="Pages opened or visited" />
        <KpiCard icon="briefcase" title="Websites" value={totals.unique_domains} subtext="Unique domains" tone="teal" />
        <KpiCard icon="plus" title="Tabs opened" value={totals.tabs_opened} tone="teal" />
        <KpiCard icon="clock" title="Browsing time" value={formatDuration(totals.seconds)} subtext="Where measurable" tone="teal" />
      </div>

      <div className="history-panels">
        <Card title="Most visited websites" description="Visits and time spent per website.">
          {top.length === 0
            ? <EmptyState title="No websites yet">Nothing was recorded for these filters.</EmptyState>
            : (
              <ol className="history-top">
                {top.map((site) => (
                  <li key={site.domain}>
                    <SiteIcon domain={site.domain} size="sm" />
                    <div className="history-top-main">
                      <div className="history-top-row">
                        <strong>{site.domain}</strong>
                        <span>{site.visits} visit{site.visits === 1 ? '' : 's'} · {formatDuration(site.seconds)}</span>
                      </div>
                      <span className="history-bar"><span style={{ width: `${(site.visits / maxTop) * 100}%` }} /></span>
                    </div>
                  </li>
                ))}
              </ol>
            )}
        </Card>

        <Card title="Daily browsing activity" description="Visits per day in this range.">
          {daily.length === 0
            ? <EmptyState title="No activity">Nothing was recorded for these filters.</EmptyState>
            : (
              <div className="history-daily" role="img" aria-label={`Visits per day: ${daily.map((day) => `${formatShortDate(day.date)} ${day.visits}`).join(', ')}`}>
                {daily.map((day) => (
                  <div className="history-daily-col" key={day.date} title={`${formatShortDate(day.date)}: ${day.visits} visits, ${formatDuration(day.seconds)}`}>
                    <span className="history-daily-bar" style={{ height: `${Math.max(4, (day.visits / maxVisits) * 100)}%` }} />
                    <span className="history-daily-label">{formatShortDate(day.date)}</span>
                  </div>
                ))}
              </div>
            )}
        </Card>
      </div>
    </>
  )
}
