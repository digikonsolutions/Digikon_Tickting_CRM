import { getTicketStatusLabel } from '../../lib/ticketStatus'
import { fileIconName, formatFileSize } from '../../lib/files'
import { Icon } from './Icon'
import { PlainText } from './PlainText'
import { RelativeTime } from './RelativeTime'

const ICONS = {
  created: 'plus',
  updated: 'pencil',
  status_changed: 'arrow',
  rescheduled: 'clock',
  reordered: 'swap',
  cancelled: 'close',
  comment: 'chat',
  attachment_added: 'paperclip',
  attachment_removed: 'trash',
}

const CANCEL_CAUSES = {
  ticket: '',
  project: ' because the project was cancelled',
  assignment_ended: ' because the specialist was released',
  employee_deactivated: ' because the specialist is no longer available',
}

function describe(item, viewerRole) {
  const actor = item.actor?.name ?? 'System'
  const payload = item.payload ?? {}

  switch (item.type) {
    case 'created':
      return `${actor} created this ticket`
    case 'status_changed':
      return `${actor} moved this to ${getTicketStatusLabel(payload.to, viewerRole)}`
    case 'rescheduled':
      return payload.reason ?? 'Schedule recalculated'
    case 'reordered':
      return `${actor} changed this ticket's priority order`
    case 'cancelled':
      return `${actor} cancelled this ticket${CANCEL_CAUSES[payload.cause] ?? ''}`
    case 'updated': {
      const fields = Object.keys(payload.changes ?? {}).map((field) => field.replace(/_/g, ' '))
      return `${actor} updated ${fields.join(', ') || 'this ticket'}`
    }
    case 'attachment_added': {
      const count = (payload.files ?? []).length
      return `${actor} attached ${count} file${count === 1 ? '' : 's'}${item.visibility === 'internal' ? ' (internal)' : ''}`
    }
    case 'attachment_removed':
      return `${actor} removed ${payload.name ?? 'a file'}`
    case 'comment':
      return item.visibility === 'internal' ? `${actor} added an internal note` : `${actor} commented`
    default:
      return `${actor} made changes`
  }
}

// Vertical activity list. Each item is `{id, type, actor:{name, role}, created_at, payload, comment?, visibility?}`.
// `children` renders after the last item (for example a "Load more" button).
// Files shown under a timeline entry: thumbnails for images, a labelled link for everything else.
function AttachmentRow({ files, attachmentUrl }) {
  if (!files.length || !attachmentUrl) return null
  return (
    <ul className="ui-timeline-files" aria-label="Attached files">
      {files.map((file) => (
        <li key={file.id}>
          <a href={attachmentUrl(file.id)} className="ui-timeline-file" title={`Download ${file.name}`}>
            {file.is_image
              ? <img src={attachmentUrl(file.id, { inline: true })} alt="" loading="lazy" />
              : <Icon name={fileIconName(file.name, file.mime_type)} size={16} />}
            <span>{file.name}</span>
            <small>{formatFileSize(file.size_bytes)}</small>
          </a>
        </li>
      ))}
    </ul>
  )
}

export function Timeline({ items = [], viewerRole = null, attachmentUrl = null, children = null, className = '' }) {
  // Uploads that came with a comment are shown under that comment, so their own entry is skipped.
  const visible = items.filter((item) => !(item.type === 'attachment_added' && item.payload?.with_comment))
  if (!visible.length) {
    return <p className="ui-timeline-empty">No activity recorded yet.</p>
  }

  return (
    <ol className={`ui-timeline ${className}`.trim()} aria-label="Activity timeline">
      {visible.map((item) => {
        const isInternal = item.visibility === 'internal'
        const changes = item.type === 'updated' ? Object.entries(item.payload?.changes ?? {}) : []

        return (
          <li key={item.id} className={`ui-timeline-item ui-timeline-item-${item.type}${isInternal ? ' is-internal' : ''}`}>
            <span className="ui-timeline-icon" aria-hidden="true"><Icon name={ICONS[item.type] ?? 'info'} size={14} /></span>
            <div className="ui-timeline-content">
              <div className="ui-timeline-header">
                <span className="ui-timeline-summary">{describe(item, viewerRole)}</span>
                <RelativeTime date={item.created_at} className="ui-timeline-time" />
              </div>
              {isInternal && <span className="ui-timeline-internal-tag">Internal, not visible to the client</span>}
              {item.comment && (
                <div className="ui-timeline-body"><PlainText text={item.comment} /></div>
              )}
              {item.type === 'attachment_added' && !item.payload?.with_comment && (
                <AttachmentRow attachmentUrl={attachmentUrl} files={(item.payload?.files ?? []).map((file) => ({ ...file, is_image: ['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.mime_type) }))} />
              )}
              <AttachmentRow attachmentUrl={attachmentUrl} files={item.attachments ?? []} />
              {changes.length > 0 && (
                <ul className="ui-timeline-changes">
                  {changes.map(([field, { from, to }]) => (
                    <li key={field}><strong>{field.replace(/_/g, ' ')}:</strong> {String(from ?? '—')} → {String(to ?? '—')}</li>
                  ))}
                </ul>
              )}
            </div>
          </li>
        )
      })}
      {children && <li className="ui-timeline-more">{children}</li>}
    </ol>
  )
}
