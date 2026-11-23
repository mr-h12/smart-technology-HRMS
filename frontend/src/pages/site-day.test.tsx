import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { SiteDayPage } from './site-day'

const user = userEvent.setup()
afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const MANAGER = ['attendance.record.own', 'site.view.own', 'employee.crew.own', 'employee.propose_laborer.own']
const LEAVE = { absent: 'leave-absent', sick: 'leave-sick', rest: 'leave-rest', other: 'leave-other' }
const site = (id: string, name: string) => ({ id, name_ar: name, name_en: name, kind: 'work', active: true })
const day = (extra = {}) => ({ state: 'missing', version: null, site_id: null, leave_code: null, in_time: null, out_time: null, note: null, ...extra })
const row = (id: string, name: string, type: string, section: string, d = {}, extra = {}) => ({
  employee: { id, code: 1, name_ar: name, name_en: name, type }, section, shift: { start: '08:00', end: '16:00' }, day: day(d), can_edit: true, why_not: null, departure_only: false, open_date: null, open_site_id: null, ...extra,
})
const answer = (s: { id: string; name_ar: string }, rows: unknown[]) => ({ site: { ...s, name_en: s.name_ar }, date: '2026-10-22', today: '2026-10-22', leave_day_types: LEAVE, rows })

const S1 = site('s1', 'Site-1')
const S2 = site('s2', 'Site-2')
const S1_ROWS = [
  row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
  row('w2', 'Worker-2', 'permanent_worker', 'workers', { state: 'stored', version: 1, site_id: 'leave-absent', leave_code: 'absent' }),
  row('e1', 'Engineer-1', 'engineer', 'engineers'),
]

function Url() {
  return <output data-testid="url">{useLocation().search}</output>
}
const render = (path = '/site-day', perms = MANAGER) => renderWithAuth(<><SiteDayPage /><Url /></>, perms, { path })
/** The entry step: toggle these sites in the picker, then «متابعة». */
async function enter(toggle: string[] = []) {
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  for (const name of toggle) await user.click(within(list).getByRole('checkbox', { name }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
}

type Handler = (url: string, init?: RequestInit) => Response
function routes(sites: unknown[], days: Record<string, unknown[]>, more: Record<string, Handler> = {}) {
  const siteDay = vi.fn((url: string) => {
    const id = new URL(url, 'http://x').searchParams.get('site_id')!
    return json(200, { data: answer(id === 's2' ? S2 : S1, days[id] ?? []) })
  })
  const post = vi.fn((_url: string, init?: RequestInit) => {
    const body = JSON.parse(String(init?.body))
    return json(200, { data: answer(body.site_id === 's2' ? S2 : S1, days[body.site_id] ?? []) })
  })
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination: { ...pagination, total: sites.length } } }),
    'GET /api/v1/attendance/site-day': siteDay,
    'POST /api/v1/attendance/site-day': post,
    ...more,
  })
  return { siteDay, post }
}
const bodyOf = (fn: { mock: { calls: unknown[][] } }, i = 0) => JSON.parse(String((fn.mock.calls[i][1] as RequestInit).body))
const group = (name: string) => screen.getByRole('group', { name })

test('a site manager saves a requested advance with the attendance day', async () => {
  const { post } = routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  const worker = group('Worker-1').closest('li')!
  await user.click(within(worker).getByRole('button', { name: 'طلب سلفة' }))
  await user.type(within(worker).getByRole('textbox', { name: 'مبلغ السلفة' }), '٢٥٠.٥٠')
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([expect.objectContaining({
    employee_id: 'w1', advance_request: '250.50',
  })])
})

