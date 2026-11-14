import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { DecideDialog, RequestDialog } from './leave-dialogs'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.clearAllMocks()
})

const PREVIEW = 'GET /api/v1/leave/requests/preview'
const balance = { entitlement: 21, casual_cap: 6, used: 19, casual_used: 3, remaining: 2, casual_remaining: 3, sick_days: 0, unpaid_days: 0 }
const preview = (p: object = {}) => () => json(200, { data: { dates: ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-15'], days: 4, balance, keep: 2, unpaid: 2, remaining_after: 0, ...p } })
const sami = { id: 'e1', code: 101, name_ar: 'سامي فؤاد', name_en: 'Sami Fouad', current_type: 'engineer' }
const user = userEvent.setup()

const dates = (from: string, to: string) => {
  fireEvent.change(screen.getByLabelText('من'), { target: { value: from } })
  fireEvent.change(screen.getByLabelText('إلى'), { target: { value: to } })
}
const previewQuery = () => new URL(String(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/preview')).at(-1)), 'http://x').searchParams
const open = (onOpenChange = vi.fn()) => renderWithAuth(<RequestDialog open onOpenChange={onOpenChange} employee={sami} />, ['leave.request.all'])

test('the preview shows the counted days, the balance after, and the unpaid days as a dual', async () => {
  mockFetch({ [PREVIEW]: preview() })
  open()
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^4 أيام محسوبة \(أيام الراحة الأسبوعية والإجازات الرسمية غير محسوبة\)، الرصيد بعدها 0$/))
  expect(screen.getByText('يومان بدون أجر')).toBeInTheDocument()
  expect(Object.fromEntries(previewQuery())).toEqual({ employee_id: 'e1', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-15' })
})

test('an overdrawn balance after reads with its minus sign first', async () => {
  mockFetch({ [PREVIEW]: preview({ keep: 0, unpaid: 4, remaining_after: -3 }) })
  open()
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/الرصيد بعدها -3$/))
  expect(within(screen.getByRole('status')).getByText('-3')).toHaveAttribute('dir', 'ltr')
})

test('one unpaid day and eleven unpaid days take their Arabic forms', async () => {
  mockFetch({ [PREVIEW]: preview({ unpaid: 1 }) })
  const first = open()
  dates('2026-11-10', '2026-11-15')
  expect(await screen.findByText('يوم واحد بدون أجر')).toBeInTheDocument()
  first.unmount()
  mockFetch({ [PREVIEW]: preview({ days: 11, unpaid: 11 }) })
  open()
  dates('2026-11-01', '2026-11-14')
  expect(await screen.findByText('11 يومًا بدون أجر')).toBeInTheDocument()
})

test('without a balance the line drops the balance clause, and nothing unpaid shows no warning', async () => {
  mockFetch({ [PREVIEW]: preview({ balance: null, keep: 4, unpaid: 0, remaining_after: null }) })
  open()
  await user.selectOptions(screen.getByLabelText('نوع الإجازة'), 'sick')
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^4 أيام محسوبة \(أيام الراحة الأسبوعية والإجازات الرسمية غير محسوبة\)$/))
  expect(screen.queryByText(/الرصيد بعدها/)).toBeNull()
  expect(screen.queryByText(/بدون أجر/, { ignore: 'option, script, style' })).toBeNull() // the kind list itself has «إجازة بدون أجر»
  expect(previewQuery().get('kind')).toBe('sick')
})

test('a refused preview shows the reason in the dialog and leaves sending to the server', async () => {
  const refusal = () => json(422, { error: { code: 'leave_overlap', message: 'يتداخل هذا الطلب مع طلب إجازة آخر.' } })
  const post = vi.fn(refusal)
  mockFetch({ [PREVIEW]: refusal, 'POST /api/v1/leave/requests': post })
  open()
  dates('2026-11-10', '2026-11-15')
  expect(await screen.findByRole('alert')).toHaveTextContent('يتداخل هذا الطلب مع طلب إجازة آخر.')
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
})

test('editing a date hides the preview until the answer for the new dates arrives', async () => {
  let release!: () => void
  const later = new Promise<void>((r) => (release = r))
  mockFetch({ [PREVIEW]: ((url: string) => (url.includes('to_date=2026-11-16') ? later.then(preview({ days: 5, unpaid: 3 })) : preview()())) as never })
  open()
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^4 أيام محسوبة/))
  expect(screen.getByText('يومان بدون أجر')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('إلى'), { target: { value: '2026-11-16' } })
  expect(screen.queryByRole('status')).toBeNull()
  expect(screen.queryByText('يومان بدون أجر')).toBeNull()
  await waitFor(() => expect(previewQuery().get('to_date')).toBe('2026-11-16'))
  expect(screen.queryByRole('status')).toBeNull()
  release()
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^5 أيام محسوبة/))
  expect(screen.getByText('3 أيام بدون أجر')).toBeInTheDocument()
})

