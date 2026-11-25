import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../auth/authService'
import { useAsync } from '../../hooks/useAsync'
import { orderToPayload, orderToState } from './workOrder'
import { setIn, withKeys } from './objectPath'

// Form state of a ticket's work order. For a ticket being edited the saved work order is fetched once (lists carry
// only a summary); for a new one it starts blank, with the GHL location defaulted from the project's brief.
export function useTicketDetails({ ticket, location = '' }) {
  const loader = useCallback(() => (ticket ? authService.getTicketByKey(ticket.key) : Promise.resolve(null)), [ticket])
  const loaded = useAsync(loader)
  const [state, setState] = useState(() => orderToState(null, location))
  const [templates, setTemplates] = useState([])
  const [hydrated, setHydrated] = useState(!ticket)

  useEffect(() => {
    let active = true
    authService.getTicketTemplates().then((result) => { if (active) setTemplates(result.templates ?? []) }).catch(() => {})
    return () => { active = false }
  }, [])
  useEffect(() => {
    if (ticket && loaded.data && !hydrated) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- one-time hydration from the fetched work order
      setState(orderToState(loaded.data.work_order, location))
      setHydrated(true)
    }
  }, [ticket, loaded.data, hydrated, location])

  const setField = useCallback((path, value) => setState((current) => setIn(current, path, value)), [])

  // Picking a work type fills the acceptance criteria and checklist from its template, but only where they are empty.
  const chooseType = useCallback((type) => {
    setState((current) => {
      const template = templates.find((item) => item.key === type)
      const next = { ...current, work_type: type }
      if (!template) return next
      if (!current.brief_section) next.brief_section = template.brief_section ?? ''
      if (!current.requirements.acceptance_criteria && template.acceptance) next.requirements = { ...current.requirements, acceptance_criteria: template.acceptance }
      if (current.checklist.length === 0) next.checklist = withKeys(template.checklist.map((text) => ({ text })))
      return next
    })
  }, [templates])

  return {
    state, setField, chooseType, templates,
    isLoading: Boolean(ticket) && !hydrated, loadError: loaded.error, hadOrder: Boolean(loaded.data?.work_order),
    template: templates.find((item) => item.key === state.work_type) ?? null,
    payload: (options) => orderToPayload(state, options),
  }
}
