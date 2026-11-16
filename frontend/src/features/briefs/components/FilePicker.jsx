import { useRef } from 'react'
import { Button, Icon } from '../../../components/ui'
import { checkFiles, formatFileSize } from '../../../lib/files'
import { BRIEF_LIMITS } from '../briefData'

// Choose several files before the record exists; they are uploaded after it is created.
// `files` is controlled by the parent. Files that fail the quick client check are reported, not added.
export function FilePicker({ label = 'Attachments (optional)', files, onChange, limits = BRIEF_LIMITS, disabled = false, notice = null, onNotice }) {
  const input = useRef(null)

  function add(incoming) {
    const { accepted, rejected } = checkFiles([...incoming], limits, files.length)
    onChange([...files, ...accepted])
    onNotice?.(rejected.length ? rejected.map((item) => item.error).join(' ') : null)
  }

  return (
    <div className="file-picker">
      <span className="file-picker-label">{label}</span>
      <input
        ref={input}
        type="file"
        multiple
        hidden
        accept={limits.extensions.map((extension) => `.${extension}`).join(',')}
        onChange={(event) => { add(event.target.files); event.target.value = '' }}
      />
      <Button type="button" variant="secondary" size="sm" disabled={disabled} onClick={() => input.current?.click()}>
        <Icon name="paperclip" size={15} /> Add files
      </Button>
      <small>Up to {Math.round(limits.max_file_kb / 1024)} MB each. Allowed: {limits.extensions.join(', ')}.</small>
      {notice && <p className="form-error" role="alert">{notice}</p>}
      {files.length > 0 && (
        <ul className="file-picker-list" aria-label="Files to upload">
          {files.map((file, index) => (
            <li key={`${file.name}-${index}`}>
              <span>{file.name} <small>{formatFileSize(file.size)}</small></span>
              <button type="button" disabled={disabled} aria-label={`Remove ${file.name}`} onClick={() => onChange(files.filter((_, position) => position !== index))}>Remove</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
