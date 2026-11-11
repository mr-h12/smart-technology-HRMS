import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'

const user = userEvent.setup()
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})

const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 19 }
const cell = (date: string, state: string, extra = {}) => ({ date, state, source: state === 'stored' ? 'entered' : null, site_id: null, leave_code: null, ...extra })
const rows = [{ employee: { id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1' },
  days: [cell('2026-10-01', 'stored', { site_id: 's1' }), cell('2026-10-02', 'derived', { leave_code: 'friday' }), cell('2026-10-03', 'missing'), cell('2026-10-21', 'future')] }]
const sites = [{ id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', outside_alexandria: false, active: true }]
const routes = {
  'GET /api/v1/attendance/period': () => json(200, { data: period }),
  'GET /api/v1/attendance/grid': () => json(200, { data: rows, meta: { pagination } }),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
}

test('the grid shows each employee per day with missing and derived days marked', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-02: جمعة' })).toHaveClass('border-dashed', 'border-muted-foreground/60') // the default border is too faint in dark mode
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' })).toHaveClass('bg-warning/15')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toHaveClass('focus-visible:outline-solid') // outline-none alone would hide the focus ring
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-21: لم يأتِ بعد' })).toBeDisabled()
})

test('the header line under the title names the period and its missing count', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const line = await screen.findByText(/أيام غير مسجلة: 19/)
  expect(line).toHaveTextContent('الفترة من 2026-09-26 إلى 2026-10-25 · أيام غير مسجلة: 19')
  for (const d of ['2026-09-26', '2026-10-25']) expect(within(line).getByText(d)).toHaveAttribute('dir', 'ltr')
  expect(screen.getByRole('heading', { level: 1 }).nextElementSibling).toBe(line)
})

test.each([
  ['grid', 'bg-primary'],
  ['sheet', 'hover:bg-muted'],
  ['import', 'hover:bg-muted'],
])('Lock is the solid header action on the grid tab and a ghost alternative elsewhere (%s)', async (tab, look) => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  renderWithAuth(<AttendancePage />, lockPerms, { path: `/attendance?group=engineer&tab=${tab}&period=2026-09-26&date=2026-10-01` })
  const lock = await screen.findByRole('button', { name: 'قفل الفترة' })
  expect(lock).toHaveClass(look)
  if (tab !== 'grid') expect(lock).not.toHaveClass('bg-primary')
})

test('the grid isolates a Latin name from the employee code so RTL does not glue them', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect((await screen.findByText('Engineer-1')).tagName).toBe('BDI')
})

test('the period range stays on one line and its row wraps instead', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  await screen.findAllByText('2026-09-26')
  const range = screen.getByRole('button', { name: 'الفترة السابقة' }).nextElementSibling!
  expect(range).toHaveTextContent('من 2026-09-26 إلى 2026-10-25')
  expect(range).toHaveClass('whitespace-nowrap')
  expect(range.parentElement).toHaveClass('flex-wrap', 'gap-1') // gap-2 pushes the next arrow onto a second line at 375 px ar
})

test('the period range and the day editor title draw each ISO date left to right inside RTL text', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/days/e1/2026-10-03': () => json(200, { data: { ...cell('2026-10-03', 'missing'), employee_id: 'e1', type: 'engineer', version: null,
    fields: ['in_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  for (const d of ['2026-09-26', '2026-10-25']) for (const el of await screen.findAllByText(d)) expect(el).toHaveAttribute('dir', 'ltr')
  await user.click(await screen.findByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' }))
  const editor = await screen.findByRole('dialog', { name: 'يوم 2026-10-03 — Engineer-1' })
  expect(within(editor).getByText('2026-10-03')).toHaveAttribute('dir', 'ltr')
})

test('the period arrows move between periods and keep the group in the URL', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'الفترة التالية' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('period_start=2026-08-26'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('group=engineer')
})

test('a weekday code without a leave type and an office present day still get a label', async () => {
  const office = [{ employee: { id: 'e2', code: 2, name_ar: 'Office-1', name_en: 'Office-1' },
    days: [cell('2026-10-01', 'stored'), cell('2026-10-02', 'derived', { leave_code: 'thursday' }), cell('2026-10-04', 'not_employed')] }]
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': () => json(200, { data: office, meta: { pagination } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=office&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Office-1 2026-10-01: حاضر' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Office-1 2026-10-02: thursday' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Office-1 2026-10-04: خارج فترة العمل' })).toBeDisabled()
})

