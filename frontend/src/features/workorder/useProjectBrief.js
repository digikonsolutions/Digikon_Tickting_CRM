import { useCallback, useMemo, useState } from 'react'
import { BRIEF_SECTIONS, RECOMMENDED } from './briefSchema'
import { compact, getIn, setIn, withKeys } from './objectPath'

const ROW_FIELDS = Object.fromEntries(BRIEF_SECTIONS.map((section) => [section.key, section.fields.filter((field) => field.type === 'rows').map((field) => field.path)]))

// The server's brief (or nothing) as form state: only known sections, with a stable key on every repeatable row.
export function briefToState(brief) {
  const state = {}
  BRIEF_SECTIONS.forEach((section) => {
    const source = brief?.[section.key]
    if (section.rows) {
      state[section.key] = withKeys(source ?? [])
      return
    }
    let next = { ...(source ?? {}) }
    ;(ROW_FIELDS[section.key] ?? []).forEach((path) => { next = setIn(next, path, withKeys(getIn(next, path))) })
    state[section.key] = next
  })
  return state
}

// What goes to the API. A new project sends only sections that have something in them. An edit sends every section
// (a cleared section goes as null) because the server replaces whole sections, so clearing needs to be said.
export function briefToPayload(state, { replaceAll = false } = {}) {
  const payload = {}
  BRIEF_SECTIONS.forEach((section) => {
    const value = compact(state[section.key])
    const empty = Array.isArray(value) ? value.length === 0 : Object.keys(value ?? {}).length === 0
    if (!empty) payload[section.key] = value
    else if (replaceAll) payload[section.key] = null
  })
  return payload
}

// "Brief 62% complete": the same recommended list the server scores against (shown live while typing).
export function briefPercent(state) {
  const filled = RECOMMENDED.filter((path) => {
    const value = getIn(state, path)
    return !(value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0))
  })
  return Math.round((filled.length / RECOMMENDED.length) * 100)
}

// Form state of the project brief. `reset` goes back to a given saved brief; `payload` is what to send.
export function useProjectBrief(initial) {
  const [values, setValues] = useState(() => briefToState(initial))
  const setField = useCallback((sectionKey, path, value) => {
    setValues((current) => ({ ...current, [sectionKey]: path === null ? value : setIn(current[sectionKey], path, value) }))
  }, [])
  const reset = useCallback((brief) => setValues(briefToState(brief)), [])
  const percent = useMemo(() => briefPercent(values), [values])
  const payload = useCallback((options) => briefToPayload(values, options), [values])
  return { values, setField, reset, percent, payload }
}