test('sending posts the request, says so, and closes', async () => {
  const post = vi.fn(() => json(201, { data: { id: 'r1', status: 'pending' } }))
  const onOpenChange = vi.fn()
  mockFetch({ [PREVIEW]: preview(), 'POST /api/v1/leave/requests': post })
  open(onOpenChange)
  dates('2026-11-10', '2026-11-15')
  await user.type(screen.getByLabelText('ملاحظة'), 'سفر')
  await screen.findByText(/4 أيام محسوبة/)
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(JSON.parse(String((post.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ employee_id: 'e1', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-15', note: 'سفر' })
  expect(toast.success).toHaveBeenCalledWith('أُرسل طلب الإجازة.')
})

test('a refused submit keeps the dialog open with the reason', async () => {
  const onOpenChange = vi.fn()
  mockFetch({ [PREVIEW]: preview(), 'POST /api/v1/leave/requests': () => json(422, { error: { code: 'leave_overlap', message: 'يتداخل هذا الطلب مع طلب إجازة آخر.' } }) })
  open(onOpenChange)
  dates('2026-11-10', '2026-11-15')
  await screen.findByText(/4 أيام محسوبة/)
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('يتداخل هذا الطلب')
  expect(onOpenChange).not.toHaveBeenCalled()
})

test('with the employee fixed there is no employee picker', async () => {
  mockFetch({})
  open()
  expect(screen.queryByLabelText('الموظف')).toBeNull()
})

test('the employee picker searches the employees in reach and offers day laborers too (the preview refuses them)', async () => {
  const worker = { id: 'e2', code: 202, name_ar: 'سعيد حسن', name_en: null, current_type: 'day_laborer' }
  const post = vi.fn(() => json(201, { data: { id: 'r1' } }))
  mockFetch({
    'GET /api/v1/employees': () => json(200, { data: [sami, worker], meta: { pagination: { ...pagination, total: 2 } } }),
    [PREVIEW]: preview(),
    'POST /api/v1/leave/requests': post,
  })
  renderWithAuth(<RequestDialog open onOpenChange={vi.fn()} />, ['leave.request.own', 'employee.view.own'])
  await user.type(screen.getByRole('textbox', { name: 'بحث بالاسم أو الرقم الوظيفي' }), 'سا')
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.some(([u]) => new URL(String(u), 'http://x').searchParams.get('search') === 'سا')).toBe(true))
  const picker = screen.getByLabelText('الموظف')
  await waitFor(() => expect(within(picker).getByRole('option', { name: 'سامي فؤاد (101)' })).toBeInTheDocument())
  expect(within(picker).getByRole('option', { name: 'سعيد حسن (202)' })).toBeInTheDocument()
  await user.selectOptions(picker, 'e1')
  dates('2026-11-10', '2026-11-15')
  await screen.findByText(/4 أيام محسوبة/)
  expect(previewQuery().get('employee_id')).toBe('e1')
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
})

test('nothing is previewed until employee, from and to are all set', async () => {
  mockFetch({ [PREVIEW]: preview() })
  renderWithAuth(<RequestDialog open onOpenChange={vi.fn()} />, ['leave.request.own'])
  dates('2026-11-10', '2026-11-15')
  await new Promise((r) => setTimeout(r, 400))
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/preview'))).toBe(false)
})

const SHOW = 'GET /api/v1/leave/requests/r1'
const shown = (p: object = {}, approval: object | null = {}) => ({
  id: 'r1', employee: { id: 'e1', code: 101, name_ar: 'سامي فؤاد', name_en: 'Sami Fouad' }, kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-15', days: 4,
  status: 'pending', note: 'سفر عائلي', requested_by: { id: 'u2', name: 'كريم حسن' }, created_at: '2026-11-04T08:00:00Z', version: 3,
  decision_reason: null, decided_by: null, decided_at: null,
  approval: approval && { dates: ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-15'], days: 4, balance, keep: 4, unpaid: 0, remaining_after: -2, blocked: [], replaced: [], ...approval },
  ...p,
})
const decide = (perms: string[], onOpenChange = vi.fn()) => renderWithAuth(<DecideDialog id="r1" open onOpenChange={onOpenChange} />, perms)
const HR = ['leave.view.all', 'leave.decide.all']
const CEO = ['leave.view.all', 'leave.decide_escalated.all']
const term = (label: string) => screen.getByText(label, { selector: 'dt' }).parentElement!
const buttons = () => within(screen.getByRole('dialog')).queryAllByRole('button').map((b) => b.textContent).filter((n) => n !== 'إغلاق')
const bodyOf = (m: ReturnType<typeof vi.fn>) => JSON.parse(String((m.mock.calls[0] as unknown as [string, RequestInit])[1].body))

test('the decide dialog shows the request, the requester, and the balance before and after', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown() }) })
  decide(HR)
  expect(await screen.findByText('سامي فؤاد', { exact: false })).toBeInTheDocument()
  expect(term('الموظف')).toHaveTextContent('سامي فؤاد (101)')
  expect(term('نوع الإجازة')).toHaveTextContent('إجازة اعتيادية')
  expect(term('الفترة')).toHaveTextContent('2026-11-10 – 2026-11-15')
  expect(term('الأيام المحسوبة')).toHaveTextContent('4')
  expect(term('ملاحظة')).toHaveTextContent('سفر عائلي')
  expect(term('مقدّم الطلب')).toHaveTextContent('كريم حسن')
  expect(term('الرصيد قبل الموافقة')).toHaveTextContent(/2$/)
  expect(within(term('الرصيد بعد الموافقة')).getByText('-2')).toHaveClass('text-destructive')
  expect(screen.queryByText('العارضة المتبقية قبل الموافقة')).toBeNull()
})

