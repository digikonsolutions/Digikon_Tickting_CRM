import { useCallback, useEffect, useState } from 'react'

// Small data-loading hook: { data, error, isLoading, reload }.
// Pass a stable function (or one that only changes when its inputs do).
export function useAsync(loader) {
  const [state, setState] = useState({ data: null, error: null, isLoading: true })

  const run = useCallback(async () => {
    setState((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const data = await loader()
      setState({ data, error: null, isLoading: false })
    } catch (error) {
      setState({ data: null, error, isLoading: false })
    }
  }, [loader])

  useEffect(() => {
    let active = true
    loader()
      .then((data) => active && setState({ data, error: null, isLoading: false }))
      .catch((error) => active && setState({ data: null, error, isLoading: false }))
    return () => {
      active = false
    }
  }, [loader])

  return { ...state, reload: run }
}
