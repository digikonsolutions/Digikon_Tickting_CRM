import { useState } from 'react'
import { Button } from '../../../components/ui'

// One line, shown to employees until they dismiss it. Dismissal is remembered per account in this browser.
export function ActivityNotice({ userId }) {
  const key = `clienthub:activity-notice:${userId}`
  const [visible, setVisible] = useState(() => {
    try {
      return window.localStorage.getItem(key) !== 'seen'
    } catch {
      return true
    }
  })
  if (!visible) return null

  function dismiss() {
    setVisible(false)
    try {
      window.localStorage.setItem(key, 'seen')
    } catch {
      // Without storage the notice simply comes back next time.
    }
  }

  return (
    <div className="activity-notice" role="note">
      <p>Your work activity here (sign-ins, ticket updates and active time) is recorded for administrators. Keystrokes, screens and page content are never recorded.</p>
      <Button type="button" size="sm" variant="secondary" onClick={dismiss}>Got it</Button>
    </div>
  )
}
