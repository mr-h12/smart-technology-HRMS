import { act, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { AttendancePage } from './attendance'
import { ImportTab } from './attendance-import'

afterEach(async () => {
  vi.unstubAllGlobals()
  await i18n.changeLanguage('ar')
})

const perms = ['attendance.view.all', 'attendance.edit.all']
const FILE_LABEL = 'ملف Excel (⁦.xlsx⁩، حتى 5 ميجابايت)'
const change = (row: number, column: string, to: string) => ({ row, column, employee_id: 'e1', code: 1, name_ar: 'Engineer-1', name_en: null, date: '2026-10-04', from: null, to })
const error = { row: 3, column: 'G', code: 12, date: '2026-10-05', error: 'unknown_site', message: 'الموقع أو نوع اليوم غير موجود.' }
const rowError = { row: 5, column: null, code: 99, date: null, error: 'unknown_employee', message: 'الرقم الوظيفي غير موجود في هذه المجموعة.' }
const preview = { summary: { cells: 30, changes: 3, unchanged: 26, errors: 2 }, changes: [change(2, 'F', 'Site-1'), change(2, 'H', 'Site-1'), change(4, 'F', 'Site-2')], errors: [error, rowError] }
const xlsx = () => new File(['PK'], 'attendance-engineer-2026-09-26.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
const sent = (f: ReturnType<typeof vi.fn>) => (f.mock.calls[0] as [string, RequestInit])[1].body as FormData

async function previewed(data: object = preview) {
  const user = userEvent.setup()
  const previewCall = vi.fn(() => json(200, { data }))
  const confirmCall = vi.fn(() => json(200, { data: { applied: 3, unchanged: 26, errors: [error] } }))
  mockFetch({ 'POST /api/v1/attendance/import/preview': previewCall, 'POST /api/v1/attendance/import/confirm': confirmCall })
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  const file = xlsx()
  await user.upload(screen.getByLabelText(FILE_LABEL), file)
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  await screen.findByText('تأكيد الحفظ')
  return { user, file, previewCall, confirmCall }
}

test('upload shows the preview and saves nothing until confirm', async () => {
  const { file, previewCall, confirmCall } = await previewed()
  expect(screen.getByText('تغييرات: 3 · بدون تغيير: 26 · أخطاء: 2')).toBeInTheDocument()
  const body = sent(previewCall)
  expect([body.get('group'), body.get('period_start'), body.get('file')]).toEqual(['engineer', '2026-09-26', file])
  expect(screen.getByText('لم يُحفظ أي شيء بعد. راجع المعاينة ثم أكّد الحفظ.')).toBeInTheDocument()
  const errors = screen.getByRole('table', { name: 'الأخطاء' })
  expect(within(errors).getByText('الموقع أو نوع اليوم غير موجود.')).toBeInTheDocument()
  expect(within(errors).getByText('G3')).toHaveAttribute('dir', 'ltr')
  expect(within(errors).getByText('5')).toHaveAttribute('dir', 'ltr') // a row error has no column: just its row number
  expect(within(screen.getByRole('table', { name: 'التغييرات' })).getByText('H2')).toBeInTheDocument()
  expect(confirmCall).not.toHaveBeenCalled()
})

test('confirm re-sends the same file and shows the result', async () => {
  const { user, file, confirmCall } = await previewed()
  await user.click(screen.getByRole('button', { name: 'تأكيد الحفظ' }))
  expect(await screen.findByText('تم حفظ 3 أيام، تُركت خانة واحدة بها خطأ.')).toBeInTheDocument()
  expect(sent(confirmCall).get('file')).toBe(file)
  expect(screen.queryByRole('table', { name: 'التغييرات' })).not.toBeInTheDocument()
})

test('confirm is disabled when the preview has no changes', async () => {
  const user = userEvent.setup()
  mockFetch({ 'POST /api/v1/attendance/import/preview': () => json(200, { data: { summary: { cells: 2, changes: 0, unchanged: 2, errors: 0 }, changes: [], errors: [] } }) })
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  await user.upload(screen.getByLabelText(FILE_LABEL), xlsx())
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  expect(await screen.findByRole('button', { name: 'تأكيد الحفظ' })).toBeDisabled()
})

// the server answers in the request's language; the banner follows the UI's, and keeps the server's words for a code it does not know
test.each([
  ['template_mismatch', 'The file does not match this period\'s attendance template.', 'الملف لا يطابق قالب الحضور لهذه الفترة.'],
  ['unreadable_file', 'The file could not be read.', 'تعذّرت قراءة الملف.'],
  ['too_many_rows', 'The file has more than 2000 rows.', 'يحتوي الملف على أكثر من 2000 صف.'],
  ['server_busy', 'رسالة الخادم.', 'رسالة الخادم.'],
])('a whole-file refusal (%s) shows in the UI language', async (code, message, shown) => {
  const user = userEvent.setup()
  mockFetch({ 'POST /api/v1/attendance/import/preview': () => json(422, { error: { code, message, details: [] } }) })
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  await user.upload(screen.getByLabelText(FILE_LABEL), xlsx())
  await user.click(screen.getByRole('button', { name: 'معاينة التغييرات' }))
  expect(await screen.findByRole('alert')).toHaveTextContent(shown)
})

test('a cell error follows the UI language, and an unknown code keeps the server message', async () => {
  const mystery = { row: 6, column: 'J', code: 7, date: '2026-10-06', error: 'mystery_code', message: 'رسالة لا يعرفها الواجهة.' }
  await previewed({ ...preview, errors: [error, mystery] })
  await act(() => i18n.changeLanguage('en'))
  const errors = screen.getByRole('table', { name: 'Errors' })
  expect(within(errors).getByText('Unknown site or day type.').tagName).toBe('BDI')
  expect(within(errors).queryByText('الموقع أو نوع اليوم غير موجود.')).not.toBeInTheDocument()
  expect(within(errors).getByText('رسالة لا يعرفها الواجهة.').tagName).toBe('BDI')
})

test('the preview names the employee in the UI language, falling back to the Arabic name', async () => {
  const named = { ...change(2, 'F', 'Site-1'), employee_id: 'e2', code: 2, name_ar: 'مهندس-2', name_en: 'Engineer Two' }
  await previewed({ ...preview, changes: [named, change(2, 'H', 'Site-1')] })
  const table = () => within(screen.getByRole('table', { name: /التغييرات|Changes/ }))
  expect(table().getByText('مهندس-2')).toBeInTheDocument()
  await act(() => i18n.changeLanguage('en'))
  expect(table().getByText('Engineer Two')).toBeInTheDocument()
  expect(table().queryByText('مهندس-2')).not.toBeInTheDocument()
  expect(table().getByText('Engineer-1')).toBeInTheDocument() // no name_en: the Arabic name
})

test('after a preview, confirm is the only primary action', async () => {
  const user = userEvent.setup()
  mockFetch({ 'POST /api/v1/attendance/import/preview': () => json(200, { data: preview }) })
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  await user.upload(screen.getByLabelText(FILE_LABEL), xlsx())
  const check = screen.getByRole('button', { name: 'معاينة التغييرات' })
  expect(check).toHaveClass('bg-primary')
  await user.click(check)
  expect(await screen.findByRole('button', { name: 'تأكيد الحفظ' })).toHaveClass('bg-primary')
  expect(check).not.toHaveClass('bg-primary')
  expect(check).toHaveClass('border-border', 'bg-card') // the outline variant, as Download template
})

test.each([
  [0, 'لم يُحفظ أي يوم', 'لا خانات بها أخطاء'],
  [1, 'تم حفظ يوم واحد', 'تُركت خانة واحدة بها خطأ'],
  [2, 'تم حفظ يومين', 'تُركت خانتان بهما أخطاء'],
  [3, 'تم حفظ 3 أيام', 'تُركت 3 خانات بها أخطاء'],
  [11, 'تم حفظ 11 يومًا', 'تُركت 11 خانة بها أخطاء'],
  [100, 'تم حفظ 100 يوم', 'تُركت 100 خانة بها أخطاء'],
])('the Arabic done line counts %i', (count, saved, skipped) => {
  const t = i18n.getFixedT('ar')
  expect([t('attendance.import.saved', { count }), t('attendance.import.skipped', { count })]).toEqual([saved, skipped])
})

test.each([
  [0, 'Saved 0 days', 'no cells with errors'],
  [1, 'Saved 1 day', 'skipped 1 cell with errors'],
  [3, 'Saved 3 days', 'skipped 3 cells with errors'],
])('the English done line counts %i', (count, saved, skipped) => {
  const t = i18n.getFixedT('en')
  expect([t('attendance.import.saved', { count }), t('attendance.import.skipped', { count })]).toEqual([saved, skipped])
})

test('the English done line joins the two counts', async () => {
  const { user } = await previewed()
  await act(() => i18n.changeLanguage('en'))
  await user.click(screen.getByRole('button', { name: 'Confirm and save' }))
  expect(await screen.findByText('Saved 3 days; skipped 1 cell with errors.')).toBeInTheDocument()
})

test('on a phone the import tab shows its short label, the full one from sm', async () => {
  const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 0 }
  mockFetch({ 'GET /api/v1/attendance/period': () => json(200, { data: period }) })
  renderWithAuth(<AttendancePage />, perms, { path: '/attendance?group=engineer&tab=import&period=2026-09-26' })
  const tab = await screen.findByRole('tab', { name: /الاستيراد من Excel/ })
  expect(within(tab).getByText('استيراد')).toHaveClass('sm:hidden')
  expect(within(tab).getByText('الاستيراد من Excel')).toHaveClass('max-sm:hidden')
})

test('a file over 5 MB or not xlsx is refused before upload', async () => {
  const user = userEvent.setup({ applyAccept: false })
  const fetch = vi.fn()
  vi.stubGlobal('fetch', fetch)
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  const big = xlsx()
  Object.defineProperty(big, 'size', { value: 5 * 1024 * 1024 + 1 })
  for (const f of [big, new File(['a,b'], 'attendance.csv', { type: 'text/csv' })]) {
    await user.upload(screen.getByLabelText(FILE_LABEL), f)
    expect(screen.getByText('اختر ملف ⁦.xlsx⁩ لا يتجاوز 5 ميجابايت.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'معاينة التغييرات' })).toBeDisabled()
  }
  expect(fetch).not.toHaveBeenCalled()
})

test('the import tab needs attendance.edit', async () => {
  const period = { group: 'engineer', start: '2026-09-26', end: '2026-10-25', prev_start: '2026-08-26', next_start: null, is_locked: false, lock: null, missing: 0 }
  mockFetch({ 'GET /api/v1/attendance/period': () => json(200, { data: period }), 'GET /api/v1/attendance/grid': () => json(200, { data: [], meta: { pagination: { total: 0 } } }) })
  const view = renderWithAuth(<AttendancePage />, ['attendance.view.all'], { path: '/attendance?group=engineer&tab=import' })
  expect(await screen.findByRole('tab', { name: /جدول الفترة/ })).toBeInTheDocument()
  expect(screen.queryByRole('tab', { name: /الاستيراد من Excel/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تنزيل القالب' })).not.toBeInTheDocument()
  view.unmount()
  renderWithAuth(<AttendancePage />, perms, { path: '/attendance?group=engineer&tab=import&period=2026-09-26' })
  expect(await screen.findByRole('tab', { name: /الاستيراد من Excel/ })).toHaveAttribute('aria-selected', 'true')
  expect(await screen.findByRole('button', { name: 'تنزيل القالب' })).toBeInTheDocument()
})

test('download saves the template under the group and period', async () => {
  const user = userEvent.setup()
  const template = vi.fn((_url: string) => new Response('PK', { status: 200 }))
  mockFetch({ 'GET /api/v1/attendance/template': template })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  const FakeURL = class extends URL { static createObjectURL = vi.fn(() => 'blob:t'); static revokeObjectURL = vi.fn() }
  vi.stubGlobal('URL', FakeURL)
  const saved: string[][] = []
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    saved.push([this.download, this.href])
  })
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  await user.click(screen.getByRole('button', { name: 'تنزيل القالب' }))
  await vi.waitFor(() => expect(saved).toEqual([['attendance-engineer-2026-09-26.xlsx', 'blob:t']]))
  expect(template.mock.calls[0][0]).toBe('/api/v1/attendance/template?group=engineer&period_start=2026-09-26')
  click.mockRestore()
  expect(FakeURL.revokeObjectURL).not.toHaveBeenCalled() // revoked later, so the browser has started the download
  await vi.waitFor(() => expect(FakeURL.revokeObjectURL).toHaveBeenCalledWith('blob:t'), { timeout: 2000 })
})

test('the file picker speaks the UI language and shows the chosen file', async () => {
  const user = userEvent.setup()
  renderWithAuth(<ImportTab group="engineer" periodStart="2026-09-26" />, perms)
  const input = screen.getByLabelText(FILE_LABEL)
  expect(input).toHaveClass('sr-only')
  expect(screen.getByText('اختيار ملف')).toHaveClass('peer-focus-visible:outline-2')
  expect(screen.getByText('لم يُختر ملف')).toBeInTheDocument()
  await user.upload(input, xlsx())
  expect(screen.getByText('attendance-engineer-2026-09-26.xlsx')).toBeInTheDocument()
  expect(screen.queryByText('لم يُختر ملف')).not.toBeInTheDocument()
})
