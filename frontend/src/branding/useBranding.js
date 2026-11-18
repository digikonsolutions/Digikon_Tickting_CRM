import { useContext } from 'react'
import { BrandingContext } from './BrandingContext'

export function useBranding() {
  const context = useContext(BrandingContext)
  if (!context) throw new Error('useBranding must be used inside BrandingProvider')
  return context
}