test('one site: no switcher, the engineers section then the workers, and the stored marks pressed', async () => {
  const { siteDay } = routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  const list = await screen.findByRole('list', { name: 'العاملون بالموقع' })
  expect(screen.getByRole('heading', { level: 1, name: 'Site-1' })).toBeInTheDocument()
  expect(within(screen.getByRole('navigation', { name: 'الموقع' })).queryAllByRole('button')).toHaveLength(1) // «تغيير المواقع» only
  expect(siteDay.mock.calls[0][0]).toContain('site_id=s1')
  const items = within(list).getAllByRole('listitem').map((li) => li.textContent)
  expect(items[0]).toBe('المهندسون')
  expect(items[1]).toContain('Engineer-1')
  expect(items[2]).toBe('العمال')
  expect(items[3]).toContain('Worker-1')
  expect(items[4]).toContain('Worker-2')
  expect(within(group('Worker-1')).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Worker-2')).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
  expect(group('Engineer-1').querySelector('[aria-pressed="true"]')).toBeNull()
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()
})

test('engineers stay first after filtering and after a save, and «حضور الكل» still covers everyone', async () => {
  // the server lists workers first; the screen puts the engineers on top
  const many = [
    ...['1', '2', '3', '4', '5'].map((n) => row(`w${n}`, `Worker-${n}`, 'day_laborer', 'workers')),
    row('e1', 'Engineer-1', 'engineer', 'engineers'),
    row('e2', 'Engineer-2', 'engineer', 'engineers'),
  ]
  const { post } = routes([S1], { s1: many })
  render()
  await enter()
  const names = () => within(screen.getByRole('list', { name: 'العاملون بالموقع' })).getAllByRole('group').map((g) => g.getAttribute('aria-label'))
  await screen.findByRole('group', { name: 'Engineer-1' })
  expect(names()).toEqual(['Engineer-1', 'Engineer-2', 'Worker-1', 'Worker-2', 'Worker-3', 'Worker-4', 'Worker-5'])

  await user.type(screen.getByRole('searchbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' }), '2')
  expect(names()).toEqual(['Engineer-2', 'Worker-2'])
  await user.clear(screen.getByRole('searchbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' }))

  await user.click(screen.getByRole('button', { name: 'حضور الكل (7)' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toHaveLength(7)
  expect(names()).toEqual(['Engineer-1', 'Engineer-2', 'Worker-1', 'Worker-2', 'Worker-3', 'Worker-4', 'Worker-5'])
})

test('morning: «حضور الكل» fills the shift start, keeps a typed arrival, an absence and approved leave, and saves only the changes', async () => {
  const { post } = routes([S1], {
    s1: [
      row('a', 'Laborer-A', 'day_laborer', 'workers'),
      row('b', 'Laborer-B', 'day_laborer', 'workers'),
      row('c', 'Worker-C', 'permanent_worker', 'workers', { state: 'stored', version: 2, site_id: 'leave-absent', leave_code: 'absent' }),
      row('d', 'Worker-D', 'permanent_worker', 'workers', { state: 'stored', version: 3, site_id: 'leave-annual', leave_code: 'annual' }, { can_edit: false, why_not: 'hr_day' }),
      row('e', 'Engineer-E', 'engineer', 'engineers'),
    ],
  })
  render()
  await enter()
  await screen.findByRole('group', { name: 'Laborer-A' })
  await user.click(within(group('Laborer-B')).getByRole('button', { name: 'حاضر' }))
  const arrivalB = within(group('Laborer-B')).getByRole('textbox', { name: 'حضور Laborer-B' })
  await user.clear(arrivalB)
  await user.type(arrivalB, '9:15')
  expect(within(group('Laborer-B')).getByText('تأخير 75 دقيقة')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حضور الكل (2)' }))
  expect(screen.getByRole('button', { name: 'حضور الكل (0)' })).toBeDisabled()
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'حضور Laborer-A' })).toHaveValue('8:00')
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'انصراف Laborer-A' })).toHaveValue('')
  expect(within(group('Laborer-A')).getByText('بلا انصراف')).toBeInTheDocument()
  expect(within(group('Worker-C')).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Worker-D')).getByText(/سجّلته الموارد البشرية؛ تعدّله هي فقط/)).toBeInTheDocument()
  for (const b of within(group('Worker-D')).getAllByRole('button')) expect(b).toBeDisabled()
  expect(screen.getByText('تغييرات غير محفوظة: 3')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post)).toEqual({
    site_id: 's1',
    date: '2026-10-22',
    rows: [
      { employee_id: 'a', version: null, site_id: 's1', in_time: '08:00', out_time: null },
      { employee_id: 'b', version: null, site_id: 's1', in_time: '09:15', out_time: null },
      { employee_id: 'e', version: null, site_id: 's1', in_time: '08:00', out_time: null },
    ],
  })
  expect(await screen.findByText('تم الحفظ.')).toBeInTheDocument()
})

test('evening: «انصراف الكل» fills the shift end, keeps a typed departure, and shows early and late departures', async () => {
  const { post } = routes([S1], {
    s1: [
      row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('b', 'Laborer-B', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('c', 'Laborer-C', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('d', 'Worker-D', 'permanent_worker', 'workers', { state: 'stored', version: 1, site_id: 'leave-sick', leave_code: 'sick' }),
    ],
  })
  render()
  await enter()
  await screen.findByRole('group', { name: 'Laborer-A' })
  expect(screen.getByText('بلا انصراف: 3')).toBeInTheDocument()
  const outB = within(group('Laborer-B')).getByRole('textbox', { name: 'انصراف Laborer-B' })
  await user.type(outB, '3:00')
  await user.click(within(group('Laborer-B')).getAllByRole('button', { name: 'مساءً' })[1])
  expect(within(group('Laborer-B')).getByText('انصراف مبكر 60 دقيقة')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'انصراف الكل (2)' }))
  const outC = within(group('Laborer-C')).getByRole('textbox', { name: 'انصراف Laborer-C' })
  expect(outC).toHaveValue('4:00')
  await user.clear(outC)
  await user.type(outC, '5:30')
  expect(within(group('Laborer-C')).getByText(/إضافي بعد الموعد 90 دقيقة/)).toBeInTheDocument()
  expect(screen.getByText('بلا انصراف: 0')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([
    { employee_id: 'a', version: 1, site_id: 's1', in_time: '08:00', out_time: '16:00' },
    { employee_id: 'b', version: 1, site_id: 's1', in_time: '08:00', out_time: '15:00' },
    { employee_id: 'c', version: 1, site_id: 's1', in_time: '08:00', out_time: '17:30' },
  ])
})

test('entry: the picker comes first, takes several sites, and the working list opens one site at a time', async () => {
  const { siteDay } = routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(screen.queryByRole('list', { name: 'العاملون بالموقع' })).toBeNull()
  expect(siteDay).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'متابعة' })).toBeDisabled()
  await user.click(within(list).getByRole('checkbox', { name: 'Site-1' }))
  await user.click(within(list).getByRole('checkbox', { name: 'Site-2' }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-1' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.siteDay.sites')).toBe('["s1","s2"]')
  expect(siteDay.mock.calls.every(([url]) => String(url).includes('site_id=s1') || String(url).includes('site_id=s2'))).toBe(true)
})

