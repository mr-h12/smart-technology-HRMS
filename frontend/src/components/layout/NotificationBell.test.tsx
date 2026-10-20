import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { NotificationBell } from './NotificationBell'

const user = userEvent.setup()
afterEach(() => vi.unstubAllGlobals())

const missing = (id: string, read_at: string | null, groups: { group: string; period_start: string; period_end: string; missing: number }[]) => ({
  id, type: 'attendance_missing_days', date: '2026-10-20', read_at, data: { date: '2026-10-20', groups },
})
// the engineers' previous and current periods are both open, so the group appears twice
const unread = missing('n1', null, [
  { group: 'engineer', period_start: '2026-08-26', period_end: '2026-09-25', missing: 3 },
  { group: 'engineer', period_start: '2026-09-26', period_end: '2026-10-25', missing: 22 },
])
const read = missing('n2', '2026-10-19T08:00:00Z', [{ group: 'day_laborer', period_start: '2026-10-16', period_end: '2026-10-31', missing: 4 }])
const inbox = (rows: unknown[]) => () => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })

function Where() {
  const { pathname, search } = useLocation()
  return <output>{pathname + search}</output>
}

test('the bell shows the unread count and the missing-days lines', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([unread, read]) })
  renderWithAuth(<NotificationBell />, [])
  const bell = await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' })
  expect(bell).toHaveTextContent('1')
  await user.click(bell)
  const items = await screen.findAllByRole('menuitem')
  expect(items).toHaveLength(3)
  expect(items[1]).toHaveTextContent('أيام غير مسجلة في المهندسون: 22')
  expect(items[1]).toHaveTextContent('2026-09-26 – 2026-10-25')
  expect(items[0]).toHaveTextContent('أيام غير مسجلة في المهندسون: 3')
  expect(items[2]).toHaveTextContent('أيام غير مسجلة في عمال اليومية: 4')
  expect(screen.getByText(/أيام حضور غير مسجلة حتى/, { selector: '.font-semibold' })).toBeInTheDocument() // the unread one is bold
})

test('choosing a line marks it read and opens the grid', async () => {
  const patch = vi.fn(() => json(200, { data: { ...unread, read_at: '2026-10-20T09:00:00Z' } }))
  mockFetch({ 'GET /api/v1/notifications': inbox([unread]), 'PATCH /api/v1/notifications/n1/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [], { path: '/employees' })
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (1 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /المهندسون: 22/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/attendance?group=engineer&period=2026-09-26&tab=grid')
  await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
})

test('choosing a line of a read notification sends nothing', async () => {
  const patch = vi.fn(() => json(200, { data: read }))
  mockFetch({ 'GET /api/v1/notifications': inbox([read]), 'PATCH /api/v1/notifications/n2/read': patch })
  renderWithAuth(<><NotificationBell /><Where /></>, [])
  await user.click(await screen.findByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' }))
  await user.click(await screen.findByRole('menuitem', { name: /عمال اليومية: 4/ }))
  expect(screen.getByRole('status')).toHaveTextContent('/attendance?group=day_laborer&period=2026-10-16&tab=grid')
  expect(patch).not.toHaveBeenCalled()
})

test('no unread notifications, no badge', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox([]) })
  renderWithAuth(<NotificationBell />, [])
  const bell = await screen.findByRole('button', { name: 'فتح الإشعارات (0 غير مقروء)' })
  expect(bell).toHaveTextContent(/^$/)
  await user.click(bell)
  expect(await screen.findByText('لا توجد إشعارات.')).toBeInTheDocument()
})

test('more than nine unread show as 9+', async () => {
  mockFetch({ 'GET /api/v1/notifications': inbox(Array.from({ length: 10 }, (_, i) => missing(`n${i}`, null, []))) })
  renderWithAuth(<NotificationBell />, [])
  expect(await screen.findByRole('button', { name: 'فتح الإشعارات (10 غير مقروء)' })).toHaveTextContent(/^9\+$/)
})