test('a reader who decides but does not file for anyone is offered escalate, refuse and approve on a pending request', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown() }) })
  decide(HR)
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual(['إحالة إلى الرئيس التنفيذي', 'رفض', 'موافقة'])
})

test('the real HR role sees cancel, escalate, refuse and approve on a pending request, in that order', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown() }) })
  decide(['leave.view.all', 'leave.request.all', 'leave.decide.all'])
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual(['إلغاء الطلب', 'إحالة إلى الرئيس التنفيذي', 'رفض', 'موافقة'])
})

test('an escalated request offers the CEO approve and refuse, and shows the escalation note', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'escalated', decision_reason: 'الرصيد سالب' }) }) })
  decide(CEO)
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual(['رفض', 'موافقة'])
  expect(term('ملاحظة الإحالة')).toHaveTextContent('الرصيد سالب')
})

test('a decider who does not file for anyone sees an escalated request read-only', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'escalated' }) }) })
  decide(HR)
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual([])
  expect(term('الحالة')).toHaveTextContent('محال إلى الرئيس التنفيذي')
})

test('the CEO cannot decide a pending request, nor can a reader without a decide permission', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown() }) })
  const ceo = decide(CEO)
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual([])
  ceo.unmount()
  decide(['leave.view.own'])
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual([])
})

