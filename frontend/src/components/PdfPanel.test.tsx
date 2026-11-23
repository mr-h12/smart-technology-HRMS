import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { canDo } from '@/lib/auth'
import { useApi } from '@/lib/query'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { canUsePdf, PDF_TYPES, PdfPanel, type PdfExport, type PdfType } from './PdfPanel'

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const HR = ['hr_forms.view.all', 'hr_forms.generate.all', 'employee.view.all']
const MANAGER = ['hr_forms.view.own', 'hr_forms.generate.own']
const LIST = 'GET /api/v1/hr-form-exports'

const row = (over: Partial<PdfExport> = {}): PdfExport => ({
  id: 'x1', type: 'leave_request', serial_number: 1, stage: 'unsigned', status: 'ready', employee_id: 'e1',
  created_at: '2026-11-14T15:05:00Z', file_name: 'leave-request-1-unsigned.pdf', actions: ['download'], ...over,
})
const answer = (rows: PdfExport[]) => (_url: string) => json(200, { data: rows, meta: { pagination } })
const sent = (fn: ReturnType<typeof vi.fn>, call = 0) => JSON.parse(String((fn.mock.calls[call] as [string, RequestInit])[1].body))

// The spec's registry table (design §2): name, required, max length (0 = none).
const SPEC: Record<PdfType, [string, boolean, number][]> = {
  experience_certificate: [['job_title', true, 160]],
  leave_request: [['job_title', true, 60], ['notification_method', false, 60], ['manager_opinion', false, 130], ['substitute', false, 60]],
  advance_request: [['job_title', true, 160], ['reason', true, 300], ['repayment_method', true, 0], ['previous_advance', false, 0], ['finance_remarks', false, 300]],
  overtime_request: [['job_title', true, 160], ['reason', true, 300]],
}

test.each(Object.keys(SPEC) as PdfType[])('%s with no PDF yet shows the empty line and the form with its fields', async (type) => {
  const list = vi.fn(answer([]))
  mockFetch({ [LIST]: list })
  const { container } = renderWithAuth(<PdfPanel type={type} subject="s1" />, HR)
  expect(await screen.findByText('لم يُنشأ ملف بعد')).toBeInTheDocument()
  expect(PDF_TYPES[type].fields.map((f) => f.name)).toEqual(SPEC[type].map(([name]) => name))
  const controls = [...container.querySelector('form')!.elements].filter((e): e is HTMLInputElement => !!(e as HTMLInputElement).name) as HTMLInputElement[]
  expect(controls.map((e) => [e.name, e.required, Math.max(e.maxLength ?? 0, 0)])).toEqual(SPEC[type])
  expect(screen.getByRole('button', { name: 'إنشاء' })).toBeEnabled()
  expect(list.mock.calls[0]?.[0]).toContain(`/hr-form-exports?type=${type}&subject_id=s1&per_page=100`)
})

test('a pending row hides View and Download, disables Generate, and polls every 3 s until the panel unmounts', async () => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
  const list = vi.fn(answer([row({ status: 'pending', actions: [] })]))
  mockFetch({ [LIST]: list })
  // Another reader of the same list stays mounted, so a timer left running after the panel goes would still refetch.
  function Page() {
    const [open, setOpen] = useState(true)
    useApi('/hr-form-exports?type=leave_request&subject_id=leave-1&per_page=100')
    return <>{open && <PdfPanel type="leave_request" subject="leave-1" />}<button type="button" onClick={() => setOpen(false)}>close</button></>
  }
  renderWithAuth(<Page />, HR)
  expect(await screen.findByRole('status')).toHaveTextContent('جارٍ إنشاء الملف')
  expect(screen.queryByRole('button', { name: 'عرض' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'تنزيل PDF' })).toBeNull()
  await user.click(screen.getByText('إنشاء نسخة جديدة'))
  expect(screen.getByRole('button', { name: 'إنشاء' })).toBeDisabled()
  expect(list).toHaveBeenCalledTimes(1)
  await act(() => vi.advanceTimersByTimeAsync(3000))
  await waitFor(() => expect(list).toHaveBeenCalledTimes(2))
  await user.click(screen.getByRole('button', { name: 'close' }))
  await act(() => vi.advanceTimersByTimeAsync(9000))
  expect(list).toHaveBeenCalledTimes(2)
})