test('two sites: switching keeps each site its own roster and unsaved changes', async () => {
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  await enter(['Site-1', 'Site-2'])
  const nav = await screen.findByRole('navigation', { name: 'الموقع' })
  await screen.findByRole('group', { name: 'Engineer-1' })
  await user.click(within(group('Engineer-1')).getByRole('button', { name: 'غياب' }))
  expect(within(nav).getByRole('button', { name: /Site-1/ })).toHaveTextContent('غير محفوظ')

  await user.click(within(nav).getByRole('button', { name: /Site-2/ }))
  expect(await screen.findByRole('group', { name: 'Worker-3' })).toBeInTheDocument()
  expect(screen.queryByRole('group', { name: 'Engineer-1' })).toBeNull()
  expect(screen.getByTestId('url')).toHaveTextContent('site=s2')
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()

  await user.click(within(nav).getByRole('button', { name: /Site-1/ }))
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
})

test('«تغيير المواقع» reopens the picker; a site left out keeps its draft, which comes back when chosen again', async () => {
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  await enter(['Site-1', 'Site-2'])
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' }))
  await user.click(screen.getByRole('button', { name: 'تغيير المواقع' }))
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(within(list).getByRole('checkbox', { name: /Site-1/ })).toHaveAttribute('aria-checked', 'true')
  await user.click(within(list).getByRole('checkbox', { name: /Site-1/ }))
  expect(screen.getByText(/في Site-1 تغييرات غير محفوظة/)).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-2' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Site-1/ })).toBeNull()

  await user.click(screen.getByRole('button', { name: 'تغيير المواقع' }))
  await user.click(within(await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })).getByRole('checkbox', { name: /Site-1/ }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  await user.click(await screen.findByRole('button', { name: /^Site-1/ }))
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
})

test('the last working list is preselected, without sites no longer assigned', async () => {
  localStorage.setItem('hrms.siteDay.sites', '["s2","gone"]')
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(within(list).getByRole('checkbox', { name: 'Site-2' })).toHaveAttribute('aria-checked', 'true')
  expect(within(list).getByRole('checkbox', { name: 'Site-1' })).toHaveAttribute('aria-checked', 'false')
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-2' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.siteDay.sites')).toBe('["s2"]')
})

test('with one assigned site it is preselected: one tap continues', async () => {
  routes([S1], { s1: S1_ROWS })
  render()
  expect(within(await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })).getByRole('checkbox', { name: 'Site-1' })).toHaveAttribute('aria-checked', 'true')
})

test('an empty site still offers «إضافة عامل يومية», with the bulk actions off', async () => {
  routes([S1], { s1: [] })
  render()
  await enter()
  expect(await screen.findByText('لا يوجد عاملون مسجلون بهذا الموقع في هذا اليوم.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'إضافة عامل يومية' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'حضور الكل (0)' })).toBeDisabled()
})

test('while a save runs the rows are read-only, and a failed save keeps the draft', async () => {
  let finish: (r: Response) => void = () => {}
  const post = vi.fn(() => new Promise<Response>((resolve) => { finish = resolve }) as unknown as Response)
  routes([S1], { s1: S1_ROWS }, { 'POST /api/v1/attendance/site-day': post })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'مرضي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText('جارٍ الحفظ…')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'غياب' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()
  finish(json(500, { error: { code: 'server_error', message: 'تعذّر الحفظ', details: [] } }))
  expect(await screen.findByText('تعذّر الحفظ')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Engineer-1')).getByRole('button', { name: 'غياب' })).toBeEnabled()
})

test('«⋯» offers rest and other only, never leave that needs a request', async () => {
  routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'أنواع إجازات أخرى' }))
  const items = (await screen.findAllByRole('menuitem')).map((i) => i.textContent)
  expect(items).toEqual(['راحة', 'أخرى'])
})