test('a decided request is read-only with its status, decider, date and reason', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'refused', decision_reason: 'ضغط العمل', decided_by: { id: 'u1', name: 'منى' }, decided_at: '2026-11-05T08:00:00Z' }, null) }) })
  decide(HR)
  await screen.findByText('كريم حسن')
  expect(buttons()).toEqual([])
  expect(term('الحالة')).toHaveTextContent('مرفوض')
  expect(term('صاحب القرار')).toHaveTextContent('منى')
  expect(term('تاريخ القرار')).toHaveTextContent('2026')
  expect(term('سبب الرفض')).toHaveTextContent('ضغط العمل')
  expect(screen.queryByText('الرصيد بعد الموافقة')).toBeNull()
})

test('days beyond the balance show the split and an amber warning', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({}, { keep: 2, unpaid: 2, remaining_after: 0 }) }) })
  decide(HR)
  expect(await screen.findByText('2 إجازة اعتيادية + 2 بدون أجر')).toBeInTheDocument()
  expect(screen.getByText(/الرصيد لا يكفي/)).toHaveTextContent(/^الرصيد لا يكفي، فتُحتسب آخر أيام الفترة بدون أجر\.$/)
})

test('casual leave shows the casual days remaining before', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ kind: 'casual' }, { remaining_after: 0 }) }) })
  decide(HR)
  await screen.findByText('كريم حسن')
  expect(term('العارضة المتبقية قبل الموافقة')).toHaveTextContent(/3$/)
})

test('blocked dates are listed before the click, and approve stays available', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({}, { blocked: ['2026-11-11'] }) }) })
  decide(HR)
  expect(await screen.findByText('2026-11-11')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'موافقة' })).toBeEnabled()
})

const replaced = [{ date: '2026-11-11', leave_code: 'sick' }, { date: '2026-11-12', leave_code: 'casual' }]
const replacedItem = (text: string) => screen.findByText((_, el) => el?.tagName === 'LI' && el.textContent === text)
const replaceBox = () => screen.getByRole('checkbox', { name: 'استبدال هذه الأيام' })

test('days of another leave type are listed with their type, and approve waits for the replace tick', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'approved' }, null) }))
  mockFetch({ [SHOW]: () => json(200, { data: shown({}, { replaced }) }), 'POST /api/v1/leave/requests/r1/approve': post })
  decide(HR)
  const alert = (await screen.findByText('هذه الأيام مسجّل عليها نوع إجازة آخر. الموافقة تستبدلها، وتبقى الملاحظة والسلفة.')).parentElement!
  expect(within(alert).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['2026-11-11 إجازة مرضية', '2026-11-12 إجازة عارضة'])
  expect(within(alert).getByText('2026-11-11')).toHaveAttribute('dir', 'ltr')
  const approve = screen.getByRole('button', { name: 'موافقة' })
  expect(approve).toBeDisabled()
  await user.click(replaceBox())
  expect(approve).toBeEnabled()
  await user.click(approve)
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ version: 3, replace_dates: ['2026-11-11', '2026-11-12'] })
})

test('the replace tick resets when the listed days change', async () => {
  let days = [replaced[0]]
  mockFetch({
    [SHOW]: () => json(200, { data: shown({}, { replaced: days }) }),
    'POST /api/v1/leave/requests/r1/approve': () => {
      days = replaced
      return json(409, { error: { code: 'leave_days_replaced', message: 'عدد الأيام المسجّلة بنوع إجازة آخر: 1.', dates: ['2026-11-12'] } })
    },
  })
  decide(HR)
  await user.click(await screen.findByRole('checkbox', { name: 'استبدال هذه الأيام' }))
  await user.click(screen.getByRole('button', { name: 'موافقة' }))
  expect(await replacedItem('2026-11-12 إجازة عارضة')).toBeInTheDocument()
  expect(replaceBox()).not.toBeChecked()
  expect(screen.getByRole('button', { name: 'موافقة' })).toBeDisabled()
})

