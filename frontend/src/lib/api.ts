import i18n from '@/i18n'

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

const TOKEN_KEY = 'hrms.auth.token.v1'

// ponytail: the token lives only in localStorage; where storage is blocked, sign-in does not stick.
export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* storage unavailable */
  }
}

let onUnauthorized = () => {}
/** Called after a request that carried a token got a 401 (session ended); the token is already cleared. */
export function setUnauthorizedHandler(fn: () => void): void {
  onUnauthorized = fn
}

async function call(path: string, { method = 'GET', body }: Options = {}) {
  const token = getToken()
  const res = await fetch(`/api/v1${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Accept-Language': i18n.language,
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body !== undefined && { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await res.json().catch(() => null)
  if (!res.ok) {
    // only if no newer sign-in replaced the token while this request was in flight
    if (res.status === 401 && token && getToken() === token) {
      setToken(null)
      onUnauthorized()
    }
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