test('a 409 that names no row (a racing insert) keeps the changes, and load latest resets the whole day', async () => {
  const { siteDay } = routes([S1], { s1: S1_ROWS }, {
    'POST /api/v1/attendance/site-day': () => json(409, { error: { code: 'concurrency_conflict', message: 'تغيّر', details: [] } }),
  })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'مرضي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText(/عدّل شخص آخر هذا اليوم/)).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  const calls = siteDay.mock.calls.length
  await user.click(screen.getByRole('button', { name: 'تحميل الأحدث' }))
  await waitFor(() => expect(siteDay.mock.calls.length).toBeGreaterThan(calls))
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'false')
})

test('a 409 that names one row: load latest resets only that row, and the other drafts stay', async () => {
  // rows.N indexes the sent rows: Worker-1 (rows.0) was saved by another site manager, Engineer-1 (rows.1) was not
  let latest = S1_ROWS
  routes([S1], { s1: S1_ROWS }, {
    'GET /api/v1/attendance/site-day': () => json(200, { data: answer(S1, latest) }),
    'POST /api/v1/attendance/site-day': () => json(409, { error: { code: 'concurrency_conflict', message: 'تغيّر', details: [{ field: 'rows.0', code: 'concurrency_conflict', message: 'تغيّر' }] } }),
  })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Worker-1' })).getByRole('button', { name: 'غياب' }))
  await user.click(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText(/عدّل شخص آخر يوم Worker-1 قبل حفظك/)).toBeInTheDocument()

  latest = [row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 2, site_id: 'leave-sick', leave_code: 'sick' }), ...S1_ROWS.slice(1)]
  await user.click(screen.getByRole('button', { name: 'تحميل الأحدث' }))
  expect(await within(group('Worker-1')).findByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Worker-1')).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'false')
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByText('تغييرات غير محفوظة: 1')).toBeInTheDocument()
})

test('a day laborer with nothing recorded can be removed from today; one already recorded cannot', async () => {
  const crew = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { employee_id: 'x', action: 'remove' } }))
  routes([S1], { s1: [row('x', 'Laborer-X', 'day_laborer', 'workers'), ...S1_ROWS] }, { 'POST /api/v1/attendance/site-day/crew': crew })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Laborer-X' })).getByRole('button', { name: 'إخراج Laborer-X من طاقم اليوم' }))
  await waitFor(() => expect(crew).toHaveBeenCalledTimes(1))
  expect(bodyOf(crew)).toEqual({ site_id: 's1', employee_id: 'x', action: 'remove' })
  expect(within(group('Worker-1')).queryByRole('button', { name: /إخراج/ })).toBeNull()
  expect(within(group('Engineer-1')).queryByRole('button', { name: /إخراج/ })).toBeNull()
})

test('«إضافة عامل يومية» finds one laborer by code or full name, then proposes a new one with their gender', async () => {
  const crew = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: {} }))
  const create = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: { id: 'n1', code: 77, name_ar: 'سعيد' } }))
  const found: Record<string, unknown> = {
    '12': { match: { id: 'f1', code: 12, name_ar: 'سعيد علي', name_en: null, site: null, here: false, can_add: true }, ambiguous: false },
    '13': { match: { id: 'f2', code: 13, name_ar: 'سعيد حسن', name_en: null, site: { id: 's9', name_ar: 'Site-9', name_en: null }, here: false, can_add: false }, ambiguous: false },
    'محمد علي': { match: null, ambiguous: true },
  }
  const search = vi.fn((url: string) => json(200, { data: found[new URL(url, 'http://x').searchParams.get('search')!] ?? { match: null, ambiguous: false } }))
  routes([S1], { s1: S1_ROWS }, {
    'GET /api/v1/attendance/site-day/laborers': search,
    'POST /api/v1/attendance/site-day/crew': crew,
    'POST /api/v1/attendance/site-day/laborers': create,
  })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  const box = within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ })
  const matches = () => within(dialog).getByRole('region', { name: 'عمال مسجلون بهذا الاسم' })

  await user.type(box, '13')
  expect(await within(dialog).findByText('على موقع آخر؛ تواصل مع الموارد البشرية')).toBeInTheDocument()
  expect(within(matches()).queryByRole('button', { name: 'إضافة لطاقم اليوم' })).toBeNull()
  expect(within(dialog).queryByRole('region', { name: 'عامل جديد' })).toBeNull()   // a code never proposes a new laborer

  await user.clear(box)
  await user.type(box, 'محمد علي')
  expect(await within(dialog).findByText('أكثر من عامل بهذا الاسم؛ اكتب الرقم الوظيفي.')).toBeInTheDocument()
  expect(within(dialog).queryByRole('region', { name: 'عامل جديد' })).toBeNull()

  await user.clear(box)
  await user.type(box, 'سعيد')
  expect(await within(dialog).findByText('لا يوجد عامل يومية بهذا الرقم أو الاسم الكامل.')).toBeInTheDocument()
  expect(within(dialog).getByText('اكتب الاسم الثلاثي على الأقل لإضافة عامل جديد.')).toBeInTheDocument()   // one word: no new laborer
  await user.type(box, '  محمود   علي')
  await waitFor(() => expect(search.mock.calls.some(([u]) => new URL(String(u), 'http://x').searchParams.get('search') === 'سعيد محمود علي')).toBe(true))
  const add = await within(dialog).findByRole('button', { name: 'إضافة العامل الجديد' })
  expect(within(dialog).queryByText('اكتب الاسم الثلاثي على الأقل لإضافة عامل جديد.')).toBeNull()
  await user.type(within(dialog).getByRole('textbox', { name: 'الأجر اليومي المقترح' }), '350')
  await user.type(within(dialog).getByRole('textbox', { name: 'رقم الموبايل (اختياري)' }), '01012345678')
  expect(add).toBeDisabled()                                                          // the gender is required
  await user.click(within(dialog).getByRole('button', { name: 'ذكر' }))
  await user.click(add)
  await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
  expect(bodyOf(create)).toEqual({
    site_id: 's1', name_ar: 'سعيد محمود علي', gender: 'male', daily_wage: '350', phone: '01012345678', national_id: null, client_request_id: expect.any(String), confirm_new: false,
  })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

  await user.click(screen.getByRole('button', { name: 'إضافة عامل يومية' }))
  await user.type(within(await screen.findByRole('dialog')).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ }), '12')
  await user.click(await screen.findByRole('button', { name: 'إضافة لطاقم اليوم' }))
  await waitFor(() => expect(crew).toHaveBeenCalledTimes(1))
  expect(bodyOf(crew)).toEqual({ site_id: 's1', employee_id: 'f1', action: 'add' })
  expect(search.mock.calls.every(([u]) => new URL(String(u), 'http://x').searchParams.get('search') !== 'سعي')).toBe(true)   // typing settles before it asks
})

