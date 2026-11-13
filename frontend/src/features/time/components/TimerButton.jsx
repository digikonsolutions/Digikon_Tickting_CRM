import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { Button, Icon, useToast } from '../../../components/ui'
import { announceTimerChanged, TIMER_STATUSES } from '../timeEvents'

// Start / Stop for the assigned specialist. `ticket.timer_running` comes from the API; the server decides
// whether a start is allowed, this only hides the button where it can never work.
export function TimerButton({ ticket, size = 'sm' }) {
  const { showToast } = useToast()
  const [isBusy, setIsBusy] = useState(false)
  const running = Boolean(ticket.timer_running)
  if (!running && !TIMER_STATUSES.includes(ticket.status)) return null

  async function toggle(event) {
    event.stopPropagation()
    setIsBusy(true)
    try {
      if (running) {
        await authService.stopTimer()
        showToast(`Timer stopped on ${ticket.key}.`)
      } else {
        const result = await authService.startTimer(ticket.id)
        showToast(result.stopped ? `Timer moved to ${ticket.key}.` : `Timer started on ${ticket.key}.`)
      }
      announceTimerChanged()
    } catch (error) {
      showToast(error.errors?.ticket?.[0] ?? error.message, 'error')
    } finally {
      setIsBusy(false)
    }
  }

  return (
    <Button type="button" size={size} variant={running ? 'danger' : 'secondary'} loading={isBusy} onClick={toggle}>
      <Icon name={running ? 'stop' : 'play'} size={14} />
      {running ? 'Stop timer' : 'Start timer'}
    </Button>
  )
}
