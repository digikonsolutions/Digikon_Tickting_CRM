import { useCallback, useEffect, useRef, useState } from 'react'

// Small data-loading hook: { data, error, isLoading, reload }.
// Pass a stable function (or one that only changes when its inputs do).
// Only the most recent call may update state, and nothing updates after unmount.
export function useAsync(loader) {
  const [state, setState] = useState({ data: null, error: null, isLoading: true })
  const requestId = useRef(0)
  const isMounted = useRef(true)

  // The single code path that runs the loader.
  const execute = useCallback(async () => {
    const id = ++requestId.current
    const isCurrent = () => isMounted.current && id === requestId.current
    try {
      const data = await loader()
      if (isCurrent()) setState({ data, error: null, isLoading: false })
    } catch (error) {
      if (isCurrent()) setState({ data: null, error, isLoading: false })
    }
  }, [loader])

  // Existing data stays visible while reloading.
  const reload = useCallback(() => {
    if (isMounted.current) setState((current) => ({ ...current, isLoading: true, error: null }))
    return execute()
  }, [execute])

  useEffect(() => {
    isMounted.current = true
    execute()
    return () => {
      isMounted.current = false
      requestId.current += 1
    }
  }, [execute])

  return { ...state, reload }
}
