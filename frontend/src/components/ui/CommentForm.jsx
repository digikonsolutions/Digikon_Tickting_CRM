import { useEffect, useRef, useState } from 'react'
import { checkFiles, DEFAULT_LIMITS, filesFromClipboard, formatFileSize } from '../../lib/files'
import { Button } from './Button'
import { Textarea } from './Fields'
import { Icon } from './Icon'

const MAX_COMMENT_LENGTH = 10000

// `onSubmit(body, { isInternal, files })` must return a promise; throw to report an error, which is shown
// under the field. With `allowAttachments`, files can be picked, dropped on the picker or pasted (screenshots)
// into the text box, and are sent together with the comment.
export function CommentForm({
  onSubmit,
  label = 'Add a comment',
  disabled = false,
  allowInternal = false,
  allowAttachments = false,
  limits = DEFAULT_LIMITS,
  className = '',
}) {
  const [body, setBody] = useState('')
  const [isInternal, setIsInternal] = useState(false)
  const [files, setFiles] = useState([])
  const [fileProblems, setFileProblems] = useState([])
  const [isSending, setIsSending] = useState(false)
  const [error, setError] = useState(null)
  const picker = useRef(null)
  const isMounted = useRef(true)
  useEffect(() => {
    isMounted.current = true
    return () => { isMounted.current = false }
  }, [])

  const trimmed = body.trim()
  const canSend = (Boolean(trimmed) || files.length > 0) && !isSending && !disabled

  function addFiles(incoming) {
    if (!incoming.length) return
    const { accepted, rejected } = checkFiles(incoming, limits, files.length)
    setFiles((current) => [...current, ...accepted])
    setFileProblems(rejected.map((item) => item.error))
    if (error) setError(null)
  }

  async function send() {
    if (!canSend) return
    setIsSending(true)
    setError(null)
    try {
      await onSubmit(trimmed, { isInternal: allowInternal && isInternal, files })
      if (isMounted.current) {
        setBody('')
        setFiles([])
        setFileProblems([])
      }
    } catch (reason) {
      if (isMounted.current) setError(reason)
    } finally {
      if (isMounted.current) setIsSending(false)
    }
  }

  const errorText = error
    ? (error.errors?.body?.[0] ?? Object.values(error.errors ?? {}).flat()[0] ?? error.message ?? 'Could not post the comment.')
    : undefined

  return (
    <form
      className={`ui-comment-form${allowInternal && isInternal ? ' is-internal' : ''} ${className}`.trim()}
      onSubmit={(event) => { event.preventDefault(); send() }}
    >
      <Textarea
        label={allowInternal && isInternal ? 'Internal note' : label}
        rows="3"
        maxLength={MAX_COMMENT_LENGTH}
        value={body}
        disabled={disabled || isSending}
        error={errorText}
        helperText={allowInternal && isInternal
          ? 'Internal: not visible to the client.'
          : `Press Ctrl+Enter (or Cmd+Enter) to post.${allowAttachments ? ' You can paste screenshots here.' : ''}`}
        onChange={(event) => { setBody(event.target.value); if (error) setError(null) }}
        onPaste={allowAttachments ? (event) => {
          const pasted = filesFromClipboard(event)
          if (pasted.length) addFiles(pasted)
        } : undefined}
        onKeyDown={(event) => {
          if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault()
            send()
          }
        }}
      />
      {allowAttachments && (
        <div className="ui-comment-attachments">
          <input
            ref={picker}
            type="file"
            multiple
            hidden
            accept={limits.extensions.map((extension) => `.${extension}`).join(',')}
            onChange={(event) => { addFiles([...event.target.files]); event.target.value = '' }}
          />
          <Button type="button" variant="ghost" size="sm" disabled={disabled || isSending} onClick={() => picker.current?.click()}>
            <Icon name="paperclip" size={16} /> Attach files
          </Button>
          {files.length > 0 && (
            <ul className="ui-comment-file-list" aria-label="Files to attach">
              {files.map((file, index) => (
                <li key={`${file.name}-${index}`}>
                  <span>{file.name} <small>{formatFileSize(file.size)}</small></span>
                  <button type="button" aria-label={`Remove ${file.name}`} disabled={isSending} onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}>
                    <Icon name="close" size={14} />
                  </button>
                </li>
              ))}
            </ul>
          )}
          {fileProblems.map((problem) => <p className="ui-field-error" role="alert" key={problem}>{problem}</p>)}
        </div>
      )}
      {allowInternal && (
        <label className="ui-comment-internal-toggle">
          <input type="checkbox" checked={isInternal} onChange={(event) => setIsInternal(event.target.checked)} disabled={disabled || isSending} />
          Internal note (not visible to the client)
        </label>
      )}
      <Button type="submit" size="sm" disabled={!canSend} loading={isSending} loadingText="Posting…">
        Post comment
      </Button>
    </form>
  )
}