test('a reader who cannot approve sees the replaced days without the tick', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'escalated' }, { replaced }) }) })
  decide(HR)
  expect(await replacedItem('2026-11-11 إجازة مرضية')).toBeInTheDocument()
  expect(screen.queryByRole('checkbox')).toBeNull()
})

test('approve posts the version, says so, and closes', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'approved', requested_by: theirs }, null) }))
  const onOpenChange = vi.fn()
  mockFetch({ [SHOW]: () => json(200, { data: shown() }), 'POST /api/v1/leave/requests/r1/approve': post })
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'موافقة' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(bodyOf(post)).toEqual({ version: 3 })
  expect(toast.success).toHaveBeenCalledWith('تمت الموافقة على الطلب.')
})

test('a decision refreshes the open leave form panel, so its sign button follows', async () => {
  let actions = ['download']
  mockFetch({
    [SHOW]: () => json(200, { data: shown() }),
    'POST /api/v1/leave/requests/r1/approve': () => {
      actions = ['download', 'approve']
      return json(200, { data: shown({ status: 'approved' }, null) })
    },
    'GET /api/v1/hr-form-exports': () => json(200, { data: [{ id: 'form-1', serial_number: 1, stage: 'unsigned', status: 'ready', file_name: 'leave-request-1-unsigned.pdf', actions }], meta: { pagination } }),
  })
  decide([...HR, 'hr_forms.view.all', 'hr_forms.approve_leave_request.all'])
  await screen.findByRole('button', { name: 'تنزيل الملف' })
  expect(screen.queryByRole('button', { name: 'اعتماد وتوقيع' })).toBeNull()
  await user.click(screen.getByRole('button', { name: 'موافقة' }))
  expect(await screen.findByRole('button', { name: 'اعتماد وتوقيع' })).toBeInTheDocument()
})

test('an approval refused for blocked days shows the reason and lists the dates', async () => {
  let blocked: string[] = []
  mockFetch({
    [SHOW]: () => json(200, { data: shown({}, { blocked }) }),
    'POST /api/v1/leave/requests/r1/approve': () => {
      blocked = ['2026-11-12']
      return json(409, { error: { code: 'leave_days_blocked', message: 'يوم واحد من أيام الإجازة مسجّل بالفعل في الحضور.' } })
    },
  })
  const onOpenChange = vi.fn()
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'موافقة' }))
  expect(await screen.findByText('يوم واحد من أيام الإجازة مسجّل بالفعل في الحضور.')).toBeInTheDocument()
  expect(await screen.findByText('2026-11-12')).toBeInTheDocument()
  expect(onOpenChange).not.toHaveBeenCalled()
})

test('a stale version shows the conflict and reloads the request', async () => {
  let status = 'pending'
  mockFetch({
    [SHOW]: () => json(200, { data: shown({ status, version: status === 'pending' ? 3 : 4 }, status === 'pending' ? {} : null) }),
    'POST /api/v1/leave/requests/r1/approve': () => {
      status = 'approved'
      return json(409, { error: { code: 'concurrency_conflict', message: 'عُدِّل هذا السجل في أثناء ذلك.' } })
    },
  })
  decide(HR)
  await user.click(await screen.findByRole('button', { name: 'موافقة' }))
  expect(await screen.findByText('عُدِّل هذا السجل في أثناء ذلك.')).toBeInTheDocument()
  await waitFor(() => expect(term('الحالة')).toHaveTextContent('موافق عليه'))
  // decided now: only HR's cancel of an approved request is left
  expect(buttons()).toEqual(['إلغاء الطلب'])
})

