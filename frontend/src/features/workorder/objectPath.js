// Small helpers for the nested brief and work-order objects: read and write a dotted path without mutating, drop
// empty values before sending, and find the server's validation message for a path (`ghl_brief.client.email`).

export function getIn(object, path) {
  return path.split('.').reduce((value, key) => (value === null || value === undefined ? undefined : value[key]), object)
}

export function setIn(object, path, value) {
  const [head, ...rest] = path.split('.')
  const current = object ?? {}
  return { ...current, [head]: rest.length ? setIn(current[head], rest.join('.'), value) : value }
}

const isBlank = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)

// Removes empty strings, nulls, empty lists and objects that end up empty. `false` and 0 are real answers and stay.
export function compact(value) {
  if (Array.isArray(value)) {
    const items = value.map((item) => (item && typeof item === 'object' && !Array.isArray(item) ? compact(item) : item)).filter((item) => !isBlank(item) && !(typeof item === 'object' && Object.keys(item).length === 0))
    return items
  }
  if (value && typeof value === 'object') {
    const out = {}
    Object.entries(value).forEach(([key, item]) => {
      if (key.startsWith('_')) return // client-only keys such as a row's React key
      const next = compact(item)
      if (isBlank(next) || (typeof next === 'object' && !Array.isArray(next) && Object.keys(next).length === 0)) return
      out[key] = next
    })
    return out
  }
  return value
}

// The first server message for a path, or undefined. `errors` is the `errors` object of a 422.
export function errorAt(errors, path) {
  return errors?.[path]?.[0]
}

// True when any error key starts with the prefix (used to open the accordion section that holds a problem).
export function hasErrorUnder(errors, prefix) {
  return Object.keys(errors ?? {}).some((key) => key === prefix || key.startsWith(`${prefix}.`))
}

// Gives every list row a stable React key, so removing a row in the middle keeps the right inputs.
export function withKeys(items) {
  return (items ?? []).map((item, index) => ({ _key: `r${index}-${Math.random().toString(36).slice(2, 7)}`, ...item }))
}