test('a locked period shows the badge and an empty group says so', async () => {
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/period': () => json(200, { data: { ...period, is_locked: true } }),
    'GET /api/v1/attendance/grid': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }),
  })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?tab=grid' })
  expect(await screen.findByText('لا يوجد موظفون في هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(screen.getByText('مقفلة')).toBeInTheDocument()
  expect(screen.getByRole('combobox', { name: 'المجموعة' })).toHaveValue('engineer')
})

test('clicking a day opens its editor', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/days/e1/2026-10-03': () => json(200, { data: { ...cell('2026-10-03', 'missing'), employee_id: 'e1', type: 'engineer', version: null,
    fields: ['in_time', 'out_time'], leave_day_fields: [], hints: {}, period: { start: '2026-09-26', end: '2026-10-25', is_locked: false } } }) })
  renderWithAuth(<AttendancePage />, ['attendance.view.all', 'attendance.edit.all'], { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'Engineer-1 2026-10-03: غير مسجل' }))
  expect(await screen.findByRole('dialog', { name: 'يوم 2026-10-03 — Engineer-1' })).toBeInTheDocument()
  expect(await screen.findByLabelText('وقت الحضور')).toBeEnabled()
})

const lockPerms = ['attendance.view.all', 'attendance.edit.all', 'attendance.lock.all']
const lockedPeriod = { ...period, is_locked: true, missing: 0, lock: { locked_by: { id: 'u1', name: 'HR-1' }, locked_at: '2026-10-26T08:00:00Z', reopened_by: null, reopened_at: null, reopen_reason: null } }
const lockReply = (p = {}) => ({ ...lockedPeriod, holidays_removed: [], holidays_added: [], leave_on_holidays: [], ...p })

test('locking with missing days lists them and needs the confirm checkbox', async () => {
  const lock = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: lockReply() }))
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/missing': () => json(200, { data: [{ employee_id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1', date: '2026-10-03' }], meta: { pagination } }),
    'POST /api/v1/attendance/locks': lock,
  })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'قفل الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'قفل حضور الفترة' })
  expect(within(dialog).getByText('بعد القفل لا يمكن لأي مستخدم تعديل أيام هذه الفترة حتى يُعاد فتحها.')).toBeInTheDocument()
  const row = await within(dialog).findByRole('listitem')
  expect(row).toHaveTextContent('Engineer-1')
  expect(row).toHaveTextContent('2026-10-03')
  expect(within(row).getByText('2026-10-03')).toHaveAttribute('dir', 'ltr')
  const submit = within(dialog).getByRole('button', { name: 'قفل الفترة' })
  expect(submit).toBeDisabled()
  await user.click(within(dialog).getByRole('checkbox', { name: 'أؤكد القفل رغم وجود أيام غير مسجلة' }))
  expect(submit).toBeEnabled()
  await user.click(submit)
  await waitFor(() => expect(lock).toHaveBeenCalled())
  expect(JSON.parse(String(lock.mock.calls[0][1]?.body))).toEqual({ group: 'engineer', period_start: '2026-09-26', confirm_missing: true })
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