test('refuse needs a reason, then posts it with the version', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'refused' }, null) }))
  const onOpenChange = vi.fn()
  mockFetch({ [SHOW]: () => json(200, { data: shown() }), 'POST /api/v1/leave/requests/r1/refuse': post })
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'رفض' }))
  const ask = await screen.findByRole('dialog', { name: 'رفض طلب الإجازة' })
  await user.type(within(ask).getByLabelText('سبب الرفض'), '   ')
  await user.click(within(ask).getByRole('button', { name: 'رفض الطلب' }))
  expect(await within(ask).findByText('اكتب سبب الرفض.')).toBeInTheDocument()
  expect(post).not.toHaveBeenCalled()
  await user.type(within(ask).getByLabelText('سبب الرفض'), 'ضغط العمل')
  await user.click(within(ask).getByRole('button', { name: 'رفض الطلب' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(bodyOf(post)).toEqual({ version: 3, reason: 'ضغط العمل' })
  expect(toast.success).toHaveBeenCalledWith('رُفض الطلب.')
})

test('escalate sends the note only when one is written', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'escalated' }) }))
  mockFetch({ [SHOW]: () => json(200, { data: shown() }), 'POST /api/v1/leave/requests/r1/escalate': post })
  decide(HR)
  await user.click(await screen.findByRole('button', { name: 'إحالة إلى الرئيس التنفيذي' }))
  const ask = await screen.findByRole('dialog', { name: 'إحالة الطلب إلى الرئيس التنفيذي' })
  await user.click(within(ask).getByRole('button', { name: 'إحالة الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ version: 3 })
  expect(toast.success).toHaveBeenCalledWith('أُحيل الطلب إلى الرئيس التنفيذي.')
})

test('escalate sends the note typed with it', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'escalated' }) }))
  mockFetch({ [SHOW]: () => json(200, { data: shown() }), 'POST /api/v1/leave/requests/r1/escalate': post })
  decide(HR)
  await user.click(await screen.findByRole('button', { name: 'إحالة إلى الرئيس التنفيذي' }))
  const ask = await screen.findByRole('dialog', { name: 'إحالة الطلب إلى الرئيس التنفيذي' })
  await user.type(within(ask).getByRole('textbox'), '  الرصيد سالب  ')
  await user.click(within(ask).getByRole('button', { name: 'إحالة الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ version: 3, reason: 'الرصيد سالب' })
})

test('a refusal the server rejects closes the reason form and shows the conflict in the request dialog', async () => {
  let status = 'pending'
  mockFetch({
    [SHOW]: () => json(200, { data: shown({ status, version: status === 'pending' ? 3 : 4 }, status === 'pending' ? {} : null) }),
    'POST /api/v1/leave/requests/r1/refuse': () => {
      status = 'approved'
      return json(409, { error: { code: 'concurrency_conflict', message: 'عُدِّل هذا السجل في أثناء ذلك.' } })
    },
  })
  const onOpenChange = vi.fn()
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'رفض' }))
  const ask = await screen.findByRole('dialog', { name: 'رفض طلب الإجازة' })
  await user.type(within(ask).getByLabelText('سبب الرفض'), 'ضغط العمل')
  await user.click(within(ask).getByRole('button', { name: 'رفض الطلب' }))
  await waitFor(() => expect(screen.queryByRole('dialog', { name: 'رفض طلب الإجازة' })).toBeNull())
  const outer = screen.getByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(outer).findByText('عُدِّل هذا السجل في أثناء ذلك.')).toBeInTheDocument()
  await waitFor(() => expect(term('الحالة')).toHaveTextContent('موافق عليه'))
  expect(screen.getByText('عُدِّل هذا السجل في أثناء ذلك.')).toBeInTheDocument()
  expect(onOpenChange).not.toHaveBeenCalled()
  expect(toast.success).not.toHaveBeenCalled()
})

test('the request dialog does not close while a decision is in flight', async () => {
  let answer!: () => void
  const post = vi.fn(() => new Promise<Response>((r) => (answer = () => r(json(200, { data: shown({ status: 'approved', requested_by: theirs }, null) })))))
  const onOpenChange = vi.fn()
  mockFetch({ [SHOW]: () => json(200, { data: shown() }), 'POST /api/v1/leave/requests/r1/approve': post as never })
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'موافقة' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  await user.keyboard('{Escape}')
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'إغلاق' }))
  expect(onOpenChange).not.toHaveBeenCalled()
  answer()
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(toast.success).toHaveBeenCalledWith('تمت الموافقة على الطلب.')
})