test('a new laborer: the national ID takes Arabic digits and needs 14, a matching name shows the match, and «شخص آخر» resends confirmed', async () => {
  const match = { id: 'f7', code: 7, name_ar: 'محمد أحمد علي', name_en: null, site: { id: 's1', name_ar: 'Site-1', name_en: null }, here: true, can_add: false }
  let calls = 0
  const create = vi.fn((_url: string, _init?: RequestInit) => {
    calls += 1
    if (calls === 1) return json(500, { error: { code: 'server_error', message: 'تعذّر الحفظ' } })   // a failed try: the retry reuses the form id
    if (calls === 2) return json(409, { error: { code: 'laborer_exists', message: 'يوجد عامل يومية مسجل بهذا الاسم الكامل.', reason: 'name', match, ambiguous: false } })
    return json(201, { data: { id: 'n2', code: 78, name_ar: 'محمد أحمد علي' } })
  })
  routes([S1], { s1: S1_ROWS }, {
    'GET /api/v1/attendance/site-day/laborers': () => json(200, { data: { match: null, ambiguous: false } }),
    'POST /api/v1/attendance/site-day/laborers': create,
  })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ }), 'محمد أحمد علي')
  const add = await within(dialog).findByRole('button', { name: 'إضافة العامل الجديد' })
  await user.click(within(dialog).getByRole('button', { name: 'ذكر' }))
  await user.type(within(dialog).getByRole('textbox', { name: 'الأجر اليومي المقترح' }), '٣٥٠٫٥')
  const nid = within(dialog).getByRole('textbox', { name: 'الرقم القومي (اختياري)' })
  await user.type(nid, '٠١٢٣٤٥')
  expect(nid).toHaveValue('012345')
  expect(within(dialog).getByText('الرقم القومي ١٤ رقمًا.')).toBeInTheDocument()
  expect(add).toBeDisabled()
  await user.type(nid, '۶۷۸۹٠١٢٣')
  expect(within(dialog).queryByText('الرقم القومي ١٤ رقمًا.')).toBeNull()

  await user.click(add)
  await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
  const first = bodyOf(create, 0)
  expect(first).toMatchObject({ name_ar: 'محمد أحمد علي', daily_wage: '350.5', national_id: '01234567890123', confirm_new: false })
  await user.click(add)                                                                  // the retry after a failure
  await waitFor(() => expect(create).toHaveBeenCalledTimes(2))
  expect(bodyOf(create, 1).client_request_id).toBe(first.client_request_id)
  const known = await within(dialog).findByRole('region', { name: 'العامل المسجل بالفعل' })
  expect(within(known).getByText('محمد أحمد علي')).toBeInTheDocument()
  expect(within(known).getByText('في هذا الموقع')).toBeInTheDocument()

  await user.click(within(dialog).getByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' }))
  await user.click(within(dialog).getByRole('button', { name: 'إضافة العامل الجديد' }))
  await waitFor(() => expect(create).toHaveBeenCalledTimes(3))
  expect(bodyOf(create, 2)).toMatchObject({ confirm_new: true, client_request_id: first.client_request_id })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('without the crew permissions there is no add or remove', async () => {
  routes([S1], { s1: [row('x', 'Laborer-X', 'day_laborer', 'workers')] })
  render('/site-day', ['attendance.record.own', 'site.view.own'])
  await enter()
  await screen.findByRole('group', { name: 'Laborer-X' })
  expect(screen.queryByRole('button', { name: 'إضافة عامل يومية' })).toBeNull()
  expect(screen.queryByRole('button', { name: /إخراج/ })).toBeNull()
})

test('an engineer at another site shows that site', async () => {
  routes([S1, S2], {
    s1: [
      row('e1', 'Engineer-1', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's2' }),
      row('e2', 'Engineer-2', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's9' }),
    ],
  })
  render('/site-day?site=s1')
  await enter(['Site-1'])
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByText('في موقع: Site-2')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'false')
  expect(within(group('Engineer-2')).getByText('في موقع آخر')).toBeInTheDocument()
})