test('a re-lock that changed holidays keeps the dialog open with the changes and only a Close button', async () => {
  const reply = lockReply({ holidays_removed: ['2026-10-06'], holidays_added: ['2026-10-08'],
    leave_on_holidays: [{ employee_id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1', date: '2026-10-08', leave_code: 'annual' }] })
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, missing: 0 } }), 'POST /api/v1/attendance/locks': () => json(201, { data: reply }) })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'قفل الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'قفل حضور الفترة' })
  await user.click(within(dialog).getByRole('button', { name: 'قفل الفترة' }))
  expect(await within(dialog).findByText('تم قفل الفترة')).toBeInTheDocument()
  expect(within(dialog).getByText('لم تعد عطلات رسمية (حُذفت أيامها المسجّلة):').nextElementSibling).toHaveTextContent('2026-10-06')
  expect(within(dialog).getByText('2026-10-06')).toHaveAttribute('dir', 'ltr')
  expect(within(dialog).getByText('عطلات رسمية أُضيفت أو عُدّلت منذ آخر قفل:').nextElementSibling).toHaveTextContent('2026-10-08')
  const leave = within(dialog).getByText('أيام إجازة في يوم عطلة رسمية (لم تتغير، راجعها):').nextElementSibling!
  expect(leave).toHaveTextContent('Engineer-1')
  expect(leave).toHaveTextContent('2026-10-08')
  expect(leave).toHaveTextContent('إجازة اعتيادية')
  expect(within(dialog).queryByRole('button', { name: 'قفل الفترة' })).toBeNull()
  expect(within(dialog).queryByText('ما زالت هذه الأيام غير مسجلة:')).toBeNull()
  const closes = within(dialog).getAllByRole('button', { name: 'إغلاق' })
  await user.click(closes[0])
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

test('a lock with no holiday changes closes with a toast', async () => {
  const reply = lockReply()
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: { ...period, missing: 0 } }), 'POST /api/v1/attendance/locks': () => json(201, { data: reply }) })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'قفل الفترة' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'قفل الفترة' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

