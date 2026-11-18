import { useEffect, useId, useRef, useState } from 'react'
import { Button, Icon } from '../../../../components/ui'

function formatSize(bytes) {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

function readDimensions(file) {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file)
    const image = new Image()
    image.onload = () => { URL.revokeObjectURL(url); resolve({ width: image.naturalWidth, height: image.naturalHeight }) }
    image.onerror = () => { URL.revokeObjectURL(url); resolve(null) }
    image.src = url
  })
}

/**
 * Drag-and-drop or click-to-browse image upload with a preview, client-side checks (type, size,
 * dimensions) before anything is sent, progress, Replace and Remove, and aria-live status messages.
 * The server validates again; this only gives fast feedback.
 *
 * `onUpload(file, { onProgress })` must resolve once saved; `onRemove()` likewise. `currentUrl` is the saved file.
 */
export function ImageUploadField({
  label,
  helperText,
  currentUrl,
  accept = ['image/png', 'image/jpeg', 'image/webp'],
  acceptLabel = 'PNG, JPG or WEBP',
  maxBytes = 2 * 1024 * 1024,
  minSize = 64,
  maxSize = 4096,
  square = false,
  onUpload,
  onRemove,
  serverError = null,
}) {
  const id = useId()
  const inputRef = useRef(null)
  const [isDragging, setIsDragging] = useState(false)
  const [pending, setPending] = useState(null) // { file, url, progress }
  const [error, setError] = useState('')
  const [status, setStatus] = useState('')
  const [brokenUrl, setBrokenUrl] = useState(null)
  const [isRemoving, setIsRemoving] = useState(false)
  const pendingUrl = pending?.url

  useEffect(() => () => { if (pendingUrl) URL.revokeObjectURL(pendingUrl) }, [pendingUrl])

  async function handleFile(file) {
    setError('')
    setStatus('')
    if (!accept.includes(file.type)) {
      setError(`${file.name} is not allowed. Use ${acceptLabel}.`)
      return
    }
    if (file.size > maxBytes) {
      setError(`${file.name} is ${formatSize(file.size)}. The limit is ${formatSize(maxBytes)}.`)
      return
    }
    const size = await readDimensions(file)
    if (!size) {
      setError(`${file.name} could not be read as an image.`)
      return
    }
    if (size.width < minSize || size.height < minSize) {
      setError(`${file.name} is ${size.width}×${size.height}px. The minimum is ${minSize}×${minSize}px.`)
      return
    }
    if (size.width > maxSize || size.height > maxSize) {
      setError(`${file.name} is ${size.width}×${size.height}px. The maximum is ${maxSize}×${maxSize}px.`)
      return
    }

    setPending({ file, url: URL.createObjectURL(file), progress: 0 })
    setStatus(`Uploading ${file.name}…`)
    try {
      await onUpload(file, { onProgress: (progress) => setPending((current) => (current ? { ...current, progress } : current)) })
      setBrokenUrl(null)
      setStatus(`${label} saved.`)
    } catch (reason) {
      const message = reason?.errors?.file?.[0] ?? reason?.message ?? 'The upload failed. Try again.'
      setError(message)
      setStatus('')
    } finally {
      setPending(null)
    }
  }

  function onPick(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (file) void handleFile(file)
  }

  function onDrop(event) {
    event.preventDefault()
    setIsDragging(false)
    const file = event.dataTransfer?.files?.[0]
    if (file) void handleFile(file)
  }

  async function remove() {
    setError('')
    setIsRemoving(true)
    try {
      await onRemove()
      setStatus(`${label} removed.`)
    } catch (reason) {
      setError(reason?.message ?? 'Could not remove the file. Try again.')
    } finally {
      setIsRemoving(false)
    }
  }

  const shownUrl = pending?.url ?? (currentUrl && currentUrl !== brokenUrl ? currentUrl : null)
  const busy = Boolean(pending) || isRemoving
  const shownError = error || serverError

  return (
    <div className="ab-upload">
      <span className="ui-field-label" id={`${id}-label`}>{label}</span>
      <div className="ab-upload-body">
        <label
          className={`ab-dropzone${isDragging ? ' is-dragging' : ''}${shownUrl ? ' has-image' : ''}${square ? ' is-square' : ''}${busy ? ' is-busy' : ''}`}
          htmlFor={`${id}-input`}
          onDragOver={(event) => { event.preventDefault(); setIsDragging(true) }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={onDrop}
        >
          <input
            ref={inputRef}
            id={`${id}-input`}
            className="ab-drop-input"
            type="file"
            accept={accept.join(',')}
            disabled={busy}
            aria-labelledby={`${id}-label`}
            aria-describedby={`${id}-help${shownError ? ` ${id}-error` : ''}`}
            onChange={onPick}
          />
          {shownUrl ? (
            <img className="ab-drop-image" src={shownUrl} alt={`Current ${label.toLowerCase()}`} onError={() => setBrokenUrl(shownUrl)} />
          ) : (
            <span className="ab-drop-empty">
              <Icon name="image" size={26} />
              <strong>{isDragging ? 'Drop to upload' : 'Drop an image or click to browse'}</strong>
              <span>{acceptLabel}</span>
            </span>
          )}
          {pending && (
            <span className="ab-drop-progress" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pending.progress} aria-label={`Uploading ${label}`}>
              <span style={{ '--progress': `${pending.progress}%` }} />
            </span>
          )}
        </label>

        <div className="ab-upload-side">
          {pending && <p className="ab-upload-file">{pending.file.name} · {formatSize(pending.file.size)}</p>}
          <div className="ab-upload-actions">
            {shownUrl && !pending && (
              <>
                <Button type="button" variant="secondary" size="sm" disabled={busy} onClick={() => inputRef.current?.click()}>Replace</Button>
                <Button type="button" variant="ghost" size="sm" loading={isRemoving} loadingText="Removing…" onClick={remove}>Remove</Button>
              </>
            )}
            {!shownUrl && !pending && (
              <Button type="button" variant="secondary" size="sm" onClick={() => inputRef.current?.click()}>Choose file</Button>
            )}
          </div>
          <p className="ui-field-help" id={`${id}-help`}>{helperText}</p>
        </div>
      </div>
      {shownError && <p className="ui-field-error" id={`${id}-error`}>{shownError}</p>}
      <p className="sr-only" role="status" aria-live="polite">{status}</p>
    </div>
  )
}
