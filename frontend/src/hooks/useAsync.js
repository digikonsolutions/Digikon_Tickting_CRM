import { useCallback, useEffect, useRef, useState } from 'react'

// The single code path that runs a loader. State is only written after the
// await, and only if this call is still the latest one for a mounted component.
async function runLoader(loader, isCurrent, setState) {
  try {
    const data = await loader()
    if (isCurrent()) setState({ loader, data, error: null, pending: false })
  } catch (error) {
    if (isCurrent()) setState({ loader, data: null, error, pending: false })
  }
}

// Small data-loading hook: { data, error, isLoading, reload }.
// Pass a stable function (or one that only changes when its inputs do).
//
// State is tagged with the loader it belongs to, so when the loader changes
// `isLoading` is true on that very render (no frame showing old rows as loaded).
// Previous `data` stays visible while a reload or a new loader is in flight.
export function useAsync(loader) {
  const [state, setState] = useState({ loader: null, data: null, error: null, pending: true })
  const requestId = useRef(0)
  const isMounted = useRef(true)

  const start = useCallback(() => {
    const id = ++requestId.current
    return runLoader(loader, () => isMounted.current && id === requestId.current, setState)
  }, [loader])

  const reload = useCallback(() => {
    if (isMounted.current) setState((current) => ({ ...current, pending: true, error: null }))
    return start()
  }, [start])

  useEffect(() => {
    isMounted.current = true
    start()
    return () => {
      isMounted.current = false
      requestId.current += 1
    }
  }, [start])

  const isCurrentLoader = state.loader === loader
  return {
    data: state.data,
    error: isCurrentLoader ? state.error : null,
    isLoading: !isCurrentLoader || state.pending,
    reload,
  }
}
