import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { DayEditor } from './attendance-day'
vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'

const user = userEvent.setup()
afterEach(() => {
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})
const pg = (total = 1) => ({ page: 1, per_page: 100, total, total_pages: 1, has_next_page: false, has_previous_page: false })
const list = (data: unknown[], total = data.length) => () => json(200, { data, meta: { pagination: pg(total) } })
const body = (fn: { mock: { calls: unknown[][] } }) => JSON.parse((fn.mock.calls[0] as [string, RequestInit])[1].body as string)

const EDIT = ['attendance.view.all', 'attendance.edit.all']
const URL = '/api/v1/attendance/days/e1/2026-10-01'
const day = (over = {}) => ({ employee_id: 'e1', date: '2026-10-01', type: 'engineer', state: 'missing', source: null, version: null, site_id: null, leave_code: null,
  in_time: null, out_time: null, scheduled_in: null, scheduled_out: null, late_minutes: null, ot_minutes: null, outside_minutes: null, outside_amount: null, transport: null, advance_amount: null, note: null,
  fields: ['in_time', 'out_time', 'late_minutes', 'ot_minutes', 'outside_minutes', 'advance_amount', 'note'], leave_day_fields: ['advance_amount', 'note'], hints: {},
  period: { start: '2026-09-26', end: '2026-10-25', is_locked: false }, ...over })
const sites = [{ id: 's1', name_ar: 'Site-1', kind: 'work', outside_alexandria: false, active: true }, { id: 's2', name_ar: 'Site-2', kind: 'work', outside_alexandria: true, active: true },
  { id: 'lf', name_ar: 'Leave-friday', kind: 'leave', leave_code: 'friday', active: true }]
const routes = (d: object, extra = {}) => ({ [`GET ${URL}`]: () => json(200, { data: d }), 'GET /api/v1/sites': list(sites), ...extra })
const open = (permissions = EDIT, onOpenChange = () => {}) =>
  renderWithAuth(<DayEditor employee={{ id: 'e1', name_ar: 'Engineer-1' }} date="2026-10-01" onOpenChange={onOpenChange} />, permissions)
const siteSelect = () => screen.findByLabelText('الموقع أو نوع اليوم')

test('an engineer day saves its fields with the version', async () => {
  const put = vi.fn(() => json(200, { data: day({ state: 'stored', version: 1, site_id: 's2' }) }))
  const onOpenChange = vi.fn()
  mockFetch(routes(day(), { [`PUT ${URL}`]: put }))
  open(EDIT, onOpenChange)
  const select = await siteSelect()
  expect(screen.getByRole('dialog', { name: 'يوم 2026-10-01 — Engineer-1' })).toHaveTextContent('لم يُسجَّل هذا اليوم بعد.')
  await user.selectOptions(select, 's2')
  await user.type(screen.getByLabelText('دقائق العمل خارج الإسكندرية'), '٣٠')
  await user.type(screen.getByLabelText('السلفة'), '500')
  await user.type(screen.getByLabelText('وقت الحضور'), '8')
  await user.type(screen.getByLabelText('وقت الانصراف'), '5:30')
  await user.click(within(screen.getByRole('group', { name: 'صباحًا أو مساءً: وقت الانصراف' })).getByRole('button', { name: 'مساءً' }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(put)).toMatchObject({ version: null, site_id: 's2', outside_minutes: 30, advance_amount: '500', in_time: '08:00', out_time: '17:30', late_minutes: null })
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(toast.success).toHaveBeenCalledWith('تم حفظ التغييرات.')
})

test('choosing a leave type hides times and minutes', async () => {
  const put = vi.fn(() => json(200, { data: day() }))
  mockFetch(routes(day(), { [`PUT ${URL}`]: put }))
  open()
  await user.selectOptions(await siteSelect(), 'lf')
  expect(screen.queryByLabelText('وقت الحضور')).toBeNull()
  expect(screen.queryByLabelText('دقائق التأخير')).toBeNull()
  await user.type(screen.getByLabelText('السلفة'), '100')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(body(put)).toEqual({ version: null, site_id: 'lf', advance_amount: '100', note: null })
})

test('the outside-Alexandria field shows only for an outside site', async () => {
  mockFetch(routes(day()))
  open()
  await user.selectOptions(await siteSelect(), 's1')
  expect(screen.queryByLabelText('دقائق العمل خارج الإسكندرية')).toBeNull()
  await user.selectOptions(await siteSelect(), 's2')
  expect(screen.getByLabelText('دقائق العمل خارج الإسكندرية')).toBeInTheDocument()
})

test('an office day offers present and the leave types, not the work sites', async () => {
  mockFetch(routes(day({ type: 'office', fields: ['in_time', 'out_time'], leave_day_fields: [], hints: { late_minutes: 15 }, state: 'stored', source: 'imported', version: 2, in_time: '09:30' })))
  open()
  expect(await siteSelect()).toHaveValue('')
  expect(screen.getByRole('option', { name: 'حاضر' })).toHaveValue('')
  expect(screen.getByRole('option', { name: 'Leave-friday' })).toBeInTheDocument()
  expect(screen.queryByRole('option', { name: 'Site-1' })).toBeNull()
  expect(screen.getByLabelText('وقت الحضور')).toHaveValue('9:30')
  expect(within(screen.getByRole('group', { name: 'صباحًا أو مساءً: وقت الحضور' })).getByRole('button', { name: 'صباحًا' })).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByText('تأخير محسوب: 15 دقيقة')).toBeInTheDocument()
  expect(screen.getByText('مستورد من Excel')).toBeInTheDocument()
})