test('request-only leave is never offered; a day already stored with it shows its name and nothing pressed', async () => {
  // annual, casual and unpaid come from an approved leave request: a site manager's write to them is 422 leave_needs_request
  routes([S1], { s1: [
    row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 'leave-annual', leave_code: 'annual' }),
    row('w2', 'Worker-2', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 'leave-casual', leave_code: 'casual' }),
    row('w3', 'Worker-3', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 'leave-unpaid', leave_code: 'unpaid' }),
  ] })
  render('/site-day?site=s1')
  await enter()
  const list = await screen.findByRole('list', { name: 'العاملون بالموقع' })
  for (const [name, leave] of [['Worker-1', 'إجازة اعتيادية'], ['Worker-2', 'إجازة عارضة'], ['Worker-3', 'إجازة بدون أجر']]) {
    const g = within(list).getByRole('group', { name })
    expect(within(g).getByText(leave)).toBeInTheDocument()
    expect(within(g).getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent)).toEqual(['حاضر', 'غياب', 'مرضي', 'أنواع إجازات أخرى']) // «أنواع إجازات أخرى» is disabled until the day editor lands; it must leave these three out too
    expect(g.querySelector('[aria-pressed="true"]')).toBeNull()
  }
  expect(screen.queryByRole('button', { name: /اعتيادية|عارضة|بدون أجر/ })).toBeNull()
})

test('no sites shows the empty state with HR as the contact', async () => {
  const { siteDay } = routes([], {})
  render()
  expect(await screen.findByText('لا توجد مواقع مسندة إليك. تواصل مع الموارد البشرية.')).toBeInTheDocument()
  await waitFor(() => expect(siteDay).not.toHaveBeenCalled())
})

test('a site with nobody on the day shows the empty list state', async () => {
  routes([S1], { s1: [] })
  render()
  await enter()
  expect(await screen.findByText('لا يوجد عاملون مسجلون بهذا الموقع في هذا اليوم.')).toBeInTheDocument()
})

test('a refused day shows the error', async () => {
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: [S1], meta: { pagination } }),
    'GET /api/v1/attendance/site-day': () => json(422, { error: { code: 'future_date', message: 'لا يمكن تسجيل يوم في المستقبل.', details: [] } }),
  })
  render('/site-day?date=2026-10-30')
  await enter()
  expect(await screen.findByRole('alert')).toHaveTextContent('لا يمكن تسجيل يوم في المستقبل.')
})

