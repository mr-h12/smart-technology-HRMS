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

/** Calls /api/v1 and returns the envelope's `data`; throws ApiError on an error envelope. */
export async function api<T = unknown>(path: string, { method = 'GET', body }: Options = {}): Promise<T> {
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
  return json?.data as T
}
