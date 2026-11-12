import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, onTestFinished, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, me, mockFetch, pagination, renderApp, renderWithAuth } from '@/test-utils'
import { PayrollPage } from './payroll'

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(), dismiss: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'

const user = userEvent.setup()
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-18T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.clearAllMocks()
  localStorage.clear()
  return i18n.changeLanguage('ar')
})

type Override = { id: string; amount: string; reason: string; inputs_changed: boolean }
const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 0 }
const officePeriod = { ...period, group: 'office', start: '2026-10-01', end: '2026-10-31', prev_start: '2026-09-01' }
const blank = { version: null, bonus: null, deduction: null, negligence: null, advances: null, insurance_employee: null, insurance_company: null, income_tax: null, lateness_exemption_minutes: null, travel_days: null, tower_days: null, ot_minutes: null, deduction_reason: null, note: null }
const c = (computed: string, override: Override | null = null) => ({ computed, override, final: override?.amount ?? computed })
const line = (n: number, components: object, extra: object = {}) => ({ employee: { id: `e${n}`, code: n, name_ar: `Engineer-${n}`, name_en: null }, components, entry: blank, flags: [] as string[], ...extra })
const OT_OVERRIDE = { id: 'o1', amount: '100.000000', reason: 'Approved by HR', inputs_changed: true }
const lines = [
  line(1, { salary: c('9000.000000'), overtime: c('56.250000', OT_OVERRIDE), deduction: c('100.000000'), insurance_employee: c('0.000000'), net: { computed: '8956.250000', override: null, final: '9000.000000' } },
    { entry: { ...blank, version: 3, deduction: '100.000000', deduction_reason: 'Late report' }, flags: ['missing', 'inputs_changed'] }),
  line(2, { salary: c('9000.000000'), overtime: c('0.000000'), deduction: c('9500.000000'), insurance_employee: c('0.000000'), net: c('-500.000000') }, { flags: ['negative_net'] }),
]
const sheet = { lines, totals: { salary: '18000.000000', overtime: '100.000000', deduction: '9600.000000', insurance_employee: '0.000000', net: '8500.000000' }, locked: false }
const zero = { transport: '0.000000', overtime: '0.000000', outside: '0.000000', lateness: '0.000000', advances: '0.000000' }
const days = [
  { date: '2026-09-26', state: 'missing', leave_code: null, site_id: null, parts: zero, day_cost: '300.000000', flags: ['missing'] },
  { date: '2026-10-01', state: 'work', leave_code: null, site_id: 's1', parts: { ...zero, overtime: '56.250000' }, day_cost: '356.250000', flags: ['early_departure:30'] },
  { date: '2026-10-02', state: 'leave', leave_code: 'sick', site_id: 'sl', parts: zero, day_cost: '300.000000', flags: [] },
]
const sites = [
  { id: 's1', name_ar: 'Site-1', name_en: 'Site-1', kind: 'work', leave_code: null, active: true },
  { id: 'sl', name_ar: 'مرضي', name_en: null, kind: 'leave', leave_code: 'sick', active: true },
]
const BASE = '/api/v1/payroll/engineer/2026-09-26'
const routes = {
  'GET /api/v1/attendance/period': (url: string) => json(200, { data: url.includes('group=office') ? officePeriod : period }),
  [`GET ${BASE}`]: () => json(200, { data: sheet }),
  [`GET ${BASE}/employees/e1`]: () => json(200, { data: { ...lines[0], days } }),
  'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination } }),
}
const HR = ['payroll.view.all', 'payroll.manage.all', 'attendance.view.all', 'site.view.all']
const CEO = ['payroll.view.all', 'attendance.view.all', 'site.view.all']
const open = (perms = HR, path = '/payroll?group=engineer') => renderWithAuth(<PayrollPage />, perms, { path })
const table = () => screen.findByRole('table')
const sent = (fn: ReturnType<typeof vi.fn>, call = 0) => JSON.parse(String((fn.mock.calls[call][1] as RequestInit).body))
const rowOf = (name: string) => within(screen.getByRole('table')).getByText(name).closest('tr')!
const reasonRequired = () => json(422, { error: { code: 'validation_failed', message: 'The given data was invalid.', details: [{ field: 'deduction_reason', code: 'invalid', message: 'حقل سبب الخصم مطلوب.' }] } })