test('a long missing-days list scrolls in its own region so the confirm checkbox stays in view', async () => {
  const many = Array.from({ length: 40 }, (_, i) => ({ employee_id: `e${i}`, code: i, name_ar: `Engineer-${i}`, name_en: `Engineer-${i}`, date: '2026-10-03' }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/missing': () => json(200, { data: many, meta: { pagination: { ...pagination, total: 40 } } }) })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'قفل الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'قفل حضور الفترة' })
  const list = await within(dialog).findByRole('region', { name: 'ما زالت هذه الأيام غير مسجلة:' })
  expect(within(list).getAllByRole('listitem')).toHaveLength(40)
  expect(list).toHaveClass('max-h-64', 'overflow-y-auto', 'focus-visible:outline-solid')
  expect(list).toHaveAttribute('tabindex', '0')
  const confirm = within(dialog).getByRole('checkbox', { name: 'أؤكد القفل رغم وجود أيام غير مسجلة' })
  expect(list.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
})

test('reopen sends the reason', async () => {
  const reopen = vi.fn((_url: string, init?: RequestInit) =>
    JSON.parse(String(init?.body)).reason === 'Correction'
      ? json(422, { error: { code: 'validation_failed', message: 'invalid', details: [{ field: 'reason', code: 'invalid', message: 'Reason too short.' }] } })
      : json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/period': () => json(200, { data: lockedPeriod }), 'POST /api/v1/attendance/locks/reopen': reopen })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByText(/قفلها HR-1 في/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'إعادة فتح الفترة' })).toHaveClass('hover:bg-muted') // a ghost alternative, never the main action
  expect(screen.getByRole('button', { name: 'إعادة فتح الفترة' })).not.toHaveClass('bg-primary')
  expect(screen.getByRole('button', { name: 'إعادة فتح الفترة' })).not.toHaveClass('border')
  await user.click(screen.getByRole('button', { name: 'إعادة فتح الفترة' }))
  const dialog = await screen.findByRole('dialog', { name: 'إعادة فتح حضور الفترة' })
  await user.type(within(dialog).getByLabelText('سبب إعادة الفتح'), 'Correction')
  await user.click(within(dialog).getByRole('button', { name: 'إعادة فتح الفترة' }))
  await waitFor(() => expect(reopen).toHaveBeenCalled())
  expect(JSON.parse(String(reopen.mock.calls[0][1]?.body))).toEqual({ group: 'engineer', period_start: '2026-09-26', reason: 'Correction' })
  expect(await within(dialog).findByText('Reason too short.')).toBeInTheDocument()
  expect(within(dialog).getByLabelText('سبب إعادة الفتح')).toHaveAttribute('aria-invalid', 'true')
})

test('without attendance.view the page is the not-found page, not a shell around a 403', async () => {
  const forbidden = vi.fn(() => json(403, { error: { code: 'forbidden', message: 'forbidden' } }))
  mockFetch({ 'GET /api/v1/attendance/period': forbidden })
  renderWithAuth(<AttendancePage />, ['employee.view.own'], { path: '/attendance' })
  expect(await screen.findByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  expect(screen.queryByRole('combobox', { name: 'المجموعة' })).not.toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  expect(forbidden).not.toHaveBeenCalled()
})

test('lock controls need attendance.lock', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all', 'attendance.edit.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إعادة فتح الفترة' })).not.toBeInTheDocument()
})

// the employee and the site each have distinct Arabic and English names
const bilingual = {
  ...routes,
  'GET /api/v1/attendance/grid': () => json(200, { data: [{ employee: { id: 'e1', code: 1, name_ar: 'مهندس-1', name_en: 'Engineer-1 EN' }, days: [cell('2026-10-01', 'stored', { site_id: 's1' })] }], meta: { pagination } }),
  'GET /api/v1/sites': () => json(200, { data: [{ ...sites[0], name_ar: 'موقع-1', name_en: 'Site-1 EN' }], meta: { pagination } }),
  'GET /api/v1/attendance/days/e1/2026-10-01': () => new Promise<Response>(() => {}) as unknown as Response, // the title shows while the day loads
  'GET /api/v1/attendance/missing': () => json(200, { data: [{ employee_id: 'e1', code: 1, name_ar: 'مهندس-1', name_en: 'Engineer-1 EN', date: '2026-10-03' }], meta: { pagination } }),
}

test('in English the grid, its day editor and the missing list show the English site and employee names', async () => {
  mockFetch(bilingual)
  await i18n.changeLanguage('en')
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  await user.click(await screen.findByRole('button', { name: 'Engineer-1 EN 2026-10-01: Site-1 EN' }))
  expect(await screen.findByRole('dialog', { name: '2026-10-01 — Engineer-1 EN' })).toBeInTheDocument()
  await user.keyboard('{Escape}')
  await user.click(screen.getByRole('button', { name: 'Lock period' }))
  expect(await within(await screen.findByRole('dialog')).findByRole('listitem')).toHaveTextContent('Engineer-1 EN')
})

test('in Arabic the grid keeps the Arabic site and employee names', async () => {
  mockFetch(bilingual)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  expect(await screen.findByRole('button', { name: 'مهندس-1 2026-10-01: موقع-1' })).toBeInTheDocument()
})

// the engineers' period 26 Oct → 25 Nov 2026 with today Monday 2 Nov: Friday 30 Oct is derived for one row, so its column is off
const week = [{ employee: { id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: 'Engineer-1' },
  days: [cell('2026-10-30', 'derived', { leave_code: 'friday' }), cell('2026-10-31', 'stored', { site_id: 's1' }), cell('2026-11-01', 'stored', { site_id: 's1' }), cell('2026-11-02', 'missing'), cell('2026-11-03', 'future')] },
{ employee: { id: 'e2', code: 2, name_ar: 'Engineer-2', name_en: 'Engineer-2' },
  days: [cell('2026-10-30', 'stored', { site_id: 's1' }), cell('2026-10-31', 'stored', { site_id: 's1' }), cell('2026-11-01', 'stored', { site_id: 's1' }), cell('2026-11-02', 'missing'), cell('2026-11-03', 'future')] }]
const weekRoutes = { ...routes, 'GET /api/v1/attendance/grid': () => json(200, { data: week, meta: { pagination } }) }
const onMonday = () => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
}
const heads = async () => (await screen.findAllByRole('columnheader')).slice(1) // after the employee column
const above = (head: HTMLElement, name: string, n: string) =>
  within(head).getByText(name).compareDocumentPosition(within(head).getByText(n)) & Node.DOCUMENT_POSITION_FOLLOWING

test('each day head shows the short weekday name above the date, in Arabic and in English', async () => {
  onMonday()
  mockFetch(weekRoutes)
  const { unmount } = renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const ar = await heads()
  expect(ar.map((h) => h.textContent)).toEqual(['جمعة30', 'سبت31', 'أحد1', 'إثنين2', 'ثلاثاء3'])
  expect(above(ar[3], 'إثنين', '2')).toBeTruthy()
  unmount()
  await i18n.changeLanguage('en')
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const en = await heads()
  expect(en.map((h) => h.textContent)).toEqual(['Fri30', 'Sat31', 'Sun1', 'Mon2', 'Tue3'])
  expect(above(en[3], 'Mon', '2')).toBeTruthy()
  expect(en[3]).toHaveClass('normal-case') // TableHead upper-cases, "MON" is not the key
})

test('today is the accent column with aria-current and the other days are not', async () => {
  onMonday()
  mockFetch(weekRoutes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const h = await heads()
  expect(h[3]).toHaveAttribute('aria-current', 'date')
  expect(h[3]).toHaveClass('bg-accent', 'text-accent-foreground')
  expect(h.filter((x) => x.hasAttribute('aria-current'))).toHaveLength(1)
})

test('a column with a derived day is tinted on its head and on every row, others are not', async () => {
  onMonday()
  mockFetch(weekRoutes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const h = await heads()
  const td = (name: string) => screen.getByRole('button', { name }).closest('td')
  expect(h[0]).toHaveClass('bg-muted-foreground/10') // visible on the bg-muted head row, unlike bg-muted/50
  expect(td('Engineer-1 2026-10-30: جمعة')).toHaveClass('bg-muted-foreground/10')
  expect(td('Engineer-2 2026-10-30: Site-1')).toHaveClass('bg-muted-foreground/10') // a worked Friday in an off column
  expect(h[1]).not.toHaveClass('bg-muted-foreground/10')
  expect(td('Engineer-1 2026-10-31: Site-1')).not.toHaveClass('bg-muted-foreground/10')
})

// jsdom has no layout: the scroller is 0–341, the sticky name column covers `pin`, and today sits at `day`
const layout = (pin: [number, number], day: [number, number]) =>
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
    const [left, right] = this.getAttribute('aria-current') === 'date' ? day : this.matches('th:first-child') ? pin : [0, 341]
    return { left, right } as DOMRect
  })

test.each([
  ['RTL, today past the end', [217, 341], [-300, -244], -300],
  ['RTL, today under the sticky names', [217, 341], [250, 306], 89],
  ['LTR, today past the end', [0, 167], [500, 556], 215],
  ['LTR, today under the sticky names', [0, 167], [120, 176], -47],
] as const)('the grid scrolls its own box once so today shows beside the sticky names (%s), never again on a re-render', async (_, pin, day, dx) => {
  onMonday()
  layout([...pin], [...day])
  const scroll = vi.spyOn(Element.prototype, 'scrollBy')
  mockFetch(weekRoutes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const box = (await screen.findByRole('table')).parentElement
  const own = () => scroll.mock.contexts.filter((el) => el === box)
  expect(own()).toHaveLength(1)
  expect(scroll).toHaveBeenCalledWith({ left: dx })
  await user.click(screen.getByRole('button', { name: 'Engineer-1 2026-10-31: Site-1' })) // a re-render: the day editor opens
  expect(own()).toHaveLength(1)
})

test('the grid does not scroll when today is outside the period', async () => {
  const scroll = vi.spyOn(Element.prototype, 'scrollBy')
  onMonday()
  mockFetch(routes) // September–October, today is later
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  await screen.findByRole('table')
  expect(scroll).not.toHaveBeenCalled()
})

test('the grid waits for the site names before it mounts, so today is measured with the columns at their final width', async () => {
  onMonday()
  let answer: ((r: Response) => void) | undefined
  mockFetch({ ...weekRoutes, 'GET /api/v1/sites': () => new Promise<Response>((r) => { answer = r }) as unknown as Response })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  await waitFor(() => expect(answer).not.toBe(undefined))
  await new Promise((r) => setTimeout(r, 50)) // the grid list has answered by now
  expect(screen.queryByRole('table')).not.toBeInTheDocument()
  answer!(json(200, { data: sites, meta: { pagination } }))
  expect(await screen.findByRole('button', { name: 'Engineer-1 2026-10-31: Site-1' })).toBeInTheDocument()
})

test('hovering an off cell darkens its tint instead of lightening it', async () => {
  onMonday()
  mockFetch(weekRoutes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const off = await screen.findByRole('button', { name: 'Engineer-2 2026-10-30: Site-1' })
  expect(off).toHaveClass('hover:bg-muted-foreground/15')
  expect(off).not.toHaveClass('hover:bg-muted')
  expect(screen.getByRole('button', { name: 'Engineer-2 2026-10-31: Site-1' })).toHaveClass('hover:bg-muted')
})

// jsdom does not paint, so this pins the structure: with collapsed borders Chromium leaves a 1 px band at each row line of a sticky
// cell unpainted at some sub-pixel offsets, and a tinted off column scrolling under it shows through. Separate borders that each cell
// owns close the band; the last row leaves its line to the legend.
test('the grid cells own their row lines, so nothing shows through the sticky name column', async () => {
  onMonday()
  mockFetch(weekRoutes)
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid' })
  const table = await screen.findByRole('table')
  expect(table).toHaveClass('border-separate', 'border-spacing-0')
  const [head, ...days] = await screen.findAllByRole('columnheader')
  expect(head).toHaveClass('sticky', 'border-b')
  expect(days[0]).toHaveClass('border-b')
  expect(days[3]).toHaveClass('border-b-2', 'border-primary') // today keeps its underline
  expect(days[3]).not.toHaveClass('border-b')
  const [first, last] = [screen.getByText('Engineer-1').closest('td')!, screen.getByText('Engineer-2').closest('td')!]
  expect(first).toHaveClass('sticky', 'border-b')
  expect(screen.getByRole('button', { name: 'Engineer-1 2026-10-30: جمعة' }).closest('td')).toHaveClass('border-b')
  expect(last.closest('tbody')).toHaveClass('[&_tr:last-child>td]:border-b-0')
})

test('the grid filters by work site: the pick goes out as site_id from page 1, and a group change clears it', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid&period=2026-09-26' })
  const filter = await screen.findByRole('combobox', { name: 'الموقع' })
  expect(filter).toHaveValue('')
  await screen.findByRole('option', { name: 'Site-1' })
  expect(vi.mocked(fetch).mock.calls.map(([u]) => String(u))).toContain('/api/v1/sites?per_page=100&kind=work&active=true')
  await user.selectOptions(filter, 's1')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('site_id=s1'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('page=1')
  await user.selectOptions(screen.getByRole('combobox', { name: 'المجموعة' }), 'permanent_worker')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('group=permanent_worker'))
  expect(grid.mock.calls.at(-1)?.[0]).not.toContain('site_id')
  expect(screen.getByRole('combobox', { name: 'الموقع' })).toHaveValue('')
})

test('the grid searches by name or code: typing asks from page 1 after a pause, ?q= seeds the box, and a group change keeps it', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  const { unmount } = renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid&period=2026-09-26' })
  const box = await screen.findByRole('textbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })
  expect(box).toHaveAttribute('maxlength', '100') // the API refuses a longer search
  await user.type(box, 'سامي')
  expect(box).toHaveValue('سامي') // the box is instant
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain(`search=${encodeURIComponent('سامي')}`))
  expect(grid.mock.calls.filter(([u]) => u.includes('search=')).length).toBe(1) // one request after the pause, not one per letter
  expect(grid.mock.calls.at(-1)?.[0]).toContain('page=1')
  unmount()

  grid.mockClear()
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid&q=7' })
  expect(await screen.findByRole('textbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })).toHaveValue('7')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('search=7'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'المجموعة' }), 'office')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('group=office'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('search=7')
})

test('a grid search with no match says so, and the search box is on the grid tab only', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }), 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid&q=nobody' })
  expect(await screen.findByText('لا يوجد موظفون مطابقون.')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'كشف اليوم' }))
  await screen.findByRole('tab', { name: 'كشف اليوم', selected: true })
  expect(screen.queryByRole('textbox', { name: 'ابحث بالاسم أو الرقم الوظيفي' })).toBeNull()
})

