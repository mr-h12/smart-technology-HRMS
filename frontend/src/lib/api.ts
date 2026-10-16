export type ApiErrorDetail = { field?: string; code: string; message: string }

export class ApiError extends Error {
  code: string
  status: number
  details: ApiErrorDetail[]
  constructor(code: string, message: string, status: number, details: ApiErrorDetail[] = []) {
    super(message)
    this.code = code
    this.status = status
    this.details = details
  }
}

type Options = { method?: string; body?: unknown }

export type Pagination = { page: number; per_page: number; total: number; total_pages: number; has_next_page: boolean; has_previous_page: boolean }

async function call(path: string, { method = 'GET', body }: Options = {}) {
  const res = await fetch(`/api/v1${path}`, {
    method,
    headers: { Accept: 'application/json', ...(body !== undefined && { 'Content-Type': 'application/json' }) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await res.json().catch(() => null)
  if (!res.ok) {
    const e = json?.error
    throw new ApiError(e?.code ?? 'unknown_error', e?.message ?? res.statusText, res.status, e?.details ?? [])
  }
  return json
}

/** Calls /api/v1 and returns the envelope's `data`; throws ApiError on an error envelope. */
export async function api<T = unknown>(path: string, opts?: Options): Promise<T> {
  return (await call(path, opts))?.data as T
}

/** Like api(), for list endpoints: also returns `meta` (meta.pagination). */
export async function apiList<T = unknown>(path: string): Promise<{ data: T[]; meta: { pagination: Pagination } }> {
  const j = await call(path)
  return { data: j.data, meta: j.meta }
}