test('renders a row per line and a totals row, with the columns of the type, the flags and the not-locked banner', async () => {
  mockFetch(routes)
  open(CEO)
  const rows = within(await table()).getAllByRole('row')
  expect(rows).toHaveLength(4) // header, 2 lines, totals
  const head = within(rows[0]).getAllByRole('columnheader').map((h) => h.textContent)
  expect(head).toEqual(expect.arrayContaining(['المرتب', 'الوقت الإضافي', 'الخصم', 'صافي المرتب']))
  expect(head).not.toContain('البدل') // a component an engineer's line does not carry has no column
  expect(rows[3]).toHaveTextContent('الإجمالي')
  expect(rows[3]).toHaveTextContent('18,000.00')
  expect(rows[3]).toHaveTextContent('8,500.00')
  expect(rowOf('Engineer-1')).toHaveTextContent('أيام غير مسجلة')
  expect(rowOf('Engineer-2')).toHaveTextContent('صافٍ بالسالب')
  expect(rowOf('Engineer-2')).toHaveTextContent('-500.00') // shown as computed, never clipped
  expect(screen.getByText(/لم يُقفل حضور هذه الفترة بعد/)).toBeInTheDocument()
})

test('an overridden cell is highlighted and shows its reason on hover and to assistive tech', async () => {
  mockFetch(routes)
  open()
  await table()
  const cell = within(rowOf('Engineer-1')).getByRole('button', { name: /^الوقت الإضافي - Engineer-1/ })
  expect(cell).toHaveTextContent('100.00')
  expect(cell).toHaveClass('bg-info-bg')
  expect(cell.querySelector('svg.lucide-pen-line')).not.toBeNull() // not by colour alone
  expect(cell.querySelector('svg.lucide-triangle-alert')).not.toBeNull()
  expect(cell).toHaveAttribute('title', expect.stringContaining('Approved by HR'))
  expect(cell).toHaveAccessibleName(/Approved by HR/)
  expect(cell).toHaveAccessibleName(/تغيّرت المدخلات بعد تعديلك/)
})

test('editing an input cell sends PUT …/entry with the version, and a row saves when focus leaves it', async () => {
  const put1 = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...lines[0], days } }))
  const put2 = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...lines[1], days } }))
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e1/entry`]: put1, [`PUT ${BASE}/employees/e2/entry`]: put2 })
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-1' })
  await user.clear(deduction)
  await user.type(deduction, '150{Enter}')
  await waitFor(() => expect(put1).toHaveBeenCalledOnce())
  expect(sent(put1)).toEqual({ version: 3, deduction: '150', deduction_reason: 'Late report', insurance_employee: null, insurance_company: null, income_tax: null, note: null })
  // no entry yet: version null; moving to another cell of the row does not save, leaving the row does
  await user.type(screen.getByRole('textbox', { name: 'التأمينات (حصة الموظف) - Engineer-2' }), '٢٥٠')
  await user.click(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' }))
  expect(put2).not.toHaveBeenCalled()
  await user.click(document.body)
  await waitFor(() => expect(put2).toHaveBeenCalledOnce())
  expect(sent(put2)).toMatchObject({ version: null, insurance_employee: '250', deduction: null })
})

test('an input amount shows formatted like the computed cells, and the stored value while it is edited', async () => {
  mockFetch(routes)
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-1' })
  expect(deduction).toHaveValue('100.00')
  await user.click(deduction)
  expect(deduction).toHaveValue('100')
  await user.tab()
  expect(deduction).toHaveValue('100.00')
  expect(screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-1' })).toHaveValue('Late report')
})

// A keyboard focus selects the field's text, and typing replaces it. user-event's Tab selects after React has re-rendered the cell
// (formatted → value to edit), so it cannot show the bug; the other two replay a browser selecting the text in its own order.
test.each([
  ['Shift+Tab', async (el: HTMLInputElement) => {
    await user.click(screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-1' }))
    await user.tab({ shift: true })
    expect(el).toHaveFocus()
  }],
  ['a browser selecting after the focus handlers, before React re-renders', (el: HTMLInputElement) => act(() => { el.focus(); el.select() })],
  ['a browser selecting before the focus handlers (Chromium, most likely)', (el: HTMLInputElement) => act(() => { el.select(); el.focus() })],
])('a stored amount reached by keyboard (%s) is replaced by what HR types', async (_, reach) => {
  const put = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...lines[0], days } }))
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e1/entry`]: put })
  open()
  await table()
  const deduction = screen.getByRole<HTMLInputElement>('textbox', { name: 'الخصم - Engineer-1' })
  await reach(deduction)
  await user.keyboard('2000')
  expect(deduction).toHaveValue('2000')
  await user.click(document.body)
  await waitFor(() => expect(put).toHaveBeenCalledOnce())
  expect(sent(put)).toMatchObject({ version: 3, deduction: '2000', deduction_reason: 'Late report' })
})