test('HR’s switch asks the grid and the header for with_archived=1, keeps it in the URL, and marks the rows of those who left', async () => {
  const left = { employee: { id: 'e9', code: 9, name_ar: 'Engineer-9', name_en: 'Engineer-9', archived: true }, days: [cell('2026-10-01', 'stored', { site_id: 's1' })] }
  const grid = vi.fn((url: string) => json(200, { data: url.includes('with_archived=1') ? [...rows, left] : rows, meta: { pagination } }))
  const head = vi.fn((_url: string) => json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid, 'GET /api/v1/attendance/period': head,
    'GET /api/v1/attendance/days/e9/2026-10-01': () => new Promise<Response>(() => {}) as unknown as Response })
  const { unmount } = renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=grid' })
  const box = await screen.findByRole('checkbox', { name: 'إظهار من تركوا العمل' })
  expect(box).not.toBeChecked()
  await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })
  expect([...grid.mock.calls, ...head.mock.calls].some(([u]) => u.includes('with_archived'))).toBe(false)
  await user.click(box)
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('with_archived=1'))
  expect(head.mock.calls.at(-1)?.[0]).toContain('with_archived=1') // the header's missing count follows the grid
  const name = (await screen.findByText('Engineer-9')).closest('td')!
  expect(within(name).getByText('ترك العمل')).toBeInTheDocument()
  expect(within(screen.getByText('Engineer-1').closest('td')!).queryByText('ترك العمل')).toBeNull()
  await user.click(screen.getByRole('button', { name: 'Engineer-9 2026-10-01: Site-1' })) // a leaver's day opens as usual
  expect(await screen.findByRole('dialog', { name: 'يوم 2026-10-01 — Engineer-9' })).toBeInTheDocument()
  await user.keyboard('{Escape}')
  await user.selectOptions(screen.getByRole('combobox', { name: 'المجموعة' }), 'office')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('group=office'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('with_archived=1')
  await user.click(screen.getByRole('checkbox', { name: 'إظهار من تركوا العمل' }))
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).not.toContain('with_archived'))
  expect(head.mock.calls.at(-1)?.[0]).not.toContain('with_archived')
  unmount()

  grid.mockClear()
  head.mockClear()
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=permanent_worker&tab=grid&with_archived=1' }) // a group not cached above
  expect(await screen.findByRole('checkbox', { name: 'إظهار من تركوا العمل' })).toBeChecked()
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('with_archived=1'))
  expect(head.mock.calls.length).toBeGreaterThan(0)
  expect(head.mock.calls.every(([u]) => u.includes('with_archived=1'))).toBe(true)
})

