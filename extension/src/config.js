// Where the API address comes from. A managed install (company policy) sets it for every employee and the person cannot
// change it; otherwise it is whatever was typed in Settings.

export function effectiveApiBase(local, managed) {
  return (managed?.apiBase && String(managed.apiBase).trim()) || local?.apiBase || ''
}

export async function readManaged(storage = globalThis.chrome?.storage) {
  try {
    return (await storage?.managed?.get('apiBase')) ?? {}
  } catch {
    return {}
  }
}