// jsdom has no layout: this proves the call; the scroll padding it honours keeps the control clear of the pinned columns in a browser
test('a control reached by keyboard is brought into view, and a press does not move the table', async () => {
  const reveal = vi.spyOn(Element.prototype, 'scrollIntoView')
  onTestFinished(() => reveal.mockRestore())
  mockFetch(routes)
  open()
  await table()
  await user.click(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' })) // the click would land on another cell
  expect(reveal).not.toHaveBeenCalled()
  await user.tab({ shift: true }) // the computed cell Shift+Tab could leave under the employee column
  const overtime = within(rowOf('Engineer-2')).getByRole('button', { name: /^الوقت الإضافي - Engineer-2/ })
  expect(overtime).toHaveFocus()
  expect(reveal).toHaveBeenCalledOnce()
  expect(reveal.mock.contexts[0]).toBe(overtime.closest('td')) // the cell: its padding holds the focus ring, and a message under an input is wider than it
  expect(reveal).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' })
})

test.each([
  ['its name button (the side panel)', 'أيام Engineer-1'],
  ['a computed cell (the override dialog)', /^صافي المرتب - Engineer-1/],
])('typing then opening %s from the same row saves the row first', async (_, target) => {
  const put = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...lines[0], days } }))
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e1/entry`]: put })
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-1' })
  await user.clear(deduction)
  await user.type(deduction, '150')
  await user.click(screen.getByRole('button', { name: target }))
  await screen.findByRole('dialog')
  await waitFor(() => expect(put).toHaveBeenCalledOnce())
  expect(sent(put)).toMatchObject({ version: 3, deduction: '150' })
})

test('changing the period saves the row first, and a refusal that arrives after the row is gone still shows', async () => {
  let answer = () => {}
  const refused = new Promise<void>((resolve) => { answer = resolve })
  const put = vi.fn(() => refused.then(() => json(422, { error: { code: 'validation_failed', message: 'The given data was invalid.', details: [{ field: 'deduction_reason', code: 'invalid', message: 'حقل سبب الخصم مطلوب.' }] } })))
  const earlier = () => json(200, { data: { lines: [], totals: {}, locked: true } })
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e2/entry`]: put as unknown as () => Response, 'GET /api/v1/payroll/engineer/2026-08-26': earlier })
  open()
  await table()
  await user.type(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' }), '75')
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  expect(await screen.findByText('لا يوجد موظفون من هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(put).toHaveBeenCalledOnce() // sent before the row went
  answer()
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('لم تُحفظ مدخلات Engineer-2: حقل سبب الخصم مطلوب.'))
})