test('the switch needs attendance.edit.all and stays on the grid tab', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  for (const perms of [['attendance.view.all'], ['attendance.view.own', 'attendance.edit.own']]) {
    const { unmount } = renderWithAuth(<AttendancePage />, perms, { path: '/attendance?group=engineer&tab=grid&with_archived=1' })
    await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })
    expect(screen.queryByRole('checkbox', { name: 'إظهار من تركوا العمل' })).toBeNull()
    unmount()
  }
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=engineer&tab=sheet&with_archived=1&date=2026-10-01' })
  await screen.findAllByText('2026-09-26')
  expect(screen.queryByRole('checkbox', { name: 'إظهار من تركوا العمل' })).toBeNull()
  expect(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes('with_archived'))).toEqual([])
})

test.each([
  ['grid', 'office'], ['grid', 'engineer'], ['grid', 'all'],
  ['sheet', 'office'], ['sheet', 'engineer'], ['sheet', 'all'],
])('all-scope attendance has the site filter on the %s tab for %s', async (tab, group) => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: `/attendance?group=${group}&tab=${tab}` })
  expect(await screen.findByRole('combobox', { name: 'الموقع' })).toBeInTheDocument()
})

test('own-scope attendance has no site filter', async () => {
  mockFetch(routes)
  renderWithAuth(<AttendancePage />, ['attendance.view.own'], { path: '/attendance?group=engineer&tab=grid' })
  await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })
  expect(screen.queryByRole('combobox', { name: 'الموقع' })).toBeNull()
})