test('a ready row with download shows View and Download only, and Download saves under the row file name', async () => {
  const user = userEvent.setup()
  mockFetch({ [LIST]: answer([row({ id: 'r1' })]), 'GET /api/v1/hr-form-exports/r1/download': () => new Response('%PDF-1.7') })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:pdf'); static revokeObjectURL = vi.fn() })
  const saved: string[] = []
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { saved.push(this.download) })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  expect(await screen.findByRole('button', { name: 'عرض' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'اعتماد' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'إعادة المحاولة' })).toBeNull()
  await user.click(screen.getByRole('button', { name: 'تنزيل PDF' }))
  await vi.waitFor(() => expect(saved).toEqual(['leave-request-1-unsigned.pdf']))
  click.mockRestore()
})

test('Approve shows when the row allows it and posts to the row approve route', async () => {
  const user = userEvent.setup()
  const approve = vi.fn(() => json(202, { data: row({ id: 'signed-1', stage: 'signed', status: 'pending', actions: [] }) }))
  mockFetch({ [LIST]: answer([row({ id: 'r1', actions: ['download', 'approve'] })]), 'POST /api/v1/hr-form-exports/r1/approve': approve })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all', 'hr_forms.approve_leave_request.all'])
  await user.click(await screen.findByRole('button', { name: 'اعتماد' }))
  expect(approve).toHaveBeenCalledOnce()
})