test('leaving for a cached period that has the same employee drops the typing: a refusal is a toast, and nothing of it reaches that period', async () => {
  let answer = () => {}
  const refused = new Promise<void>((resolve) => { answer = resolve })
  const putSeptember = vi.fn(() => refused.then(() => json(422, { error: { code: 'validation_failed', message: 'The given data was invalid.', details: [{ field: 'deduction_reason', code: 'invalid', message: 'حقل سبب الخصم مطلوب.' }] } })))
  const AUGUST = '/api/v1/payroll/engineer/2026-08-26'
  const august = line(2, { salary: c('9000.000000'), deduction: c('40.000000'), net: c('8960.000000') }, { entry: { ...blank, version: 7, deduction: '40.000000', deduction_reason: 'August reason' } })
  const putAugust = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...august, days } }))
  mockFetch({
    ...routes,
    'GET /api/v1/attendance/period': (url: string) => json(200, { data: url.includes('period_start=2026-08-26') ? { ...period, start: '2026-08-26', end: '2026-09-25', prev_start: '2026-07-26', next_start: '2026-09-26' } : period }),
    [`GET ${AUGUST}`]: () => json(200, { data: { lines: [august], totals: { salary: '9000.000000', deduction: '40.000000', net: '8960.000000' }, locked: true } }),
    [`PUT ${BASE}/employees/e2/entry`]: putSeptember as unknown as () => Response,
    [`PUT ${AUGUST}/employees/e2/entry`]: putAugust,
  })
  open()
  await table()
  const previous = screen.getByRole('button', { name: 'الفترة السابقة' })
  await user.click(previous)
  await screen.findByDisplayValue('August reason')
  await user.click(screen.getByRole('button', { name: 'الفترة التالية' })) // back to September, from the cache
  await waitFor(() => expect(previous).toBeEnabled())
  expect(rowOf('Engineer-1')).toBeInTheDocument()
  await user.type(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' }), '75')
  await user.click(previous) // saves September's row first; August is cached, so its sheet shows at once
  expect(putSeptember).toHaveBeenCalledOnce()
  expect(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' })).toHaveValue('40.00') // August's own values, no carried draft
  expect(screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' })).toHaveValue('August reason')
  answer()
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('لم تُحفظ مدخلات Engineer-2: حقل سبب الخصم مطلوب.'))
  expect(screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' })).not.toHaveAttribute('aria-invalid')
  // an edit on August's row writes August's entry: its version and its values, nothing typed in September
  await user.type(screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' }), '!')
  await user.click(document.body)
  await waitFor(() => expect(putAugust).toHaveBeenCalledOnce())
  expect(sent(putAugust)).toMatchObject({ version: 7, deduction: '40', deduction_reason: 'August reason!' })
  expect(putSeptember).toHaveBeenCalledOnce()
})

test('a typed but unsaved cell says so beyond its colour, and leaving the page warns', async () => {
  mockFetch(routes)
  open()
  await table()
  const insurance = screen.getByRole('textbox', { name: 'التأمينات (حصة الموظف) - Engineer-2' })
  const unsaved = 'لم يُحفظ بعد؛ يُحفظ عند مغادرة الصف أو الضغط على زر الإدخال.'
  expect(within(rowOf('Engineer-2')).queryByTitle(unsaved)).toBeNull()
  await user.type(insurance, '250')
  expect(insurance).toHaveAccessibleDescription(unsaved)
  expect(within(rowOf('Engineer-2')).getByTitle(unsaved)).toBeInTheDocument() // an icon beside the name
  const leaving = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(leaving)
  expect(leaving.defaultPrevented).toBe(true)
})

test('a 409 shows the reload message, reloads the sheet and overwrites nothing', async () => {
  const get = vi.fn(() => json(200, { data: sheet }))
  const put = vi.fn(() => json(409, { error: { code: 'concurrency_conflict', message: 'Someone else changed this record.' } }))
  mockFetch({ ...routes, [`GET ${BASE}`]: get, [`PUT ${BASE}/employees/e1/entry`]: put })
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-1' })
  await user.clear(deduction)
  await user.type(deduction, '150{Enter}')
  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('عدّل مستخدم آخر مدخلات Engineer-1 للتو؛ أُعيد تحميل الكشف، راجع ثم أعد المحاولة.'))
  expect(put).toHaveBeenCalledOnce()
  expect(get).toHaveBeenCalledTimes(2)
  await waitFor(() => expect(deduction).toHaveValue('100')) // the server's value: the draft is dropped, nothing retried
})

test('a refused field shows its message on its cell, takes the focus from the field HR saved from, and keeps what HR typed', async () => {
  const put = vi.fn(() => json(422, { error: { code: 'validation_failed', message: 'The given data was invalid.', details: [{ field: 'deduction_reason', code: 'invalid', message: 'حقل سبب الخصم مطلوب.' }] } }))
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e2/entry`]: put })
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-2' })
  await user.type(deduction, '75{Enter}')
  const reason = screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' })
  await waitFor(() => expect(reason).toHaveAccessibleDescription('حقل سبب الخصم مطلوب.'))
  expect(reason).toHaveFocus() // under a pinned column or past the edge, it would go unseen; focus brings it in and reads its message
  expect(reason).toHaveAttribute('aria-invalid', 'true')
  expect(deduction).toHaveValue('75')
  expect(toast.error).not.toHaveBeenCalled()
})

test('a refusal takes the focus from the page; from a control HR has moved to, it does not, and a toast says it', async () => {
  const put = vi.fn(reasonRequired)
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e2/entry`]: put })
  open()
  await table()
  const reason = screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' })
  await user.type(screen.getByRole('textbox', { name: 'الخصم - Engineer-2' }), '75')
  await user.click(document.body) // leaving the row for no control saves it
  await waitFor(() => expect(reason).toHaveFocus())
  expect(toast.error).not.toHaveBeenCalled() // the focused field shows the message
  const elsewhere = screen.getByRole('textbox', { name: 'الخصم - Engineer-1' })
  await user.click(elsewhere) // leaving it for another row's cell saves it again
  await waitFor(() => expect(put).toHaveBeenCalledTimes(2))
  await waitFor(() => expect(reason).not.toHaveAttribute('readonly')) // the second refusal has landed
  expect(reason).toHaveAttribute('aria-invalid', 'true')
  expect(elsewhere).toHaveFocus()
  // its cell may be under a pinned column or past the edge, so the refusal is also a toast
  expect(toast.error).toHaveBeenCalledWith('لم تُحفظ مدخلات Engineer-2: حقل سبب الخصم مطلوب.')
})

