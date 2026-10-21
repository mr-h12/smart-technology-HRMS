import { act, renderHook } from '@testing-library/react'
import { expect, test, vi } from 'vitest'
import { useDebounced } from './useDebounced'

test('the value follows only after it has been still for the delay', () => {
  vi.useFakeTimers()
  const { result, rerender } = renderHook(({ v }) => useDebounced(v, 250), { initialProps: { v: 'a' } })
  rerender({ v: 'ab' })
  act(() => void vi.advanceTimersByTime(200))
  rerender({ v: 'abc' })
  act(() => void vi.advanceTimersByTime(200))
  expect(result.current).toBe('a')
  act(() => void vi.advanceTimersByTime(50))
  expect(result.current).toBe('abc')
  vi.useRealTimers()
})
