import { useEffect, useMemo, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Badge, Button, FieldRow, Input, Modal, Select, useToast } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { formatDate } from '../../../lib/format'
import { DOCUMENT_RULES, DOCUMENT_TYPES, formatBytes, labelOf } from './employeeForm'

// Client-side pre-check so an obviously wrong file never travels. The server re-checks the real content type.
function checkFile(file) {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!DOCUMENT_RULES.extensions.includes(extension)) return `${file.name}: only PDF, JPG and PNG files are allowed.`
  if (file.size === 0) return `${file.name} is empty.`
  if (file.size > DOCUMENT_RULES.maxBytes) return `${file.name} is larger than 5 MB.`
  return null
}

function DropZone({ onFiles, disabled }) {
  const inputRef = useRef(null)
  const [dragging, setDragging] = useState(false)

  function pick(list) {
    const files = Array.from(list ?? [])
    if (files.length) onFiles(files)
  }

  return (
    <div
      className={`emp-dropzone${dragging ? ' is-dragging' : ''}${disabled ? ' is-disabled' : ''}`}
      role="button"
      tabIndex={disabled ? -1 : 0}
      aria-disabled={disabled || undefined}
      aria-label="Upload documents. Drag files here or press Enter to choose files."
      onClick={() => !disabled && inputRef.current?.click()}
      onKeyDown={(event) => { if (!disabled && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); inputRef.current?.click() } }}
      onDragOver={(event) => { event.preventDefault(); if (!disabled) setDragging(true) }}
      onDragLeave={() => setDragging(false)}
      onDrop={(event) => { event.preventDefault(); setDragging(false); if (!disabled) pick(event.dataTransfer.files) }}
    >
      <Icon name="paperclip" size={22} />
      <strong>Drag files here or choose files</strong>
      <span>PDF, JPG or PNG, up to 5 MB each</span>
      <input ref={inputRef} type="file" hidden multiple accept={DOCUMENT_RULES.accept} onChange={(event) => { pick(event.target.files); event.target.value = '' }} />
    </div>
  )
}

function Preview({ file }) {
  const url = useMemo(() => (file.type.startsWith('image/') ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (url) URL.revokeObjectURL(url) }, [url])
  return url
    ? <img className="emp-thumb" src={url} alt={`Preview of ${file.name}`} />
    : <span className="emp-thumb emp-thumb-file" aria-hidden="true"><Icon name="file" size={20} /></span>
}

function expiryBadge(document) {
  if (document.expired) return <Badge variant="danger">Expired {formatDate(document.expires_at)}</Badge>
  if (document.expiring_soon) return <Badge variant="warning">Expires {formatDate(document.expires_at)}</Badge>
  if (document.expires_at) return <Badge variant="slate">Expires {formatDate(document.expires_at)}</Badge>
  return null
}

