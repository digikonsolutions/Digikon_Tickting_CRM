import { useCallback, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, Card, Icon, Modal, RelativeTime, Skeleton, useToast } from '../../../components/ui'
import { useAsync } from '../../../hooks/useAsync'
import { checkFiles, fileIconName, formatFileSize } from '../../../lib/files'
import { BRIEF_LIMITS, uploadErrorMessage } from '../briefData'

// Files on a project brief: list, download, and (for the agency) upload and delete. The server decides
// who may upload (`can_upload`) and delete (`can_delete` per file).
export function ProjectFiles({ projectId }) {
  const { showToast } = useToast()
  const load = useCallback(() => authService.listProjectAttachments(projectId), [projectId])
  const { data, error, isLoading, reload } = useAsync(load)
  const [isUploading, setIsUploading] = useState(false)
  const [uploadError, setUploadError] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const picker = useRef(null)
  const limits = data?.limits ?? BRIEF_LIMITS
  const attachments = data?.attachments ?? []

  async function upload(incoming) {
    const { accepted, rejected } = checkFiles([...incoming], limits)
    setUploadError(rejected.length ? rejected.map((item) => item.error).join(' ') : null)
    if (!accepted.length) return
    setIsUploading(true)
    try {
      await authService.uploadProjectAttachments(projectId, accepted)
      showToast(`${accepted.length} file${accepted.length === 1 ? '' : 's'} uploaded.`)
      await reload()
    } catch (reason) {
      setUploadError(uploadErrorMessage(reason))
    } finally {
      setIsUploading(false)
    }
  }

  async function confirmDelete() {
    setIsDeleting(true)
    try {
      await authService.deleteProjectAttachment(pendingDelete.id)
      setPendingDelete(null)
      showToast('File removed.')
      await reload()
    } catch (reason) {
      setUploadError(uploadErrorMessage(reason))
      setPendingDelete(null)
    } finally {
      setIsDeleting(false)
    }
  }

  if (isLoading && !data) return <Card title="Files"><div role="status" aria-label="Loading files"><Skeleton variant="row" /></div></Card>
  if (error && !data) return <Card title="Files"><Alert variant="error">{error.message}</Alert></Card>
  if (!attachments.length && !data?.can_upload) return null

  return (
    <Card
      title="Project files"
      header={(
        <div className="project-tickets-header">
          <div><h2>Project files</h2><p>Briefs, designs and documents for this project.</p></div>
          {data?.can_upload && (
            <>
              <input ref={picker} type="file" multiple hidden accept={limits.extensions.map((extension) => `.${extension}`).join(',')} onChange={(event) => { upload(event.target.files); event.target.value = '' }} />
              <Button type="button" variant="secondary" size="sm" loading={isUploading} loadingText="Uploading…" onClick={() => picker.current?.click()}>
                <Icon name="paperclip" size={15} /> Add files
              </Button>
            </>
          )}
        </div>
      )}
    >
      {uploadError && <Alert variant="error">{uploadError}</Alert>}
      {attachments.length === 0
        ? <p className="assignment-empty">No files yet.</p>
        : (
          <ul className="files-list" aria-label="Project files">
            {attachments.map((file) => (
              <li className="files-item" key={file.id}>
                <a className="files-thumb" href={authService.projectAttachmentUrl(file.id)} aria-label={`Download ${file.name}`}>
                  {file.is_image
                    ? <img src={authService.projectAttachmentUrl(file.id, { inline: true })} alt="" loading="lazy" />
                    : <Icon name={fileIconName(file.name, file.mime_type)} size={26} />}
                </a>
                <div className="files-meta">
                  <strong>{file.name}</strong>
                  <span>{formatFileSize(file.size_bytes)} · {file.uploaded_by ?? 'Former user'} · <RelativeTime date={file.created_at} /></span>
                </div>
                <div className="files-actions">
                  <a className="ui-button ui-button-secondary ui-button-sm" href={authService.projectAttachmentUrl(file.id)}><Icon name="download" size={15} /> Download</a>
                  {file.can_delete && (
                    <Button type="button" variant="ghost" size="sm" aria-label={`Delete ${file.name}`} onClick={() => setPendingDelete(file)}>
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
          <p><strong>{pendingDelete.name}</strong> will no longer be available on this project.</p>
        </Modal>
      )}
    </Card>
  )
}