test('a time that is not a time stops the save and names the person', async () => {
  const { post } = routes([S1], { s1: [row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' })] })
  render()
  await enter()
  await user.type(await screen.findByRole('textbox', { name: 'انصراف Laborer-A' }), '3:303:30')
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText(/وقت غير صحيح عند: Laborer-A/)).toBeInTheDocument()
  expect(post).not.toHaveBeenCalled()
})

test('an earlier day without a departure: the banner names it, the row is locked, and a link opens that day at its site', async () => {
  const { siteDay } = routes([S1, S2], { s1: [
    row('e1', 'Engineer-1', 'engineer', 'engineers', {}, { can_edit: false, why_not: 'open_day', open_date: '2026-10-21', open_site_id: 's2' }),
    row('e2', 'Engineer-2', 'engineer', 'engineers', {}, { can_edit: false, why_not: 'open_day', open_date: '2026-10-20', open_site_id: null }),
  ] })
  render('/site-day?site=s1')
  await enter(['Site-1'])
  const banner = await screen.findByText(/بلا انصراف في يوم سابق \(2\)/)
  const alert = banner.closest('[role="alert"], [role="status"], div')!
  expect(within(alert as HTMLElement).getByText(/Engineer-2/)).toBeInTheDocument()
  expect(within(alert as HTMLElement).getByText(/تكمّله الموارد البشرية/)).toBeInTheDocument()
  const g = await screen.findByRole('group', { name: 'Engineer-1' })
  expect(within(g).getByText(/انصراف يوم 2026-10-21 ناقص/)).toBeInTheDocument()
  expect(within(g).getByRole('button', { name: 'حاضر' })).toBeDisabled()
  const g2 = screen.getByRole('group', { name: 'Engineer-2' })
  expect(within(g2).getByText(/انصراف يوم 2026-10-20 ناقص؛ تكمّله الموارد البشرية/)).toBeInTheDocument()
  expect(within(g2).queryByRole('button', { name: /افتح يوم/ })).toBeNull()
  await user.click(within(g).getByRole('button', { name: 'افتح يوم 2026-10-21 وسجّل الانصراف' }))
  expect(screen.getByTestId('url')).toHaveTextContent('date=2026-10-21')
  expect(screen.getByTestId('url')).toHaveTextContent('site=s2')                       // the site it was recorded at, added to the working list
  await waitFor(() => expect(siteDay.mock.calls.some(([u]) => String(u).includes('site_id=s2') && String(u).includes('date=2026-10-21'))).toBe(true))
})

test('past the window an open day takes only its departure', async () => {
  const { post } = routes([S1], { s1: [row('e1', 'Engineer-1', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:10' }, { departure_only: true })] })
  render()
  await enter()
  const g = await screen.findByRole('group', { name: 'Engineer-1' })
  expect(within(g).getByText(/سجّل الانصراف فقط، وتُبلَّغ الموارد البشرية/)).toBeInTheDocument()
  expect(within(g).getByRole('button', { name: 'غياب' })).toBeDisabled()
  expect(within(g).getByRole('textbox', { name: 'حضور Engineer-1' })).toBeDisabled()
  await user.type(within(g).getByRole('textbox', { name: 'انصراف Engineer-1' }), '4:00')
  await user.click(within(g).getAllByRole('button', { name: 'مساءً' })[1])
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([{ employee_id: 'e1', version: 1, site_id: 's1', in_time: '08:10', out_time: '16:00' }])
})

test('the bulk actions are «حضور الكل» and «انصراف الكل» only: «زي امبارح» was removed', async () => {
  routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  const bulk = await screen.findByRole('group', { name: /Site-1/ })
  expect(within(bulk).queryByRole('button', { name: /زي امبارح/ })).toBeNull()
  expect(within(bulk).getByRole('button', { name: /حضور الكل/ })).toBeInTheDocument()
  expect(within(bulk).getByRole('button', { name: /انصراف الكل/ })).toBeInTheDocument()
})

test('a note is typed on the row and saved with it', async () => {
  const { post } = routes([S1], { s1: [row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 2, site_id: 's1', in_time: '09:00' })] })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة ملاحظة' }))
  await user.type(screen.getByRole('textbox', { name: 'ملاحظة Laborer-A' }), 'وصل متأخر بسبب المواصلات')
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([{ employee_id: 'a', version: 2, site_id: 's1', in_time: '09:00', out_time: null, note: 'وصل متأخر بسبب المواصلات' }])
})

test('a day stored at another of the manager\'s sites takes no note here, so the save never sends that site', async () => {
  routes([S1, S2], { s1: [row('e1', 'Engineer-1', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's2', in_time: '09:00' })] })
  render('/site-day?site=s1')
  await enter(['Site-1'])
  const g = await screen.findByRole('group', { name: 'Engineer-1' })
  expect(within(g).getByText('في موقع: Site-2')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /إضافة ملاحظة|ملاحظة:/ })).toBeNull()
})

test('a long note shows on one line, cut short, so the card keeps its width', async () => {
  const long = 'ملاحظة طويلة '.repeat(40).trim()
  routes([S1], { s1: [row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '09:00', note: long })] })
  render()
  await enter()
  const label = await screen.findByText(`ملاحظة: ${long}`)
  expect(label).toHaveClass('truncate')
  expect(label.closest('button')).toHaveClass('max-w-full')
})

test('the filter box shows on a small site too and finds a code typed in Arabic digits (Youssef, 2026-11-19)', async () => {
  const rows = [row('w1', 'Worker-1', 'day_laborer', 'workers', {}, {}), { ...row('w2', 'Worker-2', 'permanent_worker', 'workers'), employee: { id: 'w2', code: 12, name_ar: 'Worker-2', name_en: 'Worker-2', type: 'permanent_worker' } }]
  routes([S1], { s1: rows })
  render()
  await enter()
  const box = await screen.findByRole('searchbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })
  await user.type(box, '١٢')
  const names = () => within(screen.getByRole('list', { name: 'العاملون بالموقع' })).getAllByRole('group').map((g) => g.getAttribute('aria-label'))
  expect(names()).toEqual(['Worker-2'])
})

test('«إضافة عامل يومية»: «١٢» is a code, so a match by code never offers «شخص آخر بنفس الاسم» (moved from #69)', async () => {
  const search = vi.fn((url: string) => json(200, {
    data: new URL(url, 'http://x').searchParams.get('search') === '12'
      ? { match: { id: 'f1', code: 12, name_ar: 'سعيد علي حسن', name_en: null, site: null, here: false, can_add: true }, ambiguous: false }
      : { match: null, ambiguous: false },
  }))
  routes([S1], { s1: S1_ROWS }, { 'GET /api/v1/attendance/site-day/laborers': search })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ }), '١٢')
  expect(await within(dialog).findByText('سعيد علي حسن')).toBeInTheDocument()   // searched as code 12
  expect(within(dialog).queryByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' })).toBeNull()
  expect(within(dialog).queryByRole('region', { name: 'عامل جديد' })).toBeNull()
})