// Create mode: files wait in `queue` (owned by the page) and upload right after the account exists.
export function QueuedDocuments({ queue, onChange }) {
  const [type, setType] = useState('cv')
  const [expiresAt, setExpiresAt] = useState('')
  const [notice, setNotice] = useState(null)

  function addFiles(files) {
    const problems = []
    const accepted = []
    for (const file of files) {
      const problem = checkFile(file)
      if (problem) problems.push(problem)
      else accepted.push({ id: `${file.name}-${file.size}-${crypto.randomUUID()}`, file, type, expires_at: expiresAt })
    }
    setNotice(problems.length ? problems.join(' ') : null)
    if (accepted.length) onChange([...queue, ...accepted])
  }

  return (
    <div className="emp-docs">
      <Alert variant="info">Documents are stored privately and are visible to administrators only. They upload as soon as the account is created.</Alert>
      <FieldRow>
        <Select id="doc-type" label="Type for new files" value={type} onChange={(event) => setType(event.target.value)}>
          {DOCUMENT_TYPES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
        <Input id="doc-expiry" label="Expiry date (optional)" type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
      </FieldRow>
      <DropZone onFiles={addFiles} />
      {notice && <p className="ui-field-error" role="alert">{notice}</p>}
      {queue.length > 0 && (
        <ul className="emp-doc-list" aria-label="Files waiting to upload">
          {queue.map((item) => (
            <li className="emp-doc" key={item.id}>
              <Preview file={item.file} />
              <div className="emp-doc-main">
                <strong>{item.file.name}</strong>
                <span>{formatBytes(item.file.size)}</span>
              </div>
              <Select aria-label={`Type for ${item.file.name}`} value={item.type} onChange={(event) => onChange(queue.map((q) => (q.id === item.id ? { ...q, type: event.target.value } : q)))}>
                {DOCUMENT_TYPES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </Select>
              <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${item.file.name}`} onClick={() => onChange(queue.filter((q) => q.id !== item.id))}>
                <Icon name="trash" size={16} />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

// Edit mode: uploads start immediately and show progress; saved documents can be verified, downloaded and removed.
export function SavedDocuments({ employeeId, documents, onChange, onCompleteness }) {
  const { showToast } = useToast()
  const [type, setType] = useState('cv')
  const [expiresAt, setExpiresAt] = useState('')
  const [uploads, setUploads] = useState([])
  const [notice, setNotice] = useState(null)
  const [removing, setRemoving] = useState(null)
  const [busy, setBusy] = useState(false)
  const docsRef = useRef(documents)
  useEffect(() => { docsRef.current = documents }, [documents])

  async function addFiles(files) {
    const problems = []
    for (const file of files) {
      const problem = checkFile(file)
      if (problem) { problems.push(problem); continue }
      const key = crypto.randomUUID()
      setUploads((current) => [...current, { key, name: file.name, progress: 0, error: null }])
      try {
        const result = await authService.uploadEmployeeDocument(employeeId, {
          file,
          type,
          expiresAt,
          onProgress: (progress) => setUploads((current) => current.map((u) => (u.key === key ? { ...u, progress } : u))),
        })
        setUploads((current) => current.filter((u) => u.key !== key))
        onChange([...docsRef.current, result.document])
        if (result.completeness) onCompleteness(result.completeness)
      } catch (reason) {
        const message = reason.errors?.file?.[0] ?? reason.message
        setUploads((current) => current.map((u) => (u.key === key ? { ...u, error: message } : u)))
      }
    }
    setNotice(problems.length ? problems.join(' ') : null)
  }

  async function toggleVerified(document) {
    setBusy(true)
    try {
      const { document: updated } = await authService.verifyEmployeeDocument(employeeId, document.id, !document.verified)
      onChange(docsRef.current.map((d) => (d.id === updated.id ? updated : d)))
      showToast(updated.verified ? 'Document marked as verified.' : 'Verification removed.')
    } catch (reason) {
      showToast(reason.message, 'error')
    } finally {
      setBusy(false)
    }
  }

  async function remove() {
    setBusy(true)
    try {
      const result = await authService.deleteEmployeeDocument(employeeId, removing.id)
      onChange(docsRef.current.filter((d) => d.id !== removing.id))
      if (result.completeness) onCompleteness(result.completeness)
      showToast('Document removed.')
      setRemoving(null)
    } catch (reason) {
      showToast(reason.message, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="emp-docs">
      <Alert variant="info">Documents are private to administrators. Every download is recorded in the audit log.</Alert>
      <FieldRow>
        <Select id="doc-type" label="Type for new files" value={type} onChange={(event) => setType(event.target.value)}>
          {DOCUMENT_TYPES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
        <Input id="doc-expiry" label="Expiry date (optional)" type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
      </FieldRow>
      <DropZone onFiles={addFiles} />
      {notice && <p className="ui-field-error" role="alert">{notice}</p>}

      {uploads.length > 0 && (
        <ul className="emp-doc-list" aria-label="Uploads in progress" aria-live="polite">
          {uploads.map((upload) => (
            <li className="emp-doc" key={upload.key}>
              <span className="emp-thumb emp-thumb-file" aria-hidden="true"><Icon name="file" size={20} /></span>
              <div className="emp-doc-main">
                <strong>{upload.name}</strong>
                {upload.error
                  ? <span className="ui-field-error">{upload.error}</span>
                  : <progress value={upload.progress} max="100" aria-label={`Uploading ${upload.name}`} />}
              </div>
              {upload.error && <Button type="button" variant="ghost" size="sm" onClick={() => setUploads((current) => current.filter((u) => u.key !== upload.key))}>Dismiss</Button>}
            </li>
          ))}
        </ul>
      )}

      {documents.length === 0 && uploads.length === 0
        ? <p className="emp-empty">No documents yet. Add a CNIC scan, CV or signed contract above.</p>
        : (
          <ul className="emp-doc-list" aria-label="Saved documents">
            {documents.map((document) => (
              <li className="emp-doc" key={document.id}>
                <span className="emp-thumb emp-thumb-file" aria-hidden="true"><Icon name={document.is_image ? 'image' : 'file'} size={20} /></span>
                <div className="emp-doc-main">
                  <strong>{document.name}</strong>
                  <span>{labelOf(DOCUMENT_TYPES, document.type)} · {formatBytes(document.size_bytes)}</span>
                  <span className="emp-doc-badges">
                    {document.verified
                      ? <Badge variant="success">Verified{document.verified_by ? ` by ${document.verified_by}` : ''}</Badge>
                      : <Badge variant="slate">Not verified</Badge>}
                    {expiryBadge(document)}
                  </span>
                </div>
                <div className="emp-doc-actions">
                  <Button type="button" variant="secondary" size="sm" disabled={busy} onClick={() => toggleVerified(document)}>
                    {document.verified ? 'Unverify' : 'Verify'}
                  </Button>
                  {document.is_image && (
                    <a className="ui-button ui-button-secondary ui-button-sm" href={authService.employeeDocumentUrl(employeeId, document.id, { inline: true })} target="_blank" rel="noopener noreferrer">Preview</a>
                  )}
                  <a className="ui-button ui-button-secondary ui-button-sm" href={authService.employeeDocumentUrl(employeeId, document.id)}>Download</a>
                  <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${document.name}`} disabled={busy} onClick={() => setRemoving(document)}>
                    <Icon name="trash" size={16} />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}

      <Modal
        open={Boolean(removing)}
        onClose={() => !busy && setRemoving(null)}
        title="Remove document?"
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={busy} onClick={() => setRemoving(null)}>Keep</Button>
            <Button type="button" variant="danger" loading={busy} loadingText="Removing…" onClick={remove}>Remove</Button>
          </>
        )}
      >
        <p>{removing?.name} will be permanently deleted. This cannot be undone.</p>
      </Modal>
    </div>
  )
}
