import { useCallback, useEffect, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, EmptyState, Icon, Modal, RelativeTime, Skeleton, useToast } from '../../../components/ui'
import { checkFiles, DEFAULT_LIMITS, fileIconName, formatFileSize } from '../../../lib/files'

// Files tab: drag-and-drop zone, picker, per-file upload progress and errors, and the list of files
// with thumbnails for images, download and (confirmed) delete. Each file is its own upload request so
// progress and errors are per file. `readOnly` blocks uploads (done or cancelled tickets).
export function FilesTab({ ticket, onChanged, readOnly = false }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.listTicketAttachments(ticket.id), [ticket.id])
  const { data, error, isLoading, reload } = useAsync(load)
  const [queue, setQueue] = useState([])
  const [isDragging, setIsDragging] = useState(false)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState(null)
  const picker = useRef(null)
  const nextId = useRef(0)
  const attachments = data?.attachments ?? []
  const limits = data?.limits ?? DEFAULT_LIMITS

  // Uploads that are still running are aborted if the tab is left.
  const controllers = useRef(new Set())
  useEffect(() => {
    const running = controllers.current
    return () => running.forEach((controller) => controller.abort())
  }, [])

  const patchQueue = (id, patch) => setQueue((current) => current.map((item) => (item.id === id ? { ...item, ...patch } : item)))

  async function uploadOne(item) {
    const controller = new AbortController()
    controllers.current.add(controller)
    patchQueue(item.id, { status: 'uploading', progress: 0, error: null })
    try {
      await authService.uploadTicketAttachments(ticket.id, {
        files: [item.file],
        signal: controller.signal,
        onProgress: (progress) => patchQueue(item.id, { progress }),
      })
      patchQueue(item.id, { status: 'done', progress: 100 })
      return true
    } catch (reason) {
      const message = Object.values(reason.errors ?? {}).flat()[0] ?? reason.message
      patchQueue(item.id, { status: 'error', error: message })
      return false
    } finally {
      controllers.current.delete(controller)
    }
  }

  async function addFiles(incoming) {
    const files = [...incoming]
    if (!files.length || readOnly) return
    const { accepted, rejected } = checkFiles(files, limits)
    const entries = [
      ...accepted.map((file) => ({ id: ++nextId.current, file, status: 'queued', progress: 0, error: null })),
      ...rejected.map(({ file, error: message }) => ({ id: ++nextId.current, file, status: 'error', progress: 0, error: message })),
    ]
    setQueue((current) => [...current.filter((item) => item.status !== 'done'), ...entries])
    let uploaded = 0
    for (const entry of entries.filter((item) => item.status === 'queued')) {
      if (await uploadOne(entry)) uploaded += 1
    }
    if (uploaded > 0) {
      showToast(`${uploaded} file${uploaded === 1 ? '' : 's'} uploaded.`)
      await reload()
      onChanged?.()
    }
  }

  async function confirmDelete() {
    setIsDeleting(true)
    setDeleteError(null)
    try {
      await authService.deleteAttachment(pendingDelete.id)
      setPendingDelete(null)
      showToast('File removed.')
      await reload()
      onChanged?.()
    } catch (reason) {
      setDeleteError(reason)
    } finally {
      setIsDeleting(false)
    }
  }

  return (
    <div className="files-tab">
      {!readOnly && (
        <div
          className={`files-dropzone${isDragging ? ' is-dragging' : ''}`}
          onDragOver={(event) => { event.preventDefault(); setIsDragging(true) }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={(event) => { event.preventDefault(); setIsDragging(false); addFiles(event.dataTransfer.files) }}
        >
          <Icon name="paperclip" size={20} />
          <p>Drag files here, or</p>
          <input ref={picker} type="file" multiple hidden accept={limits.extensions.map((extension) => `.${extension}`).join(',')} onChange={(event) => { addFiles(event.target.files); event.target.value = '' }} />
          <Button type="button" variant="secondary" size="sm" onClick={() => picker.current?.click()}>Choose files</Button>
          <small>Up to {Math.round(limits.max_file_kb / 1024)} MB each. Allowed: {limits.extensions.join(', ')}.</small>
        </div>
      )}
      {readOnly && <Alert variant="info">Files cannot be added to a done or cancelled ticket. Existing files can still be downloaded.</Alert>}

      {queue.length > 0 && (
        <ul className="files-queue" aria-label="Uploads">
          {queue.map((item) => (
            <li key={item.id} className={`files-queue-item is-${item.status}`}>
              <span className="files-queue-name">{item.file.name} <small>{formatFileSize(item.file.size)}</small></span>
              {item.status === 'uploading' && <progress value={item.progress} max="100" aria-label={`Uploading ${item.file.name}`} />}
              {item.status === 'done' && <span className="files-queue-state">Uploaded</span>}
              {item.status === 'queued' && <span className="files-queue-state">Waiting…</span>}
              {item.status === 'error' && (
                <span className="files-queue-error" role="alert">
                  {item.error}
                  <button type="button" onClick={() => setQueue((current) => current.filter((entry) => entry.id !== item.id))}>Dismiss</button>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {error && <Alert variant="error">{error.message} <Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></Alert>}
      {isLoading && !data
        ? <div role="status" aria-label="Loading files"><Skeleton variant="row" /><Skeleton variant="row" /></div>
        : attachments.length === 0
          ? <EmptyState title="No files yet">Screenshots, briefs and documents added to this ticket will show up here.</EmptyState>
          : (
            <ul className="files-list" aria-label="Files">
              {attachments.map((file) => (
                <li className="files-item" key={file.id}>
                  <a className="files-thumb" href={authService.attachmentUrl(file.id)} aria-label={`Download ${file.name}`}>
                    {file.is_image
                      ? <img src={authService.attachmentUrl(file.id, { inline: true })} alt="" loading="lazy" />
                      : <Icon name={fileIconName(file.name, file.mime_type)} size={26} />}
                  </a>
                  <div className="files-meta">
                    <strong>{file.name}</strong>
                    <span>
                      {formatFileSize(file.size_bytes)} · {file.uploader.name} · <RelativeTime date={file.created_at} />
                      {file.is_internal && <em className="files-internal"> Internal</em>}
                    </span>
                  </div>
                  <div className="files-actions">
                    <a className="ui-button ui-button-secondary ui-button-sm" href={authService.attachmentUrl(file.id)}><Icon name="download" size={15} /> Download</a>
                    {file.can_delete && (
                      <Button type="button" variant="ghost" size="sm" aria-label={`Delete ${file.name}`} onClick={() => { setDeleteError(null); setPendingDelete(file) }}>
                        <Icon name="trash" size={15} />
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}

      {pendingDelete && (
        <Modal
          open
          onClose={() => !isDeleting && setPendingDelete(null)}
          title="Delete this file?"
          footer={(
            <>
              <Button type="button" variant="secondary" disabled={isDeleting} onClick={() => setPendingDelete(null)}>Keep file</Button>
              <Button type="button" variant="danger" loading={isDeleting} loadingText="Deleting…" onClick={confirmDelete}>Delete file</Button>
            </>
          )}
        >
          {deleteError && <Alert variant="error">{deleteError.message}</Alert>}
          <p><strong>{pendingDelete.name}</strong> will no longer be available to anyone on this ticket.</p>
        </Modal>
      )}
    </div>
  )
}