test('«شخص آخر بنفس الاسم» holds for its name only: a new matching name asks again and sends no confirm_new (review of #72)', async () => {
  const match = (name: string) => ({ id: name, code: 7, name_ar: name, name_en: null, site: null, here: false, can_add: false })
  const create = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: { id: 'n1', code: 9, name_ar: 'x' } }))
  routes([S1], { s1: S1_ROWS }, {
    'GET /api/v1/attendance/site-day/laborers': (url: string) => json(200, { data: { match: match(new URL(url, 'http://x').searchParams.get('search')!), ambiguous: false } }),
    'POST /api/v1/attendance/site-day/laborers': create,
  })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  const box = within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ })
  await user.type(box, 'محمد أحمد علي')
  await user.click(await within(dialog).findByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' }))
  expect(await within(dialog).findByRole('region', { name: 'عامل جديد' })).toBeInTheDocument()
  await user.clear(box)
  await user.type(box, 'محمد أحمد حسن')                                                // another name, another existing laborer
  expect(await within(dialog).findByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' })).toBeInTheDocument()
  await waitFor(() => expect(within(dialog).queryByRole('region', { name: 'عامل جديد' })).toBeNull())
  expect(create).not.toHaveBeenCalled()
})

test('«شخص آخر» pressed on X while the next name loads does not confirm Z, a match the manager never saw (review of #72, 2026-11-22)', async () => {
  let release: (r: Response) => void = () => {}
  const match = (id: string, name: string, code: number) => ({ id, code, name_ar: name, name_en: null, site: null, here: false, can_add: false })
  const search = vi.fn((url: string) => {
    const q = new URL(url, 'http://x').searchParams.get('search')
    if (q === 'محمد أحمد علي') return json(200, { data: { match: match('X', 'محمد أحمد علي', 101), ambiguous: false } })
    return new Promise<Response>((resolve) => { release = resolve }) as unknown as Response   // the second name's search is held
  })
  const create = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: { id: 'n1', code: 9, name_ar: 'x', proposal_id: 'p1' } }))
  routes([S1], { s1: S1_ROWS }, { 'GET /api/v1/attendance/site-day/laborers': search, 'POST /api/v1/attendance/site-day/laborers': create })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  const box = within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ })
  await user.type(box, 'محمد أحمد علي')
  expect(await within(dialog).findByText('#101')).toBeInTheDocument()                        // X's card
  await user.clear(box)
  await user.type(box, 'محمد أحمد حسن')
  await waitFor(() => expect(search.mock.calls.some(([u]) => new URL(String(u), 'http://x').searchParams.get('search') === 'محمد أحمد حسن')).toBe(true))
  expect(within(dialog).getByText('#101')).toBeInTheDocument()                               // X still shown while Z's search is in flight
  await user.click(within(dialog).getByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' }))

  release(json(200, { data: { match: match('Z', 'محمد أحمد حسن', 202), ambiguous: false } }))
  expect(await within(dialog).findByText('#202')).toBeInTheDocument()                        // Z's card arrives
  // the page asks again, for Z, and shows no new-laborer form until the manager answers
  expect(await within(dialog).findByRole('button', { name: 'شخص آخر بنفس الاسم: إضافة عامل جديد' })).toBeInTheDocument()
  expect(within(dialog).queryByRole('region', { name: 'عامل جديد' })).toBeNull()
  expect(create).not.toHaveBeenCalled()
})

test('typing more of a name keeps the new-laborer form in place while the next search loads (no flash, Youssef 2026-11-19)', async () => {
  let release: (r: Response) => void = () => {}
  const search = vi.fn((url: string) => {
    const q = new URL(url, 'http://x').searchParams.get('search')
    if (q === 'محمد علي') return json(200, { data: { match: null, ambiguous: false } })
    return new Promise<Response>((resolve) => { release = resolve }) as unknown as Response   // the second search stays pending
  })
  routes([S1], { s1: S1_ROWS }, { 'GET /api/v1/attendance/site-day/laborers': search })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  const box = within(dialog).getByRole('textbox', { name: /الرقم الوظيفي أو الاسم الكامل/ })
  await user.type(box, 'محمد علي')
  expect(await within(dialog).findByRole('region', { name: 'عامل جديد' })).toBeInTheDocument()
  await user.type(box, ' حسن')
  await waitFor(() => expect(search).toHaveBeenCalledTimes(2))
  expect(within(dialog).getByRole('region', { name: 'عامل جديد' })).toBeInTheDocument()   // still mounted while the request is pending
  expect(within(dialog).queryByText('جارٍ البحث…')).toBeNull()
  release(json(200, { data: { match: null, ambiguous: false } }))
})
