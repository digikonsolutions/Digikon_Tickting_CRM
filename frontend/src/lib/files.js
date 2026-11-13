// Client-side mirror of the server's attachment limits. The server stays the authority: this only
// gives instant feedback before a file is sent.

export const DEFAULT_LIMITS = Object.freeze({
  max_file_kb: 10240,
  max_files_per_upload: 10,
  max_files_per_ticket: 50,
  extensions: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'csv', 'docx', 'xlsx', 'pptx'],
})

const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp']

export function fileExtension(name = '') {
  const index = String(name).lastIndexOf('.')
  return index === -1 ? '' : String(name).slice(index + 1).toLowerCase()
}

export function isImageName(name) {
  return IMAGE_EXTENSIONS.includes(fileExtension(name))
}

// 1536 -> "1.5 KB"
export function formatFileSize(bytes) {
  const size = Number(bytes)
  if (!Number.isFinite(size) || size < 0) return ''
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(size < 10 * 1024 ? 1 : 0)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

// Icon name for the generic file types shown in lists.
export function fileIconName(name, mime = '') {
  if (mime.startsWith('image/') || isImageName(name)) return 'image'
  return 'file'
}

// Returns a message, or null when the file looks acceptable.
export function validateFile(file, limits = DEFAULT_LIMITS) {
  if (!file.size) return `${file.name} is empty.`
  if (file.size > limits.max_file_kb * 1024) return `${file.name} is larger than ${Math.round(limits.max_file_kb / 1024)} MB.`
  const extension = fileExtension(file.name)
  if (!extension || !limits.extensions.includes(extension)) return `${file.name}: files of this type are not allowed.`
  return null
}

// Splits a batch into files that can be uploaded and per-file errors, honouring the per-upload limit.
export function checkFiles(files, limits = DEFAULT_LIMITS, alreadyQueued = 0) {
  const accepted = []
  const rejected = []
  files.forEach((file) => {
    const problem = validateFile(file, limits)
    if (problem) rejected.push({ file, error: problem })
    else if (accepted.length + alreadyQueued >= limits.max_files_per_upload) {
      rejected.push({ file, error: `You can add at most ${limits.max_files_per_upload} files at a time.` })
    } else accepted.push(file)
  })
  return { accepted, rejected }
}

// Files pasted into a text box (screenshots from the clipboard have no name, so give them one).
export function filesFromClipboard(event) {
  const items = [...(event.clipboardData?.files ?? [])]
  return items.map((file, index) => {
    if (file.name && file.name !== 'image.png') return file
    const extension = fileExtension(file.type.replace('image/', 'x.')) || 'png'
    const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)
    return new File([file], `screenshot-${stamp}${items.length > 1 ? `-${index + 1}` : ''}.${extension}`, { type: file.type })
  })
}
