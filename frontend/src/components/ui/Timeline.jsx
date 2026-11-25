import { fileIconName, formatFileSize } from '../../lib/files'
import { Icon } from './Icon'
import { PlainText } from './PlainText'
import { describeTimelineItem } from './timelineText'
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
  time_logged: 'clock',
  priority_changed: 'alert',
  reassigned: 'users',
  status_detail: 'lock',
  secret_flagged: 'shield',
  quote_sent: 'file',
  quote_decided: 'check',
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
                <span className="ui-timeline-summary">{describeTimelineItem(item, viewerRole)}</span>
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
