import { useEffect, useState } from 'react'

/** `value` once it has stopped changing for `ms`; the caller's own state stays instant. The effect is a timer, not a fetch. */
export function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms)
    return () => clearTimeout(id)
  }, [value, ms])
  return v
}
