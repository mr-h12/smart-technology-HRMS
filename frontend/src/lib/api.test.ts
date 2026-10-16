import { afterEach, expect, test, vi } from 'vitest'
import { api, ApiError } from './api'

const reply = (status: number, body: unknown) =>
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status })))

afterEach(() => vi.unstubAllGlobals())

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