test('a derived day names its leave type and starts from it', async () => {
  mockFetch(routes(day({ state: 'derived', site_id: 'lf', leave_code: 'friday', advance_amount: '250.500000' })))
  open()
  expect(await siteSelect()).toHaveValue('lf')
  expect(screen.getByText('سُجِّل هذا اليوم تلقائيًا (Leave-friday). اختر موقعًا إذا عمل الموظف فيه.')).toBeInTheDocument()
  expect(screen.getByLabelText('السلفة')).toHaveValue('250.5')
  expect(screen.queryByRole('button', { name: 'مسح اليوم' })).toBeNull()
})

test('only the amounts lose their trailing zeros; a note shows as typed', async () => {
  mockFetch(routes(day({ state: 'stored', source: 'entered', version: 1, site_id: 's1', advance_amount: '500.000000', note: '3.50' })))
  open()
  await siteSelect()
  expect(screen.getByLabelText('السلفة')).toHaveValue('500')
  expect(screen.getByLabelText('ملاحظات')).toHaveValue('3.50')
})

test('a 409 keeps the typed values and offers the latest version', async () => {
  const get = vi.fn(() => json(200, { data: day() }))
  mockFetch(routes(day(), { [`GET ${URL}`]: get, [`PUT ${URL}`]: () => json(409, { error: { code: 'concurrency_conflict', message: 'conflict' } }) }))
  open()
  await user.selectOptions(await siteSelect(), 's1')
  await user.type(screen.getByLabelText('السلفة'), '500')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByText('عدّل مستخدم آخر هذا اليوم. حمّل آخر نسخة ثم أعد إدخال تغييراتك.')).toBeInTheDocument()
  expect(screen.getByLabelText('السلفة')).toHaveValue('500')
  await user.click(screen.getByRole('button', { name: 'تحميل آخر نسخة' }))
  await waitFor(() => expect(get).toHaveBeenCalledTimes(3)) // open, the refusal's own refetch (form kept), then the reload
  await waitFor(() => expect(screen.getByLabelText('السلفة')).toHaveValue(''))
})

test('after a 409, Save and Clear keep the version the form started from until Load latest', async () => {
  const get = vi.fn(() => json(200, { data: day({ state: 'stored', source: 'entered', version: get.mock.calls.length > 1 ? 2 : 1, site_id: 's1' }) })) // every refetch brings the other user's version 2
  const conflict = () => json(409, { error: { code: 'concurrency_conflict', message: 'conflict' } })
  const put = vi.fn(conflict)
  const del = vi.fn(conflict)
  mockFetch(routes(day(), { [`GET ${URL}`]: get, [`PUT ${URL}`]: put, [`DELETE ${URL}`]: del }))
  open()
  await siteSelect()
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await screen.findByRole('button', { name: 'تحميل آخر نسخة' })
  await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(put).toHaveBeenCalledTimes(2))
  expect(put.mock.calls.map((c) => JSON.parse(((c as unknown[])[1] as RequestInit).body as string).version)).toEqual([1, 1])
  await user.click(screen.getByRole('button', { name: 'مسح اليوم' }))
  await waitFor(() => expect(del).toHaveBeenCalledTimes(1))
  expect(String((del.mock.calls[0] as unknown[])[0])).toContain('version=1')
  await user.click(screen.getByRole('button', { name: 'تحميل آخر نسخة' }))
  await user.click(await screen.findByRole('button', { name: 'حفظ' }))
  await waitFor(() => expect(put).toHaveBeenCalledTimes(3))
  expect(JSON.parse((put.mock.calls[2] as unknown[] as [string, RequestInit])[1].body as string).version).toBe(2)
})

