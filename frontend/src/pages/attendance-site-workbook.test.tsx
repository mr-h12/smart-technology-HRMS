import { act, fireEvent, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'
import { SiteWorkbookTab } from './attendance-site-workbook'

afterEach(async () => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  await i18n.changeLanguage('ar')
})

const perms = ['attendance.view.all', 'attendance.edit.all']
const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 0 }
const sites = [{ id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', active: true }, { id: 's2', name_ar: 'Site-2', name_en: 'Site-2', kind: 'work', active: true }]
const routes = {
  'GET /api/v1/attendance/period': () => json(200, { data: period }),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
}
const Url = () => <output data-testid="url">{useLocation().search}</output>
const onThe12th = () => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-11-12T10:00:00Z'))
}
/** Picks a site once the list has loaded. */
async function pickSite(user: ReturnType<typeof userEvent.setup>, name = 'Site-1') {
  await screen.findByRole('option', { name })
  await user.selectOptions(screen.getByRole('combobox', { name: 'الموقع' }), name)
}

test('the import tab switches between per type and per site', async () => {
  const user = userEvent.setup()
  mockFetch(routes)
  renderWithAuth(<><AttendancePage /><Url /></>, perms, { path: '/attendance?group=engineer&tab=import&period=2026-09-26' })
  const byType = await screen.findByRole('tab', { name: 'حسب المجموعة' })
  const bySite = screen.getByRole('tab', { name: 'حسب الموقع' })
  expect(byType).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByRole('button', { name: 'تنزيل القالب' })).toBeInTheDocument()
  expect(screen.getByRole('combobox', { name: 'المجموعة' })).toBeInTheDocument()

  await user.click(bySite)
  expect(bySite).toHaveAttribute('aria-selected', 'true')
  expect(screen.getByTestId('url').textContent).toContain('by=site')
  expect(screen.queryByRole('combobox', { name: 'المجموعة' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'الفترة السابقة' })).not.toBeInTheDocument() // no period stepper either
  expect(screen.queryByRole('button', { name: 'تنزيل القالب' })).not.toBeInTheDocument()
  expect(screen.getByRole('combobox', { name: 'الموقع' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'تنزيل ملف الموقع' })).toBeInTheDocument()

  await user.click(byType)
  expect(screen.getByTestId('url').textContent).not.toContain('by=')
  expect(await screen.findByRole('button', { name: 'تنزيل القالب' })).toBeInTheDocument()
})

test('landing on by=site shows the site workbook without asking for a period', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, perms, { path: '/attendance?tab=import&by=site' })
  expect(await screen.findByRole('button', { name: 'تنزيل ملف الموقع' })).toBeInTheDocument()
  expect(vi.mocked(fetch).mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/attendance/period'))).toEqual([])
})

test('the per-site switch needs both attendance.view.all and attendance.edit.all, else by=site is per type', async () => {
  mockFetch(routes)
  for (const p of [['attendance.view.own', 'attendance.edit.own'], ['attendance.view.all', 'attendance.edit.own'], ['attendance.view.own', 'attendance.edit.all']]) {
    const view = renderWithAuth(<AttendancePage />, p, { path: '/attendance?group=engineer&tab=import&by=site&period=2026-09-26' })
    expect(await screen.findByRole('button', { name: 'تنزيل القالب' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'المجموعة' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'حسب الموقع' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'حسب المجموعة' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'تنزيل ملف الموقع' })).not.toBeInTheDocument()
    view.unmount()
  }
})

test('the download asks for the chosen site and the current half-month', async () => {
  onThe12th()
  const user = userEvent.setup()
  const workbook = vi.fn((_url: string) => new Response('PK', { status: 200 }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/site-workbook': workbook })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  const FakeURL = class extends URL { static createObjectURL = vi.fn(() => 'blob:w'); static revokeObjectURL = vi.fn() }
  vi.stubGlobal('URL', FakeURL)
  const saved: string[][] = []
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    saved.push([this.download, this.href])
  })
  renderWithAuth(<SiteWorkbookTab />, perms)
  expect(screen.getByLabelText('من تاريخ')).toHaveValue('2026-11-01')
  expect(screen.getByLabelText('إلى تاريخ')).toHaveValue('2026-11-15')
  const download = screen.getByRole('button', { name: 'تنزيل ملف الموقع' })
  expect(download).toBeDisabled() // until a site is picked
  await pickSite(user)
  await user.click(download)
  await vi.waitFor(() => expect(saved).toEqual([['site-workbook-2026-11-01-2026-11-15.xlsx', 'blob:w']]))
  expect(workbook.mock.calls[0][0]).toBe('/api/v1/attendance/site-workbook?site_id=s1&from=2026-11-01&to=2026-11-15')
})

test.each([
  ['2028-02-20T10:00:00Z', '2028-02-16', '2028-02-29'], // a leap February
  ['2026-11-15T22:30:00Z', '2026-11-16', '2026-11-30'], // already the 16th in Cairo
])('from the 16th the default runs to the month\'s last day (now %s)', (now, from, to) => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(now))
  mockFetch(routes)
  renderWithAuth(<SiteWorkbookTab />, perms)
  expect(screen.getByLabelText('من تاريخ')).toHaveValue(from)
  expect(screen.getByLabelText('إلى تاريخ')).toHaveValue(to)
})

test('a reversed range or one over 31 days disables the download and says why', async () => {
  onThe12th()
  const user = userEvent.setup()
  mockFetch(routes)
  renderWithAuth(<SiteWorkbookTab />, perms)
  await pickSite(user)
  const download = screen.getByRole('button', { name: 'تنزيل ملف الموقع' })
  const to = screen.getByLabelText('إلى تاريخ')
  expect(download).toBeEnabled()

  fireEvent.change(to, { target: { value: '2026-10-31' } })
  expect(download).toBeDisabled()
  expect(to).toHaveAccessibleDescription('تاريخ النهاية قبل تاريخ البداية.')

  fireEvent.change(to, { target: { value: '2026-12-01' } }) // 31 days, both ends counted
  expect(download).toBeEnabled()
  expect(to).not.toHaveAccessibleDescription()

  fireEvent.change(to, { target: { value: '2026-12-02' } })
  expect(download).toBeDisabled()
  expect(to).toHaveAccessibleDescription('المدة 31 يومًا على الأكثر.')
  await act(() => i18n.changeLanguage('en'))
  expect(screen.getByLabelText('To')).toHaveAccessibleDescription('The range is at most 31 days.')
})
