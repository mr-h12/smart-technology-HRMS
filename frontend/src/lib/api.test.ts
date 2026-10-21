import { afterEach, expect, test, vi } from 'vitest'
import { api, apiBlob, ApiError, apiList, getToken, setToken, setUnauthorizedHandler } from './api'

const reply = (status: number, body: unknown) =>
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status })))

afterEach(() => {
  vi.unstubAllGlobals()
  setUnauthorizedHandler(() => {})
})

test('unwraps data', async () => {
  reply(200, { data: { id: 1 }, meta: { request_id: 'r' } })
  expect(await api('/employees/1')).toEqual({ id: 1 })
})

test('throws ApiError on an error envelope', async () => {
  const details = [{ field: 'name', code: 'required', message: 'Name required' }]
  reply(422, { error: { code: 'validation_failed', message: 'Invalid', details }, meta: { request_id: 'r' } })
  const err = await api('/employees', { method: 'POST', body: {} }).catch((e) => e)
  expect(err).toBeInstanceOf(ApiError)
  expect(err).toMatchObject({ code: 'validation_failed', message: 'Invalid', status: 422, details })
})

test('null details becomes an empty array', async () => {
  reply(500, { error: { code: 'server_error', message: 'Boom', details: null }, meta: { request_id: 'r' } })
  const err = await api('/x').catch((e) => e)
  expect((err as ApiError).details).toEqual([])
})

test('apiList returns data and meta', async () => {
  reply(200, { data: [{ id: 1 }], meta: { pagination: { total: 1 }, request_id: 'r' } })
  expect(await apiList('/employees')).toEqual({ data: [{ id: 1 }], meta: { pagination: { total: 1 }, request_id: 'r' } })
})

test('sends the bearer token and the UI language', async () => {
  const f = vi.fn(async () => new Response(JSON.stringify({ data: {} }), { status: 200 }))
  vi.stubGlobal('fetch', f)
  setToken('a'.repeat(64))
  await api('/auth/me')
  const headers = (f.mock.calls[0] as unknown as [string, RequestInit])[1].headers as Record<string, string>
  expect(headers.Authorization).toBe(`Bearer ${'a'.repeat(64)}`)
  expect(headers['Accept-Language']).toBe('ar')
})

test('a 401 with a token clears it and calls the handler; without a token it only throws', async () => {
  const onUnauthorized = vi.fn()
  setUnauthorizedHandler(onUnauthorized)
  reply(401, { error: { code: 'unauthenticated', message: 'x' } })
  setToken('a'.repeat(64))
  await api('/auth/me').catch(() => {})
  expect(getToken()).toBeNull()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBeNull()
  expect(onUnauthorized).toHaveBeenCalledTimes(1)
  await api('/auth/login', { method: 'POST', body: {} }).catch(() => {})
  expect(onUnauthorized).toHaveBeenCalledTimes(1)
})

test('passes the abort signal to fetch', async () => {
  const f = vi.fn(async (_url: string, _init?: RequestInit) => new Response(JSON.stringify({ data: [], meta: {} }), { status: 200 }))
  vi.stubGlobal('fetch', f)
  const signal = new AbortController().signal
  await apiList('/employees', { signal })
  expect(f.mock.calls[0][1]?.signal).toBe(signal)
})

test('FormData bodies are sent without a JSON content type', async () => {
  const f = vi.fn(async (_url: string, _init?: RequestInit) => new Response(JSON.stringify({ data: {} }), { status: 200 }))
  vi.stubGlobal('fetch', f)
  const body = new FormData()
  body.set('group', 'engineer')
  await api('/attendance/import/preview', { method: 'POST', body })
  const init = f.mock.calls[0][1]!
  expect(init.body).toBe(body)
  expect(init.headers).not.toHaveProperty('Content-Type')
})

test('apiBlob returns the body as a Blob with the bearer token', async () => {
  const f = vi.fn(async (_url: string, _init?: RequestInit) => new Response('xlsx-bytes', { status: 200 }))
  vi.stubGlobal('fetch', f)
  setToken('b'.repeat(64))
  const blob = await apiBlob('/attendance/template?group=engineer')
  expect(await blob.text()).toBe('xlsx-bytes')
  expect(f.mock.calls[0][0]).toBe('/api/v1/attendance/template?group=engineer')
  expect((f.mock.calls[0][1]!.headers as Record<string, string>).Authorization).toBe(`Bearer ${'b'.repeat(64)}`)
})

test('apiBlob shares the error envelope and the 401 handling', async () => {
  const onUnauthorized = vi.fn()
  setUnauthorizedHandler(onUnauthorized)
  reply(401, { error: { code: 'unauthenticated', message: 'x' } })
  setToken('b'.repeat(64))
  const err = await apiBlob('/attendance/template').catch((e) => e)
  expect(err).toMatchObject({ code: 'unauthenticated', status: 401 })
  expect(getToken()).toBeNull()
  expect(onUnauthorized).toHaveBeenCalledTimes(1)
})