test('a refusal\'s message goes once the typing it refused is undone and the row is left', async () => {
  const put = vi.fn(reasonRequired)
  mockFetch({ ...routes, [`PUT ${BASE}/employees/e2/entry`]: put })
  open()
  await table()
  const deduction = screen.getByRole('textbox', { name: 'الخصم - Engineer-2' })
  const reason = screen.getByRole('textbox', { name: 'سبب الخصم - Engineer-2' })
  await user.type(deduction, '75{Enter}')
  await waitFor(() => expect(reason).toHaveAttribute('aria-invalid', 'true'))
  await user.clear(deduction)
  await user.click(document.body)
  expect(reason).not.toHaveAttribute('aria-invalid')
  expect(screen.queryByText('حقل سبب الخصم مطلوب.')).toBeNull()
  expect(put).toHaveBeenCalledOnce() // typed back to what it was: nothing to send
})

test('the override dialog requires a reason, then posts the component, the amount and the reason', async () => {
  const post = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: { ...lines[1], days } }))
  mockFetch({ ...routes, [`POST ${BASE}/employees/e2/overrides`]: post })
  open()
  await table()
  await user.click(within(rowOf('Engineer-2')).getByRole('button', { name: /^الوقت الإضافي - Engineer-2/ }))
  const dialog = await screen.findByRole('dialog', { name: 'قيمة بديلة: الوقت الإضافي - Engineer-2' })
  await user.type(within(dialog).getByLabelText('القيمة البديلة'), '80')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ القيمة البديلة' }))
  expect(await within(dialog).findByText('اكتب سبب القيمة البديلة.')).toBeInTheDocument()
  expect(post).not.toHaveBeenCalled()
  await user.type(within(dialog).getByLabelText('السبب'), 'Approved')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ القيمة البديلة' }))
  await waitFor(() => expect(post).toHaveBeenCalledOnce())
  expect(sent(post)).toEqual({ component: 'overtime', amount: '80', reason: 'Approved' })
})

