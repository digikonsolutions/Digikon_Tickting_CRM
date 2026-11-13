import { useState } from 'react'
import { Button } from '../../components/ui'
import { TicketComments } from './TicketComments'

// "Comments (N)" button that expands the thread inline. Comments load only once expanded.
export function TicketCommentsToggle({ ticketId, ticketTitle, count = 0 }) {
  const [open, setOpen] = useState(false)
  // Locally posted comments bump the count until the parent refetches (then `count` already includes them).
  const [adjust, setAdjust] = useState({ base: count, delta: 0 })
  const delta = adjust.base === count ? adjust.delta : 0
  const regionId = `ticket-comments-${ticketId}`

  return (
    <div className="ticket-comments-toggle">
      <Button
        type="button"
        variant="ghost"
        size="sm"
        aria-expanded={open}
        aria-controls={regionId}
        onClick={() => setOpen((value) => !value)}
      >
        Comments ({count + delta})
      </Button>
      <div id={regionId} role="region" aria-label={`Comments on ${ticketTitle ?? 'ticket'}`} hidden={!open}>
        {open && (
          <TicketComments
            ticketId={ticketId}
            onPosted={() => setAdjust({ base: count, delta: delta + 1 })}
          />
        )}
      </div>
    </div>
  )
}
