import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button, Modal, Textarea, useToast } from '../../../components/ui'
import { FilePicker } from '../../briefs/components/FilePicker'
import { uploadErrorMessage } from '../../briefs/briefData'

const REQUEST_COPY = {
  more_hours: { title: 'Request more hours', hint: 'Tell us how many hours you need and for what.' },
  employee: { title: 'Request an employee', hint: 'Describe the skills and start date you need.' },
  upgrade: { title: 'Request a plan upgrade', hint: 'Tell us what you need: more seats, hours, projects or client accounts.' },
  renewal: { title: 'Request a renewal', hint: 'Anything we should know about your renewal?' },
}

// One request to the administrators, with notes and optional files. Mount it with a `key` so it starts empty each time.
export function RequestModal({ type, onClose, onSent }) {
  const { showToast } = useToast()
  const [message, setMessage] = useState('')
  const [files, setFiles] = useState([])
  const [notice, setNotice] = useState(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState(null)
  const copy = REQUEST_COPY[type] ?? REQUEST_COPY.more_hours

  async function send(event) {
    event.preventDefault()
    if (sending) return
    setSending(true)
    setError(null)
    try {
      await authService.sendAgencyRequest({ type, message: message.trim(), files })
      showToast('Request sent. An administrator will get back to you.')
      onSent?.()
      onClose()
    } catch (reason) {
      setError(reason)
    } finally {
      setSending(false)
    }
  }

  const fileError = error?.errors && Object.entries(error.errors).find(([key]) => key.startsWith('files'))?.[1]?.[0]

  return (
    <Modal
      open={Boolean(type)}
      onClose={() => !sending && onClose()}
      title={copy.title}
      footer={(
        <>
          <Button type="button" variant="secondary" disabled={sending} onClick={onClose}>Cancel</Button>
          <Button type="submit" form="agency-request-form" loading={sending} loadingText="Sending…">Send request</Button>
        </>
      )}
    >
      <form id="agency-request-form" className="ui-form" onSubmit={send}>
        <Textarea
          id="agency-request-message"
          label="Notes (optional)"
          rows="4"
          maxLength="1000"
          value={message}
          helperText={`${copy.hint} ${message.length} of 1000 characters.`}
          onChange={(event) => setMessage(event.target.value)}
          error={error?.errors?.message?.[0]}
        />
        <FilePicker files={files} onChange={setFiles} disabled={sending} notice={notice ?? (fileError ? uploadErrorMessage(error) : null)} onNotice={setNotice} />
        <ApiErrorMessage error={error?.status === 422 ? null : error} />
      </form>
    </Modal>
  )
}