test('an overridden cell offers a revert with a reason, not a second override', async () => {
  const del = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { ...lines[0], days } }))
  mockFetch({ ...routes, [`DELETE ${BASE}/employees/e1/overrides/o1`]: del })
  open()
  await table()
  await user.click(within(rowOf('Engineer-1')).getByRole('button', { name: /^الوقت الإضافي - Engineer-1/ }))
  const dialog = await screen.findByRole('dialog', { name: 'إرجاع المحسوب: الوقت الإضافي - Engineer-1' })
  expect(dialog).toHaveTextContent('Approved by HR')
  expect(dialog).toHaveTextContent('56.25') // the computed value it returns to
  expect(dialog).toHaveTextContent(/تغيّرت المدخلات بعد تعديلك/)
  expect(within(dialog).queryByLabelText('القيمة البديلة')).toBeNull()
  await user.click(within(dialog).getByRole('button', { name: 'إرجاع المحسوب' }))
  expect(await within(dialog).findByText('اكتب سبب الإرجاع.')).toBeInTheDocument()
  expect(del).not.toHaveBeenCalled()
  await user.type(within(dialog).getByLabelText('السبب'), 'Wrong amount')
  await user.click(within(dialog).getByRole('button', { name: 'إرجاع المحسوب' }))
  await waitFor(() => expect(del).toHaveBeenCalledOnce())
  expect(sent(del)).toEqual({ reason: 'Wrong amount' })
})

test('the side panel lists the days with their site or leave, parts and flags, and the overrides', async () => {
  mockFetch(routes)
  open(CEO)
  await table()
  await user.click(screen.getByRole('button', { name: 'أيام Engineer-1' }))
  const panel = await screen.findByRole('dialog')
  const items = within(await within(panel).findByRole('list', { name: 'أيام الفترة' })).getAllByRole('listitem')
  expect(items).toHaveLength(3)
  expect(items[0]).toHaveTextContent('2026-09-26')
  expect(items[0]).toHaveTextContent('غير مسجل')
  expect(items[1]).toHaveTextContent('Site-1')
  expect(items[1]).toHaveTextContent(/الوقت الإضافي\s*56\.25/)
  expect(items[1]).toHaveTextContent('356.25')
  expect(items[1]).toHaveTextContent(/انصراف مبكر.*30 د/)
  expect(items[2]).toHaveTextContent('إجازة مرضية')
  expect(panel).toHaveTextContent('Approved by HR')
})

test('a payroll.view user reads the sheet with no input cells and no override controls', async () => {
  mockFetch(routes)
  open(CEO)
  const tbl = await table()
  expect(within(tbl).queryAllByRole('textbox')).toHaveLength(0)
  expect(within(tbl).queryByRole('button', { name: /^المرتب - / })).toBeNull()
  expect(rowOf('Engineer-1')).toHaveTextContent('100.00') // the deduction, as a value
  // the overridden cell still names its reason; it opens the side panel
  await user.click(within(rowOf('Engineer-1')).getByRole('button', { name: /Approved by HR/ }))
  expect(await screen.findByRole('list', { name: 'أيام الفترة' })).toBeInTheDocument()
})

test('the group and period pickers choose the sheet', async () => {
  const office = vi.fn(() => json(200, { data: { lines: [], totals: {}, locked: true } }))
  const previous = vi.fn(() => json(200, { data: { lines: [], totals: {}, locked: true } }))
  mockFetch({ ...routes, 'GET /api/v1/payroll/office/2026-10-01': office, 'GET /api/v1/payroll/office/2026-09-01': previous })
  open()
  await table()
  await user.selectOptions(screen.getByLabelText('المجموعة'), 'office')
  expect(await screen.findByText('لا يوجد موظفون من هذه المجموعة خلال الفترة.')).toBeInTheDocument()
  expect(office).toHaveBeenCalled()
  expect(screen.queryByText(/لم يُقفل حضور هذه الفترة بعد/)).toBeNull()
  await user.click(screen.getByRole('button', { name: 'الفترة السابقة' }))
  await waitFor(() => expect(previous).toHaveBeenCalled())
})

test('the nav item is hidden without payroll.view, and /payroll is not found', async () => {
  const signedIn = (permissions: string[]) => {
    localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
    mockFetch({ ...routes, 'GET /api/v1/auth/me': () => json(200, { data: me(permissions) }), 'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }) })
  }
  signedIn(['employee.view.all', 'attendance.view.all'])
  const first = renderApp('/payroll')
  expect(await screen.findByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'المرتبات' })).toBeNull()
  first.unmount()
  signedIn(['employee.view.all', ...CEO])
  renderApp('/payroll')
  expect(await screen.findByRole('link', { name: 'المرتبات' })).toHaveAttribute('href', '/payroll')
  expect(await screen.findByRole('heading', { level: 1, name: 'المرتبات' })).toBeInTheDocument()
})