const LEAD = ['leave.view.own', 'leave.request.own']
const mine = { id: 'u1', name: 'منى' }
const theirs = { id: 'u2', name: 'كريم حسن' }
const cancelButton = () => within(screen.getByRole('dialog')).queryByRole('button', { name: 'إلغاء الطلب' })

test.each([
  ['a site manager, on their own pending request', LEAD, { status: 'pending', requested_by: mine }, true],
  ['a site manager, on their own escalated request', LEAD, { status: 'escalated', requested_by: mine }, true],
  ['a site manager, on their own approved request', LEAD, { status: 'approved', requested_by: mine }, false],
  ["a site manager, on someone else's pending request", LEAD, { status: 'pending', requested_by: theirs }, false],
  ['HR filing for anyone, on a pending request', ['leave.view.all', 'leave.request.all'], { status: 'pending', requested_by: theirs }, true],
  ['HR filing for anyone, on an escalated request', ['leave.view.all', 'leave.request.all'], { status: 'escalated', requested_by: theirs }, true],
  ['HR deciding for anyone, on an approved request', HR, { status: 'approved', requested_by: theirs }, true],
  ['a reader who files for anyone but does not decide, on an approved request', ['leave.view.all', 'leave.request.all'], { status: 'approved', requested_by: theirs }, false],
  ['the CEO, on an approved request', CEO, { status: 'approved', requested_by: theirs }, false],
  ['HR with every leave permission, on a refused request of their own', [...HR, 'leave.request.all'], { status: 'refused', requested_by: mine }, false],
  ['HR with every leave permission, on a cancelled request of their own', [...HR, 'leave.request.all'], { status: 'cancelled', requested_by: mine }, false],
])('the cancel button for %s', async (_, perms, p, offered) => {
  mockFetch({ [SHOW]: () => json(200, { data: shown(p, p.status === 'pending' || p.status === 'escalated' ? {} : null) }) })
  decide(perms as string[])
  await screen.findByText(p.requested_by.name, { selector: 'dd' })
  expect(!!cancelButton()).toBe(offered)
})

