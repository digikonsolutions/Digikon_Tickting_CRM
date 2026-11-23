import { Link } from 'react-router-dom'
import { Badge, DropdownMenu } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import '../../dashboard/pages/roles/admin/adminDashboard.css'

const dash = <span className="adm-muted" aria-label="Not set">—</span>

// Hours this week (a plain function, not a component, so this file only exports helpers): a mini bar against the included hours when the agency has a contract, plain hours otherwise.
function hoursCell(row) {
  const used = Number(row.hours_booked_this_week) || 0
  if (!row.hours_included) return <span className="adm-num adm-nowrap">{used.toFixed(1)} h <span className="adm-muted">this week</span></span>
  const percent = Math.min(100, Math.round((used / row.hours_included) * 100))
  return (
    <span className="adm-hours-cell">
      <span className="adm-num">{used.toFixed(1)} / {row.hours_included} h</span>
      <span className={`adm-minibar${percent >= 100 ? ' is-hot' : percent >= 80 ? ' is-warm' : ''}`} role="progressbar" aria-label={`${row.name} hours: ${used} of ${row.hours_included}`} aria-valuemin="0" aria-valuemax={row.hours_included} aria-valuenow={used}>
        <span style={{ width: `${percent}%` }} />
      </span>
    </span>
  )
}

export function agencyMenuItems(row, onToggle, onEdit) {
  const base = `/admin/agencies/${row.id}`
  const suspending = row.status === 'active'
  const draft = row.status === 'draft'
  return [
    { label: 'View details', to: base },
    ...(onEdit ? [{ label: 'Edit agency', onClick: () => onEdit(row) }] : []),
    { label: 'Contract & plan', to: `${base}?tab=contract` },
    { label: 'Hours & usage', to: `${base}?tab=usage` },
    { label: 'Employees held', to: `${base}?tab=employees` },
    { label: 'Projects', to: `${base}?tab=projects` },
    row.slug && row.status === 'active'
      ? { label: 'Open portal', href: `/a/${row.slug}/login`, external: true }
      : { label: 'Open portal', disabled: true, title: 'The portal is available once the agency is active.', onClick: () => {} },
    row.owner_id
      ? { label: 'Agency owner profile', to: `/admin/agency-owners?search=${encodeURIComponent(row.owner_email ?? row.owner_name ?? '')}` }
      : { label: 'Agency owner profile', disabled: true, title: 'This agency has no owner yet', onClick: () => {} },
    { label: draft ? 'Activate & send invite' : suspending ? 'Suspend agency' : 'Activate agency', danger: suspending, separator: true, onClick: () => onToggle(row) },
  ]
}

/** Columns for the agencies table on the dashboard and on the Agencies page. */
export function agencyColumns({ onToggle, onEdit }) {
  return [
    {
      key: 'name',
      label: 'Agency',
      render: (_value, row) => (
        <span className="adm-agency-name">
          <Link to={`/admin/agencies/${row.id}`}><strong>{row.name}</strong></Link>
          <small>{row.owner_name ? `Owner: ${row.owner_name}` : 'No owner'}</small>
        </span>
      ),
    },
    { key: 'status', label: 'Status', render: (value) => <Badge variant={value === 'active' ? 'success' : value === 'draft' ? 'slate' : 'warning'}>{value === 'active' ? 'Active' : value === 'draft' ? 'Draft' : 'Suspended'}</Badge> },
    { key: 'plan', label: 'Plan', render: (_value, row) => (row.plan ? <Badge variant="info">{row.plan}</Badge> : <span className="adm-muted adm-nowrap">No contract</span>) },
    {
      key: 'seats',
      label: 'Seats',
      render: (_value, row) => <span className="adm-num" title="Employees held / seats allowed">{row.employees_held} / {row.seats_allowed ?? dash}</span>,
    },
    { key: 'hours', label: 'Hours', render: (_value, row) => hoursCell(row) },
    { key: 'renewal', label: 'Renewal', render: (_value, row) => (row.renewal_date ? formatDate(row.renewal_date) : dash) },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value, row) => <DropdownMenu label={`Actions for ${row.name}`} items={agencyMenuItems(row, onToggle, onEdit)} />,
    },
  ]
}
