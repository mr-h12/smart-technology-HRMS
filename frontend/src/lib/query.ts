import { keepPreviousData, QueryClient, skipToken, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'
import type { Row } from '@/components/form'
import i18n from '@/i18n'
import { api, ApiError, apiList } from './api'

/** A 4xx answer is final; anything else (5xx, network) gets one more try. */
export const shouldRetry = (failures: number, e: unknown) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && failures < 1

/** The one cache. Auth clears it on login, logout and 401 so no answer survives into another account. */
export const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, gcTime: 5 * 60_000, retry: shouldRetry } } })

// server messages are localized: a language switch makes every cached answer stale
i18n.on('languageChanged', () => void queryClient.invalidateQueries())

/** `/employees/123?x=1` → ['employees', '123', '?x=1'], so the prefix ['employees'] matches every query of that resource. */
export function queryKey(path: string): string[] {
  const i = path.indexOf('?')
  const segments = (i < 0 ? path : path.slice(0, i)).split('/').filter(Boolean)
  return i < 0 || i === path.length - 1 ? segments : [...segments, path.slice(i)]
}

/** Lookups several pages share. One exact string each: query keys depend on query-string order, so every caller must use these to hit one cache entry. */
export const USERS = '/users?per_page=100'
export const RESIDENCES = '/residences?per_page=100'

// Both hooks cache the whole envelope ({data, meta}) under the same key shape, so they never collide.
const fetchEnvelope = (path: string) => ({ signal }: { signal: AbortSignal }) => apiList<Row>(path, { signal })
const none: Row[] = []

/** Loads a list endpoint; `total` lets pages warn when more rows exist than were fetched; `loading` is true until the first answer. */
export function useList(path: string) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: queryKey(path), queryFn: fetchEnvelope(path), placeholderData: keepPreviousData })
  const reload = useCallback(() => void qc.invalidateQueries({ queryKey: queryKey(path) }), [qc, path])
  const ok = !q.isError && q.data
  return { rows: ok ? ok.data : none, total: ok ? ok.meta.pagination.total : 0, err: q.error ?? undefined, loading: q.isPending, reload }
}

/** Loads one record (the envelope's `data`); a null path loads nothing. */
export function useApi<T = Row>(path: string | null) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: queryKey(path ?? ''), queryFn: path === null ? skipToken : fetchEnvelope(path) })
  const reload = useCallback(() => void (path !== null && qc.invalidateQueries({ queryKey: queryKey(path) })), [qc, path])
  return { data: q.isError ? undefined : (q.data?.data as T | undefined), err: q.error ?? undefined, loading: q.isLoading, reload }
}

/**
 * Returns `save(path, {method, body}, invalidate?)`: sends the write (POST by default) and, on success, marks stale every query
 * under each `invalidate` path (default: the path's resource, e.g. '/sites/1' → ['sites']), so what is on screen refetches.
 * Every write is audited, so the audit log is always marked stale too.
 */
export function useSave() {
  const qc = useQueryClient()
  return useCallback(
    async <T = Row>(path: string, opts: { method?: string; body?: unknown } = {}, invalidate: string[] = [queryKey(path)[0] ?? '']) => {
      const r = await api<T>(path, { method: 'POST', ...opts })
      for (const p of [...invalidate, '/audit-log']) void qc.invalidateQueries({ queryKey: queryKey(p) })
      return r
    },
    [qc],
  )
}
