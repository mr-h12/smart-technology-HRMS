import { afterEach, expect, test, vi } from 'vitest'
import { newRequestId } from '@/pages/site-day-add'

afterEach(() => vi.unstubAllGlobals())

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

test('the form id is a v4 UUID with crypto.randomUUID and without it (plain HTTP, old phones), as the API requires', () => {
  expect(newRequestId()).toMatch(V4)
  const real = globalThis.crypto
  vi.stubGlobal('crypto', { getRandomValues: (a: Uint8Array<ArrayBuffer>) => real.getRandomValues(a) })
  const ids = new Set(Array.from({ length: 50 }, newRequestId))
  for (const id of ids) expect(id).toMatch(V4)
  expect(ids.size).toBe(50)
})
