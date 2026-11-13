import { act, fireEvent, screen, within } from '@testing-library/react'
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
    // no period in the URL: the per-type template button shows only once the period request answers, which per site skips
    const view = renderWithAuth(<AttendancePage />, p, { path: '/attendance?group=engineer&tab=import&by=site' })
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

test('the 15th still gives the 1st to the 15th', () => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-15T10:00:00Z'))
  mockFetch(routes)
  renderWithAuth(<SiteWorkbookTab />, perms)
  expect(screen.getByLabelText('من تاريخ')).toHaveValue('2026-11-01')
  expect(screen.getByLabelText('إلى تاريخ')).toHaveValue('2026-11-15')
})

test('a download refusal goes away once HR changes the site or the dates', async () => {
  onThe12th()
  const user = userEvent.setup()
  mockFetch({ ...routes, 'GET /api/v1/attendance/site-workbook': () => json(422, { error: { code: 'validation_failed', message: 'The selected site is invalid.', details: [] } }) })
  renderWithAuth(<SiteWorkbookTab />, perms)
  await pickSite(user)
  await user.click(screen.getByRole('button', { name: 'تنزيل ملف الموقع' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('The selected site is invalid.')
  fireEvent.change(screen.getByLabelText('إلى تاريخ'), { target: { value: '2026-11-14' } })
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'تنزيل ملف الموقع' }))
  expect(await screen.findByRole('alert')).toBeInTheDocument()
  await pickSite(user, 'Site-2')
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
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

  fireEvent.change(to, { target: { value: '2026-11-01' } }) // one day: from = to
  expect(download).toBeEnabled()
  expect(to).not.toHaveAccessibleDescription()

  fireEvent.change(to, { target: { value: '2026-12-01' } }) // 31 days, both ends counted
  expect(download).toBeEnabled()
  expect(to).not.toHaveAccessibleDescription()

  fireEvent.change(to, { target: { value: '2026-12-02' } })
  expect(download).toBeDisabled()
  expect(to).toHaveAccessibleDescription('المدة 31 يومًا على الأكثر.')
  await act(() => i18n.changeLanguage('en'))
  expect(screen.getByLabelText('To')).toHaveAccessibleDescription('The range is at most 31 days.')
})

// ---- upload, preview and confirm. Fixtures are trimmed from the backend's answers on the Gate B file (real shapes). ----
const FILE_LABEL = 'ملف Excel (⁦.xlsx⁩، حتى 5 ميجابايت)'
type Day = { site: string; in_time: string | null; out_time: string | null; late_minutes: number | null; ot_minutes: number | null; advance_amount: string | null; note: string | null }
const day = (o: Partial<Day> = {}): Day => ({ site: 'Site-1', in_time: '08:00', out_time: '17:00', late_minutes: 0, ot_minutes: 0, advance_amount: null, note: null, ...o })
const listed = (employee_id: string, code: number, name_ar: string, type: string, date: string, sheet: string, row: number, to: Day, more: object = {}) =>
  ({ key: `${employee_id}/${date}`, sheet, row, date, employee_id, code, name_ar, name_en: null, type, new_laborer: false, matched_by_national_id: false, conflict_site: null, from: null, to, ...more })
const newRow = (name_ar: string, date: string, sheet: string, row: number, to: Day) =>
  ({ key: null, sheet, row, date, employee_id: null, code: null, name_ar, name_en: null, type: 'day_laborer', new_laborer: true, matched_by_national_id: false, conflict_site: null, from: null, to })