test('server field errors show under their fields', async () => {
  mockFetch(routes(day(), { [`PUT ${URL}`]: () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'site_id', code: 'site_required', message: 'اختر موقعًا.' }] } }) }))
  open()
  await user.click(await screen.findByRole('button', { name: 'حفظ' }))
  expect(await siteSelect()).toHaveAccessibleDescription('اختر موقعًا.')
})

test('read-only when the period is locked or without attendance.edit', async () => {
  mockFetch(routes(day({ period: { start: '2026-09-26', end: '2026-10-25', is_locked: true } })))
  const first = open()
  expect(await siteSelect()).toBeDisabled()
  expect(screen.getByLabelText('السلفة')).toBeDisabled()
  expect(screen.getByText('الفترة مقفلة، ولا يمكن تعديل أيامها.')).toBeInTheDocument()
  expect(screen.queryByText('للعرض فقط: لا تملك صلاحية تعديل الحضور.')).toBeNull() // one note, not both
  expect(screen.queryByRole('button', { name: 'حفظ' })).toBeNull()
  first.unmount()

  mockFetch(routes(day()))
  open(['attendance.view.all']) // the CEO: view only
  expect(await siteSelect()).toBeDisabled()
  expect(screen.getByLabelText('ملاحظات')).toBeDisabled()
  expect(screen.getByLabelText('السلفة')).toBeDisabled()
  const note = screen.getByText('للعرض فقط: لا تملك صلاحية تعديل الحضور.')
  expect(note.parentElement).toHaveClass('[&_:disabled]:opacity-100') // full-strength values on the muted fill
  expect(screen.queryByRole('button', { name: 'حفظ' })).toBeNull()
})

test('a view-only user in a locked period sees only the locked note', async () => {
  mockFetch(routes(day({ period: { start: '2026-09-26', end: '2026-10-25', is_locked: true } })))
  open(['attendance.view.all'])
  expect(await siteSelect()).toBeDisabled()
  expect(screen.getByText('الفترة مقفلة، ولا يمكن تعديل أيامها.')).toBeInTheDocument()
  expect(screen.queryByText('للعرض فقط: لا تملك صلاحية تعديل الحضور.')).toBeNull()
})

test('an editable form keeps the dimmed disabled style', async () => {
  mockFetch(routes(day()))
  open()
  expect((await siteSelect()).closest('.overflow-y-auto')).not.toHaveClass('[&_:disabled]:opacity-100')
})

test('clearing a stored day sends its version', async () => {
  const del = vi.fn((_url: string) => json(200, { data: day() }))
  const onOpenChange = vi.fn()
  mockFetch(routes(day({ state: 'stored', source: 'entered', version: 3, site_id: 's1' }), { [`DELETE ${URL}`]: del }))
  open(EDIT, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'مسح اليوم' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(del.mock.calls[0][0]).toContain('version=3')
})

test('in English the title, the site options and a seeded leave type without an English name use English', async () => {
  const bilingual = [{ ...sites[0], name_ar: 'موقع-1', name_en: 'Site-1 EN' }, sites[2]] // the seeded leave types have no name_en
  mockFetch({ ...routes(day({ state: 'derived', site_id: 'lf', leave_code: 'friday' })), 'GET /api/v1/sites': list(bilingual) })
  await i18n.changeLanguage('en')
  renderWithAuth(<DayEditor employee={{ id: 'e1', name_ar: 'مهندس-1', name_en: 'Engineer-1 EN' }} date="2026-10-01" onOpenChange={() => {}} />, EDIT)
  await screen.findByLabelText('Site or day type')
  expect(screen.getByRole('dialog', { name: '2026-10-01 — Engineer-1 EN' })).toHaveTextContent('This day was set automatically (Friday).')
  expect(screen.getByRole('option', { name: 'Site-1 EN' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Friday' })).toHaveValue('lf')
})

test('in English a leave type with an unknown code and no English name shows its Arabic name, not a key', async () => {
  const custom = [...sites, { id: 'lu', name_ar: 'إجازة-1', kind: 'leave', leave_code: 'unpaid', active: true }]
  mockFetch({ ...routes(day()), 'GET /api/v1/sites': list(custom) })
  await i18n.changeLanguage('en')
  open()
  await screen.findByLabelText('Site or day type')
  expect(screen.getByRole('option', { name: 'إجازة-1' })).toHaveValue('lu')
})