test('«بدون موقع» is a sheet-only value: the grid neither offers it nor sends it', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=grid&site=none' })
  const filter = await screen.findByRole('combobox', { name: 'الموقع' })
  await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })
  expect(filter).toHaveValue('')
  expect(within(filter).queryByRole('option', { name: 'بدون موقع' })).toBeNull()
  expect(grid.mock.calls.at(-1)?.[0]).not.toContain('site_id')
})

test('leaving the sheet tab clears «بدون موقع» instead of carrying it to the grid', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=sheet&site=none' })
  const filter = await screen.findByRole('combobox', { name: 'الموقع' })
  expect(within(filter).getByRole('option', { name: 'بدون موقع' })).toBeInTheDocument()
  expect(filter).toHaveValue('none')
  await user.click(screen.getByRole('tab', { name: 'جدول الفترة' }))
  await user.click(await screen.findByRole('tab', { name: 'كشف اليوم' }))
  await waitFor(async () => expect(await screen.findByRole('combobox', { name: 'الموقع' })).toHaveValue(''))
})

test('All is the first group on the grid: the grid asks for group=all (with the site), the header for the engineer window, and no lock or missing line shows', async () => {
  const grid = vi.fn((_url: string) => json(200, { data: rows, meta: { pagination } }))
  const head = vi.fn((_url: string) => json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/grid': grid, 'GET /api/v1/attendance/period': head })
  renderWithAuth(<AttendancePage />, lockPerms, { path: '/attendance?group=all&tab=grid' })
  const select = await screen.findByRole('combobox', { name: 'المجموعة' })
  expect(select).toHaveValue('all')
  expect(within(select).getAllByRole('option')[0]).toHaveTextContent('الكل')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('group=all'))
  expect(head.mock.calls.every(([u]) => u.includes('group=engineer'))).toBe(true)
  await user.selectOptions(await screen.findByRole('combobox', { name: 'الموقع' }), 's1')
  await waitFor(() => expect(grid.mock.calls.at(-1)?.[0]).toContain('site_id=s1'))
  expect(grid.mock.calls.at(-1)?.[0]).toContain('group=all')
  await screen.findByRole('button', { name: 'Engineer-1 2026-10-01: Site-1' })
  expect(screen.queryByRole('button', { name: 'قفل الفترة' })).toBeNull()
  expect(screen.queryByText(/أيام غير مسجلة/)).toBeNull()
})

