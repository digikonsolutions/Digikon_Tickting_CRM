import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// Unmount rendered trees and clear anything a test wrote to the document or storage.
afterEach(() => {
  cleanup()
  document.title = ''
  document.documentElement.removeAttribute('style')
  document.head.querySelectorAll('link[rel~="icon"]').forEach((link) => link.remove())
  window.localStorage.clear()
})