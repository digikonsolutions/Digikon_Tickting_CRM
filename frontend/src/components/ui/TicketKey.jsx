export function TicketKey({ ticketKey, className = '' }) {
  if (!ticketKey) return null
  return (
    <span className={`ui-ticket-key ${className}`.trim()} aria-label={`Ticket key ${ticketKey}`}>
      {ticketKey}
    </span>
  )
}