test('cancelling an approved request says its leave days leave attendance, then posts the version and the reason', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'cancelled' }, null) }))
  const onOpenChange = vi.fn()
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'approved', requested_by: theirs }, null) }), 'POST /api/v1/leave/requests/r1/cancel': post })
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'إلغاء الطلب' }))
  const ask = await screen.findByRole('dialog', { name: 'إلغاء طلب الإجازة' })
  expect(ask).toHaveAccessibleDescription('ستُمسح من الحضور أيام الإجازة التي سجّلتها الموافقة على هذا الطلب، عدا الأيام التي غُيّر نوعها بعدها. ويُرفض الإلغاء إذا كان على أحد هذه الأيام ملاحظة أو سلفة، أو وقع في فترة مقفلة.')
  await user.type(within(ask).getByLabelText('سبب الإلغاء (اختياري)'), ' عاد الموظف مبكرًا ')
  await user.click(within(ask).getByRole('button', { name: 'تأكيد الإلغاء' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(bodyOf(post)).toEqual({ version: 3, reason: 'عاد الموظف مبكرًا' })
  expect(toast.success).toHaveBeenCalledWith('أُلغي الطلب.')
})

test('a site manager withdraws their own pending request without a reason', async () => {
  const post = vi.fn(() => json(200, { data: shown({ status: 'cancelled' }, null) }))
  mockFetch({ [SHOW]: () => json(200, { data: shown({ requested_by: mine }) }), 'POST /api/v1/leave/requests/r1/cancel': post })
  decide(LEAD)
  await user.click(await screen.findByRole('button', { name: 'إلغاء الطلب' }))
  const ask = await screen.findByRole('dialog', { name: 'إلغاء طلب الإجازة' })
  expect(ask).toHaveAccessibleDescription('سيُسحب الطلب دون أن يُسجَّل شيء في الحضور.')
  await user.click(within(ask).getByRole('button', { name: 'تأكيد الإلغاء' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
  expect(bodyOf(post)).toEqual({ version: 3 })
})

test('a cancel refused for days holding a note or an advance shows the reason and lists those dates', async () => {
  mockFetch({
    [SHOW]: () => json(200, { data: shown({ status: 'approved', requested_by: theirs }, null) }),
    'POST /api/v1/leave/requests/r1/cancel': () => json(409, { error: { code: 'leave_cancel_blocked', message: 'عدد أيام الإجازة التي تحمل ملاحظة أو سلفة: 2. عدّلها في الحضور أولًا.', dates: ['2026-11-11', '2026-11-12'] } }),
  })
  const onOpenChange = vi.fn()
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'إلغاء الطلب' }))
  const ask = await screen.findByRole('dialog', { name: 'إلغاء طلب الإجازة' })
  await user.click(within(ask).getByRole('button', { name: 'تأكيد الإلغاء' }))
  const outer = screen.getByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(outer).findByText('عدد أيام الإجازة التي تحمل ملاحظة أو سلفة: 2. عدّلها في الحضور أولًا.')).toBeInTheDocument()
  expect(within(outer).getByText('2026-11-11')).toHaveAttribute('dir', 'ltr')
  expect(within(outer).getByText('2026-11-12')).toBeInTheDocument()
  expect(onOpenChange).not.toHaveBeenCalled()
})

test('a cancelled request shows who cancelled it and why', async () => {
  mockFetch({ [SHOW]: () => json(200, { data: shown({ status: 'cancelled', decision_reason: 'عاد الموظف مبكرًا', decided_by: { id: 'u1', name: 'منى' }, decided_at: '2026-11-05T08:00:00Z' }, null) }) })
  decide(HR)
  await screen.findByText('كريم حسن')
  expect(term('الحالة')).toHaveTextContent('ملغى')
  expect(term('ألغاه')).toHaveTextContent('منى')
  expect(term('تاريخ الإلغاء')).toBeInTheDocument()
  expect(screen.queryByText('صاحب القرار')).toBeNull()
  expect(screen.queryByText('تاريخ القرار')).toBeNull()
  expect(term('سبب الإلغاء')).toHaveTextContent('عاد الموظف مبكرًا')
})

test.each([
  ['a locked period', 423, 'period_locked', 'فترة الحضور مقفلة ولا يمكن تعديلها.'],
  ['a stale version', 409, 'concurrency_conflict', 'عُدِّل هذا السجل في أثناء ذلك.'],
])('a cancel refused for %s shows the reason, keeps the request open, and reloads it', async (_, status, code, message) => {
  let version = 3
  mockFetch({
    [SHOW]: () => json(200, { data: shown({ status: 'approved', requested_by: theirs, version }, null) }),
    'POST /api/v1/leave/requests/r1/cancel': () => {
      version = 4
      return json(status, { error: { code, message } })
    },
  })
  const onOpenChange = vi.fn()
  decide(HR, onOpenChange)
  await user.click(await screen.findByRole('button', { name: 'إلغاء الطلب' }))
  await user.click(within(await screen.findByRole('dialog', { name: 'إلغاء طلب الإجازة' })).getByRole('button', { name: 'تأكيد الإلغاء' }))
  const outer = screen.getByRole('dialog', { name: 'تفاصيل طلب الإجازة' })
  expect(await within(outer).findByText(message)).toBeInTheDocument()
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.filter(([u]) => String(u).endsWith('/leave/requests/r1')).length).toBeGreaterThan(1))
  expect(onOpenChange).not.toHaveBeenCalled()
  expect(within(outer).getByRole('button', { name: 'إلغاء الطلب' })).toBeEnabled()
})