const TUE = 'الثلاثاء 03-11'
const WED = 'الأربعاء 04-11'
const conflictRow = listed('e17', 17, 'Laborer-1', 'day_laborer', '2026-11-03', TUE, 3, day({ out_time: '16:00', late_minutes: null }), {
  conflict_site: 'برج العرب',
  from: day({ site: 'برج العرب', late_minutes: null, ot_minutes: null }),
})
const sameRow = listed('e15', 15, 'Worker-1', 'permanent_worker', '2026-11-03', TUE, 4, day({ in_time: '09:00', late_minutes: null, ot_minutes: null }), {
  from: day({ in_time: '09:00', late_minutes: null, ot_minutes: null }), // a day entered by hand that the file repeats
})
const lateRow = listed('e13', 13, 'Engineer-2', 'engineer', '2026-11-04', WED, 3, day({ in_time: '08:30', out_time: '18:00', late_minutes: 30, ot_minutes: 60 }))
const zeroRow = listed('e18', 18, 'Laborer-2', 'day_laborer', '2026-11-04', WED, 4, day())
const advanceRow = listed('e12', 12, 'Engineer-1', 'engineer', '2026-11-04', WED, 5, day({ advance_amount: '500.000000', note: 'سلفة تجربة' }))
const leaveRow = listed('e15', 15, 'Worker-1', 'permanent_worker', '2026-11-04', WED, 6, day({ site: 'غ', in_time: null, out_time: null, late_minutes: null, ot_minutes: null }))
const HASSAN = 'حسن محمد إبراهيم'
const KARIM = 'كريم سعيد عبد الله'
const rowError = (sheet: string, row: number, date: string, name: string | null, error: string, message: string) => ({ sheet, row, date, name, error, message })
const timesError = rowError('الأحد 08-11', 4, '2026-11-08', 'Engineer-3 (مهندس) · 14', 'times_need_both', 'أدخل وقتي الحضور والانصراف معًا.')
const futureError = rowError('السبت 14-11', 3, '2026-11-14', 'Laborer-1 (يومية) · 17', 'future_date', 'لا يمكن تسجيل حضور لتاريخ لم يأتِ بعد.')
const preview = {
  site: { id: 's1', name_ar: 'Site-1', name_en: null },
  from: '2026-11-03', // read from the file: not the picker's half-month
  to: '2026-11-08',
  token: 'f8046191d278',
  // the server's total, shown as sent: the rows here hold 500, so a sum in the client would show 500
  summary: { rows: 11, changes: 8, unchanged: 0, errors: 2, conflicts: 1, new_laborers: 2, advances_total: '700.000000' },
  changes: [conflictRow, sameRow, newRow(HASSAN, '2026-11-03', TUE, 5, day({ out_time: '16:00' })), lateRow, zeroRow, advanceRow, leaveRow, newRow(HASSAN, '2026-11-04', WED, 7, day())],
  new_laborers: [
    { name_ar: HASSAN, national_id: '29501010212345', phone: '01012345678', residence: 'العامرية', proposed_wage: '350.000000', gender: 'male', first_date: '2026-11-03', days: 2 },
    { name_ar: KARIM, national_id: '29803150201234', phone: null, residence: 'الاسكندرية', proposed_wage: null, gender: 'male', first_date: '2026-11-08', days: 0 }, // every row refused later
  ],
  errors: [timesError, futureError],
}
const noConflict = { ...preview, summary: { ...preview.summary, conflicts: 0 }, changes: preview.changes.filter((c) => !c.conflict_site) }
const result = {
  applied: 9, unchanged: 0, kept: 1, advances_total: '500.000000',
  new_laborers: [{ employee_id: 'n24', code: 24, name_ar: HASSAN, proposal: true }, { employee_id: 'n25', code: 25, name_ar: KARIM, proposal: false }],
  errors: [timesError, futureError],
}
const xlsx = (name = 'site-workbook-2026-11-01-2026-11-15.xlsx') => new File(['PK'], name, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
const sent = (f: ReturnType<typeof vi.fn>, i = 0) => (f.mock.calls[i] as [string, RequestInit])[1].body as FormData
/** The visible values of a DataList row: a field cell's last child holds the value (its first is the phone card's label). */
const values = (row: HTMLElement) => within(row).getAllByRole('cell').map((c) => (c.childElementCount > 1 ? c.lastElementChild!.textContent : c.textContent))
const rowWith = (table: HTMLElement, text: string) => within(table).getAllByRole('row').find((r) => r.textContent!.includes(text))!

async function previewed(data: object = preview, confirmAnswer = () => json(200, { data: result })) {
  const user = userEvent.setup()
  const previewCall = vi.fn(() => json(200, { data }))
  const confirmCall = vi.fn(confirmAnswer)
  mockFetch({ ...routes, 'POST /api/v1/attendance/site-workbook/preview': previewCall, 'POST /api/v1/attendance/site-workbook/confirm': confirmCall })
  renderWithAuth(<SiteWorkbookTab />, perms)
  const file = xlsx()
  await user.upload(screen.getByLabelText(FILE_LABEL), file)
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  await screen.findByRole('heading', { name: /^Site-1/ })
  return { user, file, previewCall, confirmCall }
}

test('the preview shows the file\'s site and range, the changes with late and overtime, the advances total, the new laborers and the errors', async () => {
  const { file, previewCall, confirmCall } = await previewed()
  expect([...sent(previewCall).keys()]).toEqual(['file']) // no site or range: the file names them
  expect(sent(previewCall).get('file')).toBe(file)
  const head = screen.getByRole('heading', { name: 'Site-1: من 2026-11-03 إلى 2026-11-08' })
  expect(within(head).getByText('2026-11-03')).toHaveAttribute('dir', 'ltr')
  expect(screen.getByText('تغييرات: 8 · بدون تغيير: 0 · أخطاء: 2 · تعارضات: 1 · عمال يومية جدد: 2')).toBeInTheDocument()
  expect(screen.getByText('مجموع السلف في صفوف الملف المقبولة: 700.00')).toBeInTheDocument()
  expect(screen.getByText('لم يُحفظ أي شيء بعد. راجع المعاينة ثم أكّد الحفظ.')).toBeInTheDocument()

  const changes = screen.getByRole('table', { name: 'التغييرات' })
  expect(within(changes).getAllByRole('row')).toHaveLength(9) // the head and 8 changes
  const late = rowWith(changes, 'Engineer-2')
  expect(within(late).getByText(WED).tagName).toBe('BDI')
  expect(values(late)).toHaveLength(5) // sheet · row, employee, current value, new value, advance
  expect(values(late)[3]).toContain('دقائق التأخير: 30 · دقائق الإضافي: 60') // late and overtime under the new value
  const advance = values(rowWith(changes, 'Engineer-1'))
  expect(advance.at(-1)).toBe('500.00')
  expect(advance[3]).toContain('ملاحظة: سلفة تجربة') // the note under the new value
  expect(rowWith(changes, 'Laborer-1').textContent).toContain('برج العرب') // the recorded day it replaces

  const laborers = screen.getByRole('table', { name: 'عمال يومية جدد' })
  const hassan = rowWith(laborers, HASSAN)
  expect(within(hassan).getByText('29501010212345')).toHaveAttribute('dir', 'ltr')
  expect(within(hassan).getByText('01012345678')).toHaveAttribute('dir', 'ltr')
  // gender, residence and mobile under the name; the days under the first day
  for (const text of ['ذكر', 'محل الإقامة: العامرية', 'الأيام: 2']) expect(within(hassan).getByText(text)).toBeInTheDocument()
  expect(within(hassan).getByText('01012345678').parentElement).toHaveTextContent('رقم الهاتف: 01012345678')
  expect(values(hassan).slice(1, 3)).toEqual(['29501010212345', '350.00'])
  expect(values(hassan)[3]).toContain('2026-11-03')
  expect(values(rowWith(laborers, KARIM))).toContain('بدون أجر مقترح')
  expect(screen.getByText('يُنشأ كل منهم عند التأكيد بالنوع «ذكر»، ويمكن تغييره من صفحة الموظف.')).toBeInTheDocument()

  const errors = screen.getByRole('table', { name: 'الأخطاء' })
  expect(within(errors).getByText('أدخل وقتي الحضور والانصراف معًا.')).toBeInTheDocument()
  expect(within(errors).getByText('Engineer-3 (مهندس) · 14').tagName).toBe('BDI')
  expect(confirmCall).not.toHaveBeenCalled()
})

test('a day the file repeats with the same values says it becomes imported', async () => {
  await previewed()
  const changes = screen.getByRole('table', { name: 'التغييرات' })
  expect(within(rowWith(changes, 'Worker-1')).getByText('نفس القيم؛ يصبح اليوم مستوردًا')).toBeInTheDocument()
  expect(within(changes).getAllByText('نفس القيم؛ يصبح اليوم مستوردًا')).toHaveLength(1) // only where from and to are identical
})

test('late and overtime show a dash when the server sends none, and 0 when it sends 0', async () => {
  await previewed()
  const changes = screen.getByRole('table', { name: 'التغييرات' })
  const newValue = (text: string) => values(rowWith(changes, text))[3]
  expect(newValue('Laborer-2')).toContain('دقائق التأخير: 0 · دقائق الإضافي: 0')
  expect(newValue('برج العرب')).toContain('دقائق التأخير: — · دقائق الإضافي: 0') // a replaced day whose arrival did not change
  expect(newValue('غ')).not.toContain('دقائق') // a leave row has neither: no line at all, and never a 0
})

test('a new laborer\'s rows have no key or Employee ID, and one with no row saved shows 0 days', async () => {
  const error = vi.spyOn(console, 'error')
  await previewed()
  const rows = within(screen.getByRole('table', { name: 'التغييرات' })).getAllByRole('row').filter((r) => r.textContent!.includes(HASSAN))
  expect(rows).toHaveLength(2)
  for (const r of rows) expect(within(r).getByText('جديد')).toBeInTheDocument()
  expect(error).not.toHaveBeenCalled() // no duplicate React keys
  expect(within(rowWith(screen.getByRole('table', { name: 'عمال يومية جدد' }), KARIM)).getByText('الأيام: 0')).toBeInTheDocument()
})

test('confirm stays disabled until every conflict has a choice, and the set-all control sets them all', async () => {
  const second = listed('e19', 19, 'Laborer-3', 'day_laborer', '2026-11-04', WED, 8, day(), { conflict_site: 'العلمين', from: day({ site: 'العلمين' }) })
  const { user, file, confirmCall } = await previewed({ ...preview, summary: { ...preview.summary, conflicts: 2 }, changes: [...preview.changes, second] })
  const confirm = screen.getByRole('button', { name: 'تأكيد الحفظ' })
  expect(confirm).toBeDisabled()
  expect(screen.getByText('اختر الإبقاء أو الاستبدال لكل تعارض قبل الحفظ.')).toBeInTheDocument()
  const conflicts = screen.getByRole('table', { name: 'التعارضات' })
  expect(within(conflicts).getAllByRole('row')).toHaveLength(3)
  expect(rowWith(conflicts, 'Laborer-3').textContent).toContain('العلمين')
  // the recorded day in full beside the file's, as HR decides here
  expect(within(conflicts).getByRole('columnheader', { name: 'اليوم المسجّل' })).toBeInTheDocument()
  expect(values(rowWith(conflicts, 'Laborer-1')).slice(1, 3)).toEqual(['برج العرب · 8:00 ص–5:00 م', 'Site-1 · 8:00 ص–4:00 م'])
  expect(within(rowWith(conflicts, 'Laborer-1')).getByText(TUE)).toBeInTheDocument() // the sheet under the name: the person is the subject here
  const first = screen.getByRole('combobox', { name: 'إبقاء أو استبدال: Laborer-1، 2026-11-03' })
  const other = screen.getByRole('combobox', { name: 'إبقاء أو استبدال: Laborer-3، 2026-11-04' })
  expect(first).toHaveValue('')

  await user.selectOptions(first, 'replace')
  expect(confirm).toBeDisabled()
  await user.selectOptions(screen.getByRole('combobox', { name: 'اختيار واحد لكل التعارضات' }), 'keep')
  expect([first, other].map((s) => (s as HTMLSelectElement).value)).toEqual(['keep', 'keep'])
  expect(confirm).toBeEnabled()
  expect(screen.queryByText('اختر الإبقاء أو الاستبدال لكل تعارض قبل الحفظ.')).not.toBeInTheDocument()

  await user.selectOptions(other, 'replace')
  await user.click(confirm)
  await screen.findByRole('status')
  const body = sent(confirmCall)
  expect([...body.keys()]).toEqual(['file', 'token', 'choices[e17/2026-11-03]', 'choices[e19/2026-11-04]'])
  expect(body.get('file')).toBe(file)
  expect([body.get('token'), body.get('choices[e17/2026-11-03]'), body.get('choices[e19/2026-11-04]')]).toEqual(['f8046191d278', 'keep', 'replace'])
})

test('a new preview or a new file drops the choices', async () => {
  const { user } = await previewed()
  const choice = () => screen.getByRole('combobox', { name: 'إبقاء أو استبدال: Laborer-1، 2026-11-03' })
  await user.selectOptions(choice(), 'keep')
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  await vi.waitFor(() => expect(choice()).toHaveValue(''))
  expect(screen.getByRole('button', { name: 'تأكيد الحفظ' })).toBeDisabled()
  await user.selectOptions(choice(), 'keep')
  await user.upload(screen.getByLabelText(FILE_LABEL), xlsx('other.xlsx'))
  expect(screen.queryByRole('table', { name: 'التعارضات' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تأكيد الحفظ' })).not.toBeInTheDocument()
})

test('a conflict the write refuses is only an error: it asks no choice and does not hold confirm back', async () => {
  const refused = rowError(TUE, 3, '2026-11-03', 'Laborer-1 (يومية) · 17', 'period_locked', 'This attendance period is locked and cannot be edited.')
  const { user, confirmCall } = await previewed({ ...noConflict, errors: [refused, ...preview.errors] })
  expect(screen.queryByRole('table', { name: 'التعارضات' })).not.toBeInTheDocument()
  expect(screen.queryByRole('combobox', { name: /إبقاء أو استبدال/ })).not.toBeInTheDocument()
  expect(within(screen.getByRole('table', { name: 'الأخطاء' })).getByText('فترة الحضور مقفلة ولا يمكن تعديلها.')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'تأكيد الحفظ' }))
  await screen.findByRole('status')
  expect([...sent(confirmCall).keys()]).toEqual(['file', 'token'])
})

test('a preview_stale answer clears the preview and asks for a new one', async () => {
  const stale = () => json(409, { error: { code: 'preview_stale', message: 'The stored attendance changed after the preview. Preview the file again.' } })
  const { user } = await previewed(noConflict, stale)
  await user.click(screen.getByRole('button', { name: 'تأكيد الحفظ' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('تغيّر الحضور المسجّل بعد المعاينة. اعرض المعاينة مرة أخرى.')
  expect(screen.queryByRole('heading', { name: /^Site-1/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('table', { name: 'التغييرات' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تأكيد الحفظ' })).not.toBeInTheDocument()
  expect(screen.getByText('site-workbook-2026-11-01-2026-11-15.xlsx')).toBeInTheDocument() // the file stays chosen
  expect(screen.getByRole('button', { name: 'معاينة التغييرات' })).toBeEnabled()
})

test('an error code follows the UI language', async () => {
  const mystery = rowError(WED, 9, '2026-11-04', 'X', 'mystery_code', 'رسالة لا يعرفها الواجهة.')
  const typed = rowError(WED, 10, '2026-11-04', 'Y', 'name_too_long', 'الاسم أطول من 255 حرفًا.')
  await previewed({ ...noConflict, errors: [typed, mystery] })
  await act(() => i18n.changeLanguage('en'))
  const errors = screen.getByRole('table', { name: 'Errors' })
  expect(within(errors).getByText('The name is longer than 255 characters.')).toBeInTheDocument()
  expect(within(errors).getByText('رسالة لا يعرفها الواجهة.')).toBeInTheDocument() // a code the UI does not know keeps the server's words
  expect(screen.getByRole('heading', { name: 'Site-1: 2026-11-03 to 2026-11-08' })).toBeInTheDocument()
})

// the server answers in the request's language; the banner follows the UI's (the site workbook's own wording where the per-type one does not fit)
test.each([
  ['template_mismatch', 422, 'الملف لا يطابق ملف الموقع. نزّل ملف الموقع من جديد واملأه.', 'The file does not match a site workbook. Download a fresh one and fill it in.'],
  ['too_many_rows', 422, 'في إحدى أوراق الملف أكثر من 2000 صف.', 'A sheet in the file has more than 2000 rows.'],
  ['unreadable_file', 422, 'تعذّرت قراءة الملف.', 'The file could not be read.'],
  ['permission_denied', 403, 'ليس لديك صلاحية للقيام بهذا الإجراء.', 'You do not have permission to perform this action.'],
  ['server_busy', 500, 'رسالة الخادم.', 'رسالة الخادم.'],
])('a whole-file refusal (%s) shows in the UI language', async (code, status, ar, en) => {
  const user = userEvent.setup()
  mockFetch({ ...routes, 'POST /api/v1/attendance/site-workbook/preview': () => json(status, { error: { code, message: 'رسالة الخادم.', details: [] } }) })
  renderWithAuth(<SiteWorkbookTab />, perms)
  await user.upload(screen.getByLabelText(FILE_LABEL), xlsx())
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  expect(await screen.findByRole('alert')).toHaveTextContent(ar)
  await act(() => i18n.changeLanguage('en'))
  expect(screen.getByRole('alert')).toHaveTextContent(en)
})

test('re-previewing a confirmed file says there is nothing to save, with the file\'s advances and the unchanged count', async () => {
  await previewed({ ...preview, token: 't2', summary: { rows: 11, changes: 0, unchanged: 9, errors: 2, conflicts: 0, new_laborers: 0, advances_total: '500.000000' }, changes: [], new_laborers: [] })
  expect(screen.getByText('تغييرات: 0 · بدون تغيير: 9 · أخطاء: 2 · تعارضات: 0 · عمال يومية جدد: 0')).toBeInTheDocument()
  expect(screen.getByText('مجموع السلف في صفوف الملف المقبولة: 500.00')).toBeInTheDocument()
  expect(screen.getByText('لا شيء للحفظ: كل صف في الملف محفوظ بالفعل أو به خطأ.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تأكيد الحفظ' })).not.toBeInTheDocument()
  expect(screen.queryByRole('table', { name: 'التغييرات' })).not.toBeInTheDocument()
})

test('the confirm result shows the counts, the advances, the new laborers\' Employee IDs and proposals, and the errors', async () => {
  const { user } = await previewed(noConflict)
  await user.click(screen.getByRole('button', { name: 'تأكيد الحفظ' }))
  const done = await screen.findByRole('status')
  expect(done).toHaveTextContent('تم حفظ 9 أيام · بدون تغيير: 0 · أُبقي على المسجّل: 1 · أخطاء: 2')
  expect(done).toHaveTextContent('مجموع السلف في الصفوف المحفوظة: 500.00')
  const added = screen.getByRole('table', { name: 'عمال اليومية المضافون' })
  expect(within(added).getByRole('columnheader', { name: 'الرقم الوظيفي' })).toBeInTheDocument()
  expect(values(rowWith(added, HASSAN))).toEqual([HASSAN, '24', 'أُرسل للاعتماد'])
  expect(values(rowWith(added, KARIM))).toEqual([KARIM, '25', 'بدون أجر مقترح: حدّده من صفحة الموظف'])
  expect(within(added).getByRole('link', { name: HASSAN })).toHaveAttribute('href', '/employees/n24')
  expect(within(screen.getByRole('table', { name: 'الأخطاء' })).getAllByRole('row')).toHaveLength(3)
  expect(screen.queryByRole('table', { name: 'التغييرات' })).not.toBeInTheDocument()
  expect(screen.queryByRole('table', { name: 'عمال يومية جدد' })).not.toBeInTheDocument()
})

test('a file over 5 MB or not xlsx is refused before upload', async () => {
  const user = userEvent.setup({ applyAccept: false })
  mockFetch(routes)
  renderWithAuth(<SiteWorkbookTab />, perms)
  const big = xlsx()
  Object.defineProperty(big, 'size', { value: 5 * 1024 * 1024 + 1 })
  for (const f of [big, new File(['a,b'], 'site.csv', { type: 'text/csv' })]) {
    await user.upload(screen.getByLabelText(FILE_LABEL), f)
    expect(screen.getByText('اختر ملف ⁦.xlsx⁩ لا يتجاوز 5 ميجابايت.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'معاينة التغييرات' })).toBeDisabled()
  }
  expect(vi.mocked(fetch).mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/site-workbook'))).toEqual([])
})

test('with no active work site the picker says so', async () => {
  mockFetch({ 'GET /api/v1/sites': () => json(200, { data: [], meta: { pagination: { ...pagination, total: 0 } } }) })
  renderWithAuth(<SiteWorkbookTab />, perms)
  expect(await screen.findByText('لا توجد مواقع عمل نشطة. أضف موقعًا أو فعّله من صفحة المواقع.')).toBeInTheDocument()
})

test('names, codes, times and sheet references never break inside a word, and the tables keep to four or five columns', async () => {
  await previewed()
  const table = (name: string) => screen.getByRole('table', { name })
  expect([['التغييرات', 5], ['التعارضات', 5], ['عمال يومية جدد', 4], ['الأخطاء', 4]].map(([n]) => within(table(n as string)).getAllByRole('columnheader').length)).toEqual([5, 4, 4, 4])
  const late = rowWith(table('التغييرات'), 'Engineer-2')
  for (const text of ['13', WED, '8:30 ص', '6:00 م']) expect(within(late).getByText(text)).toHaveClass('whitespace-nowrap')
  // DataList lets a cell break anywhere; a name may wrap only between its words
  expect(within(late).getByText('Engineer-2').closest('.wrap-normal')).not.toBeNull()
  expect(within(rowWith(table('عمال يومية جدد'), HASSAN)).getByText(HASSAN).closest('.wrap-normal')).not.toBeNull()
})

test.each([
  ['unknown_employee', 'هذا الاسم ليس في قائمة الموقع لهذه الأيام (تغيّر أو نُقل أو أُرشف)؛ نزّل ملف الموقع من جديد.'],
  ['duplicate_row', 'هذا الموظف مكرر في هذه الورقة.'],
])('a %s row speaks of the site workbook, and the per-type wording stays', async (code, shown) => {
  const shared = i18n.getFixedT('ar')(`attendance.import.error.${code}`)
  await previewed({ ...noConflict, errors: [rowError(WED, 9, '2026-11-04', 'Worker-9 (عامل) · 99', code, 'server words')] })
  expect(within(screen.getByRole('table', { name: 'الأخطاء' })).getByText(shown)).toBeInTheDocument()
  expect(shared).not.toBe(shown) // the per-type tab keeps its own key
})

test('a new laborer whose rows were all refused is still offered to confirm', async () => {
  await previewed({ ...noConflict, summary: { ...noConflict.summary, changes: 0, new_laborers: 1 }, changes: [], new_laborers: [preview.new_laborers[1]] })
  expect(screen.getByRole('button', { name: 'تأكيد الحفظ' })).toBeEnabled()
  expect(screen.queryByText('لا شيء للحفظ: كل صف في الملف محفوظ بالفعل أو به خطأ.')).not.toBeInTheDocument()
})

test('a file with no filled rows says so and offers no confirm', async () => {
  await previewed({ ...preview, summary: { rows: 0, changes: 0, unchanged: 0, errors: 0, conflicts: 0, new_laborers: 0, advances_total: '0.000000' }, changes: [], new_laborers: [], errors: [] })
  expect(screen.getByText('لا توجد صفوف مملوءة في الملف.')).toBeInTheDocument()
  expect(screen.queryByText('لا شيء للحفظ: كل صف في الملف محفوظ بالفعل أو به خطأ.')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تأكيد الحفظ' })).not.toBeInTheDocument()
})

test('the changes mark the conflict row and a person matched by national ID', async () => {
  const matched = listed('e20', 20, 'Laborer-4', 'day_laborer', '2026-11-04', WED, 9, day(), { matched_by_national_id: true })
  await previewed({ ...preview, changes: [...preview.changes, matched] })
  const changes = screen.getByRole('table', { name: 'التغييرات' })
  expect(within(rowWith(changes, 'Laborer-4')).getByText('طابق بالرقم القومي')).toBeInTheDocument()
  expect(within(rowWith(changes, 'Laborer-1')).getByText('تعارض')).toBeInTheDocument()
  expect([within(changes).getAllByText('طابق بالرقم القومي').length, within(changes).getAllByText('تعارض').length]).toEqual([1, 1])
})