test('All is the first group on the sheet too: it asks for group=all and the header for the engineer window', async () => {
  const sheet = vi.fn((_url: string) => new Promise<Response>(() => {}) as unknown as Response)
  const head = vi.fn((_url: string) => json(200, { data: period }))
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': sheet, 'GET /api/v1/attendance/period': head })
  renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=all&tab=sheet&date=2026-10-01' })
  const select = await screen.findByRole('combobox', { name: 'المجموعة' })
  expect(select).toHaveValue('all')
  expect(within(select).getAllByRole('option')[0]).toHaveTextContent('الكل')
  await waitFor(() => expect(sheet.mock.calls.at(-1)?.[0]).toContain('group=all'))
  await screen.findAllByText('2026-09-26')
  expect(head.mock.calls.every(([u]) => u.includes('group=engineer'))).toBe(true)
})

test('the default group stays engineers, and the import tab does not offer All', async () => {
  mockFetch({ ...routes, 'GET /api/v1/attendance/sheet': () => new Promise<Response>(() => {}) as unknown as Response })
  const { unmount } = renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance' })
  expect(await screen.findByRole('combobox', { name: 'المجموعة' })).toHaveValue('engineer')
  unmount()
  renderWithAuth(<AttendancePage />, ['attendance.view.all', 'attendance.edit.all'], { path: '/attendance?group=all&tab=import' })
  const select = await screen.findByRole('combobox', { name: 'المجموعة' })
  expect(select).toHaveValue('engineer')
  expect(within(select).queryByRole('option', { name: 'الكل' })).toBeNull()
})
