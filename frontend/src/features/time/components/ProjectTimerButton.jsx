import { useState } from 'react'
import { authService } from '../../../auth/authService'
import { Button, Icon, useToast } from '../../../components/ui'
import { announceTimerChanged } from '../timeEvents'

// Start / Stop a timer straight on a project that has no tickets. The server decides whether it is allowed.
export function ProjectTimerButton({ project, running = false, size = 'sm' }) {
  const { showToast } = useToast()
  const [isBusy, setIsBusy] = useState(false)

  async function toggle() {
    setIsBusy(true)
    try {
      if (running) {
        await authService.stopTimer()
        showToast(`Timer stopped on ${project.title}.`)
      } else {
        await authService.startProjectTimer(project.id)
        showToast(`Timer started on ${project.title}.`)
      }
      announceTimerChanged()
    } catch (error) {
      showToast(error.errors?.project?.[0] ?? error.message, 'error')
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