test('the server refusal of an approve shows inline', async () => {
  const user = userEvent.setup()
  mockFetch({
    [LIST]: answer([row({ id: 'r1', actions: ['download', 'approve'] })]),
    'POST /api/v1/hr-form-exports/r1/approve': () => json(422, { error: { code: 'validation_failed', message: 'ارفع صورة توقيعك أولًا.', details: [] } }),
  })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all', 'hr_forms.approve_leave_request.all'])
  await user.click(await screen.findByRole('button', { name: 'اعتماد' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('ارفع صورة توقيعك أولًا.')
})

test('a failed row offers Retry only when its actions say so', async () => {
  const user = userEvent.setup()
  const retry = vi.fn(() => json(202, { data: row({ id: 'f2', status: 'pending', actions: [] }) }))
  mockFetch({ [LIST]: answer([row({ id: 'f1', status: 'failed', actions: [] })]) })
  const first = renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  expect(await screen.findByText('تعذر إنشاء الملف')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إعادة المحاولة' })).toBeNull()
  first.unmount()

  mockFetch({ [LIST]: answer([row({ id: 'f2', status: 'failed', actions: ['retry'] })]), 'POST /api/v1/hr-form-exports/f2/retry': retry })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  await user.click(await screen.findByRole('button', { name: 'إعادة المحاولة' }))
  expect(retry).toHaveBeenCalledOnce()
})

test('a ready row whose actions are empty shows no button at all: buttons never come from the status', async () => {
  mockFetch({ [LIST]: answer([row({ actions: [] })]) })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  expect(await screen.findByText('جاهز')).toBeInTheDocument()
  expect(screen.queryAllByRole('button')).toEqual([])
})

test('Generate replays its idempotency key after a failed request and takes a new one after a create or a changed value', async () => {
  const user = userEvent.setup()
  const rows = [row({ id: 'r0', type: 'experience_certificate' })]
  const unavailable = () => json(503, { error: { code: 'unavailable', message: 'الخدمة غير متاحة' } })
  // As the server does, the list then shows the copy made (ready here, so Generate opens again).
  const created = () => {
    const made = row({ id: `c${rows.length}`, type: 'experience_certificate' })
    rows.unshift(made)
    return json(202, { data: { ...made, status: 'pending' } })
  }
  const answers: (() => Response)[] = [() => { throw new TypeError('Failed to fetch') }, unavailable, unavailable, created, created]
  let calls = 0
  const post = vi.fn(() => answers[calls++]!())
  mockFetch({ [LIST]: answer(rows), 'POST /api/v1/hr-form-exports': post })
  renderWithAuth(<PdfPanel type="experience_certificate" subject="e1" />, HR)
  await user.click(await screen.findByText('إنشاء نسخة جديدة'))
  const title = screen.getByLabelText('المسمى الوظيفي')
  const submit = async (n: number) => {
    const button = screen.getByRole('button', { name: 'إنشاء' })
    await waitFor(() => expect(button).toBeEnabled())
    await user.click(button)
    await waitFor(() => expect(post).toHaveBeenCalledTimes(n))
  }
  const key = (call: number) => sent(post, call).idempotency_key

  await user.type(title, 'مهندسة اختبار')
  await submit(1)
  expect(sent(post)).toEqual({ type: 'experience_certificate', subject_id: 'e1', idempotency_key: expect.any(String), fields: { job_title: 'مهندسة اختبار' } })
  expect(await screen.findByRole('alert')).toHaveTextContent('Failed to fetch')
  await submit(2)
  expect(key(1)).toBe(key(0))
  expect(await screen.findByRole('alert')).toHaveTextContent('الخدمة غير متاحة')

  await user.type(title, ' أولى')
  await submit(3)
  expect(sent(post, 2).fields).toEqual({ job_title: 'مهندسة اختبار أولى' })
  expect(key(2)).not.toBe(key(1))
  await submit(4)
  expect(key(3)).toBe(key(2))

  await submit(5)
  expect(sent(post, 4).fields).toEqual(sent(post, 3).fields)
  expect(key(4)).not.toBe(key(3))
})

test('after a create, Generate stays disabled until the list shows the created row, so a fast second click sends nothing', async () => {
  const user = userEvent.setup()
  const old = row({ id: 'r0', type: 'experience_certificate' })
  let release: (r: Response) => void = () => {}
  let lists = 0
  const list = () => (lists++ ? new Promise<Response>((r) => { release = r }) as unknown as Response : json(200, { data: [old], meta: { pagination } }))
  const post = vi.fn(() => json(202, { data: row({ id: 'c1', type: 'experience_certificate', status: 'pending', actions: [] }) }))
  mockFetch({ [LIST]: list, 'POST /api/v1/hr-form-exports': post })
  renderWithAuth(<PdfPanel type="experience_certificate" subject="e1" />, HR)
  await user.click(await screen.findByText('إنشاء نسخة جديدة'))
  const title = screen.getByLabelText('المسمى الوظيفي')
  await user.type(title, 'مهندسة اختبار')
  const generate = screen.getByRole('button', { name: 'إنشاء' })
  await user.click(generate)
  await waitFor(() => expect(lists).toBe(2))
  await waitFor(() => expect(title).toBeEnabled())
  await user.click(generate)
  expect(post).toHaveBeenCalledOnce()
  expect(generate).toBeDisabled()
  release(json(200, { data: [row({ id: 'c1', type: 'experience_certificate' }), old], meta: { pagination } }))
  await waitFor(() => expect(generate).toBeEnabled())
})

test('a copy approved through View as says so on its row; a copy approved otherwise does not', async () => {
  mockFetch({ [LIST]: answer([row({ id: 's1', stage: 'signed', impersonator_id: 'dev-1' }), row({ id: 's0', serial_number: 0, stage: 'signed' })]) })
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  const [viewAs, normal] = await screen.findAllByRole('listitem')
  expect(within(viewAs!).getByText('اعتُمدت بالعرض بحساب آخر')).toBeInTheDocument()
  expect(within(normal!).queryByText('اعتُمدت بالعرض بحساب آخر')).toBeNull()
})

test('View opens the file in the app, with an Open link and no frame where the browser has no PDF viewer', async () => {
  const user = userEvent.setup()
  Object.defineProperty(navigator, 'pdfViewerEnabled', { value: false, configurable: true })
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:pdf'); static revokeObjectURL = vi.fn() })
  const file = vi.fn(() => new Response('%PDF-1.7'))
  mockFetch({ [LIST]: answer([row({ id: 'r1', type: 'experience_certificate', serial_number: 4, file_name: 'experience-certificate-4-unsigned.pdf' })]), 'GET /api/v1/hr-form-exports/r1/download': file })
  renderWithAuth(<PdfPanel type="experience_certificate" subject="e1" />, ['hr_forms.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض' }))
  const dialog = await screen.findByRole('dialog', { name: 'شهادة خبرة · مسلسل 4 · بدون توقيع' })
  expect(await within(dialog).findByRole('link', { name: 'فتح' })).toHaveAttribute('href', 'blob:pdf')
  expect(dialog.querySelector('iframe')).toBeNull()
  expect(within(dialog).getByRole('link', { name: 'تنزيل' })).toHaveAttribute('download', 'experience-certificate-4-unsigned.pdf')
  expect(file).toHaveBeenCalledOnce()
})

test.each([['on the page', false], ['inside another dialog', true]] as const)('closing the viewer by Escape or ✕ gives focus back to View %s', async (_where, nested) => {
  const user = userEvent.setup()
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:pdf'); static revokeObjectURL = vi.fn() })
  mockFetch({ [LIST]: answer([row({ id: 'r1', type: 'experience_certificate', serial_number: 4, stage: 'signed' })]), 'GET /api/v1/hr-form-exports/r1/download': () => new Response('%PDF-1.7') })
  const panel = <PdfPanel type="experience_certificate" subject="e1" />
  renderWithAuth(nested ? <Dialog open><DialogContent aria-describedby={undefined}><DialogTitle>طلب إجازة</DialogTitle>{panel}</DialogContent></Dialog> : panel, ['hr_forms.view.all'])
  const view = await screen.findByRole('button', { name: 'عرض' })
  const viewer = () => screen.findByRole('dialog', { name: 'شهادة خبرة · مسلسل 4 · موقّعة' })
  for (const close of [() => user.keyboard('{Escape}'), async () => user.click(within(await viewer()).getByRole('button', { name: 'إغلاق' }))]) {
    await user.click(view)
    await viewer()
    await close()
    await vi.waitFor(() => expect(document.activeElement).toBe(view))
  }
  if (nested) expect(screen.getByRole('dialog', { name: 'طلب إجازة' })).toContainElement(view)
})

test('pay and no-pay copies each keep their newest row current, the pay copy first; the rest go under earlier copies', async () => {
  const user = userEvent.setup()
  mockFetch({
    [LIST]: answer([
      row({ id: 'n3', type: 'advance_request', serial_number: 3, shows_pay: false }),
      row({ id: 'p2', type: 'advance_request', serial_number: 2, shows_pay: true }),
      row({ id: 'p1', type: 'advance_request', serial_number: 1, shows_pay: true }),
    ]),
  })
  renderWithAuth(<PdfPanel type="advance_request" subject="a1" />, HR)
  const earlier = (await screen.findByText('نسخ سابقة (1)')).closest('details')!
  const current = screen.getAllByRole('listitem').filter((li) => !earlier.contains(li))
  expect(current.map((li) => within(li).getByText(/مسلسل/).textContent)).toEqual(['مسلسل 2', 'مسلسل 3'])
  expect(within(current[1]!).getByText('بدون الراتب')).toBeInTheDocument()
  expect(within(current[0]!).queryByText('بدون الراتب')).toBeNull()
  expect(within(earlier).getByText('مسلسل 1')).toBeInTheDocument()
  await user.click(screen.getByText('إنشاء نسخة جديدة'))
  expect(screen.getByRole('button', { name: 'إنشاء' })).toBeEnabled()
})

test('a pending no-pay copy does not block Generate for a caller who sees pay', async () => {
  const user = userEvent.setup()
  mockFetch({
    [LIST]: answer([
      row({ id: 'n3', type: 'advance_request', serial_number: 3, shows_pay: false, status: 'pending', actions: [] }),
      row({ id: 'p2', type: 'advance_request', serial_number: 2, shows_pay: true }),
    ]),
  })
  renderWithAuth(<PdfPanel type="advance_request" subject="a1" />, HR)
  await user.click(await screen.findByText('إنشاء نسخة جديدة'))
  expect(screen.getByRole('button', { name: 'إنشاء' })).toBeEnabled()
})

test('canUsePdf opens only the advance to own-scope callers; the panel hides without view and drops the form without generate', async () => {
  const can = (a: string) => canDo(MANAGER, a)
  for (const action of ['view', 'generate'] as const) {
    expect((Object.keys(PDF_TYPES) as PdfType[]).filter((type) => canUsePdf(can, action, type))).toEqual(['advance_request'])
  }
  mockFetch({ [LIST]: answer([]) })
  const hidden = renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, MANAGER)
  expect(hidden.container).toBeEmptyDOMElement()
  hidden.unmount()

  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, ['hr_forms.view.all'])
  expect(await screen.findByText('لم يُنشأ ملف بعد')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إنشاء' })).toBeNull()
  expect(screen.queryByLabelText('المسمى الوظيفي')).toBeNull()
})

test('the finance fields are absent and unsent for a caller who does not see pay', async () => {
  const user = userEvent.setup()
  const post = vi.fn(() => json(202, { data: row({ status: 'pending' }) }))
  mockFetch({ [LIST]: answer([]), 'POST /api/v1/hr-form-exports': post })
  const { container } = renderWithAuth(<PdfPanel type="advance_request" subject="a1" />, MANAGER)
  await user.type(await screen.findByLabelText('المسمى الوظيفي'), 'عامل')
  await user.type(screen.getByLabelText('سبب السلفة'), 'علاج')
  await user.selectOptions(screen.getByLabelText('طريقة التسديد'), 'cash')
  expect(container.querySelector('[name="previous_advance"], [name="finance_remarks"]')).toBeNull()
  await user.click(screen.getByRole('button', { name: 'إنشاء' }))
  await waitFor(() => expect(post).toHaveBeenCalledOnce())
  expect(sent(post).fields).toEqual({ job_title: 'عامل', reason: 'علاج', repayment_method: 'cash' })
})

test('once a file exists, the advance panel says to generate a new copy if the amount changed; the other types do not', async () => {
  for (const type of Object.keys(PDF_TYPES) as PdfType[]) {
    mockFetch({ [LIST]: answer([row({ type })]) })
    const panel = renderWithAuth(<PdfPanel type={type} subject="s1" />, HR)
    await screen.findByRole('button', { name: 'عرض' })
    expect(screen.queryByText(/تغيّر مبلغ السلفة/) !== null, type).toBe(type === 'advance_request')
    panel.unmount()
  }
})

test('reopening the panel asks the server again, so Approve follows a decision made meanwhile', async () => {
  let actions: PdfExport['actions'] = ['download']
  mockFetch({ [LIST]: () => json(200, { data: [row({ actions })], meta: { pagination } }) })
  const permissions = ['hr_forms.view.all', 'hr_forms.approve_leave_request.all']
  const first = renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, permissions)
  await screen.findByRole('button', { name: 'تنزيل PDF' })
  expect(screen.queryByRole('button', { name: 'اعتماد' })).toBeNull()
  first.unmount()

  actions = ['download', 'approve']
  renderWithAuth(<PdfPanel type="leave_request" subject="leave-1" />, permissions)
  expect(await screen.findByRole('button', { name: 'اعتماد' })).toBeInTheDocument()
})
