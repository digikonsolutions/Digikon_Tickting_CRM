import { useCallback, useState } from 'react'
import { Card, PageHeader, Table } from '../../../components/ui'
import { formatHours } from '../../../lib/format'
import { TimesheetTab } from '../../time'
import { useWorkspace } from '../useWorkspace'
import { projectTotals } from '../workspace'

// /my-work/time: the existing timesheet (per day and per ticket), plus the same week per project.
export function TimePage() {
  const { workspace } = useWorkspace()
  const [week, setWeek] = useState(null)
  const onLoaded = useCallback((data) => setWeek(data), [])
  const totals = projectTotals(week, workspace)

  return (
    <section className="page ew-page">
      <PageHeader eyebrow="Employee" title="Time" subtitle="What you logged against what was planned." />
      <Card title="Timesheet"><TimesheetTab refreshKey={workspace} onLoaded={onLoaded} /></Card>
      <Card title="By project" description="The same week, per project.">
        <Table
          columns={[
            { key: 'title', label: 'Project' },
            { key: 'logged', label: 'Logged', render: (value) => formatHours(value) },
            { key: 'scheduled', label: 'Planned', render: (value) => formatHours(value) },
            { key: 'difference', label: 'Difference', render: (_value, entry) => { const diff = Math.round((entry.logged - entry.scheduled) * 100) / 100; return `${diff > 0 ? '+' : ''}${formatHours(diff)}` } },
          ]}
          rows={totals}
          loading={!week}
          emptyTitle="Nothing this week"
          emptyText="No time logged or planned in this week."
        />
      </Card>
    </section>
  )
}
