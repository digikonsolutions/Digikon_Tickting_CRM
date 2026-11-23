import { createContext, useContext } from 'react'

export const PortalContext = createContext(null)

/** The agency portal being shown ({ slug, brand }), or null on the platform's own pages. */
export function usePortal() {
  return useContext(PortalContext)
}