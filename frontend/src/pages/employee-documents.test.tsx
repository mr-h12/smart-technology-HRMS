import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { Documents, UploadDialog } from './employee-documents'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  delete (navigator as { pdfViewerEnabled?: boolean }).pdfViewerEnabled // own property set by view(); jsdom has none
})

const perms = ['employee_document.view.all', 'employee_document.edit.all']
const POST = 'POST /api/v1/employees/e1/documents'
const created = () => json(201, { data: { id: 'd1', kind: 'work_stub' } })
const body = (f: ReturnType<typeof vi.fn>, i = 0) => (f.mock.calls[i] as [string, RequestInit])[1].body as FormData
const pdf = (bytes: BlobPart = '%PDF', name = 'عقد.pdf') => new File([bytes], name, { type: 'application/pdf' })
const TOO_LARGE = /حجم الملف [\d.]+ ميجابايت والحد الأقصى 10 ميجابايت\./

function open(preset: Parameters<typeof UploadDialog>[0]['preset'] = {}, user = userEvent.setup()) {
  const onClose = vi.fn()
  renderWithAuth(<UploadDialog empId="e1" preset={preset} onClose={onClose} />, perms, { router: false })
  return { user, onClose }
}

test('upload posts the file, kind, expiry and note as multipart', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user, onClose } = open()
  expect(screen.getByLabelText('المستند')).toHaveDisplayValue('اختر نوع المستند')
  await user.upload(screen.getByLabelText('الملف'), pdf())
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled() // no kind yet: nothing is sent only to be refused
  await user.selectOptions(screen.getByLabelText('المستند'), 'work_stub')
  await user.type(screen.getByLabelText('تاريخ الانتهاء'), '2027-01-31')
  await user.type(screen.getByLabelText('ملاحظات'), 'نسخة موقعة')
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  const sent = body(post)
  expect([sent.get('kind'), sent.get('expires_at'), sent.get('note'), sent.has('replaces')]).toEqual(['work_stub', '2027-01-31', 'نسخة موقعة', false])
  expect((sent.get('file') as File).name).toBe('عقد.pdf')
})

test('replace prefills kind, expiry and note and sends replaces', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user, onClose } = open({ replaces: { id: 'd0', kind: 'criminal_record', expires_at: '2026-12-01', note: 'قديم' } })
  expect(screen.getByLabelText('المستند')).toHaveValue('criminal_record')
  expect(screen.getByLabelText('تاريخ الانتهاء')).toHaveValue('2026-12-01')
  expect(screen.getByLabelText('ملاحظات')).toHaveValue('قديم')
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect([body(post).get('replaces'), body(post).get('kind')]).toEqual(['d0', 'criminal_record'])
})

test('a file over 10 MB is refused before sending', async () => {
  mockFetch({})
  const { user } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf(new Uint8Array(11 * 1024 * 1024), 'scan.pdf'))
  expect(await screen.findByText(TOO_LARGE)).toHaveTextContent('امسحه ضوئيًا بجودة أقل أو قسّمه.')
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled()
  expect(fetch).not.toHaveBeenCalled()
})

test('HEIC and other types are refused', async () => {
  mockFetch({})
  const { user } = open({ kind: 'photos' }, userEvent.setup({ applyAccept: false }))
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'photo.heic', { type: 'image/heic' }))
  expect(await screen.findByText('هذه الصورة بصيغة HEIC. احفظها بصيغة JPG أو PDF ثم أعد المحاولة.')).toBeInTheDocument()
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'live', { type: 'image/heif-sequence' }))
  expect(await screen.findByText('هذه الصورة بصيغة HEIC. احفظها بصيغة JPG أو PDF ثم أعد المحاولة.')).toBeInTheDocument()
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'notes.docx', { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }))
  expect(await screen.findByText('يُقبل فقط ملف PDF أو JPG أو PNG أو WEBP.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled()
  expect(fetch).not.toHaveBeenCalled()
})

// jsdom has no object URLs; the image preview needs one
const stubUrls = () => vi.stubGlobal('URL', class extends URL { static createObjectURL = () => 'blob:preview'; static revokeObjectURL = () => {} })
const sentFile = async (post: ReturnType<typeof vi.fn>) => {
  await vi.waitFor(() => expect(post).toHaveBeenCalled())
  return body(post).get('file') as File
}

test('a file the browser gives no type gets one from its extension', async () => {
  stubUrls()
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user } = open({ kind: 'photos' }, userEvent.setup({ applyAccept: false }))
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'notes.docx', { type: '' }))
  expect(await screen.findByText('يُقبل فقط ملف PDF أو JPG أو PNG أو WEBP.')).toBeInTheDocument()
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'ID.WEBP', { type: '' }))
  await user.click(await screen.findByRole('button', { name: 'رفع مستند' }))
  const sent = await sentFile(post)
  expect([sent.name, sent.type]).toEqual(['ID.WEBP', 'image/webp'])
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'scan.JPEG', { type: '' }))
  await user.click(await screen.findByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2))
  expect((body(post, 1).get('file') as File).type).toBe('image/jpeg')
})

/** createImageBitmap and the canvas, which jsdom lacks: a 4000×3000 photo that encodes to a 400 KB JPEG. */
function stubShrink(bitmap = () => Promise.resolve({ width: 4000, height: 3000, close() {} })) {
  stubUrls()
  const decode = vi.fn(bitmap)
  vi.stubGlobal('createImageBitmap', decode)
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ fillRect() {}, drawImage() {} } as never)
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((done) => done(new Blob([new Uint8Array(400_000)], { type: 'image/jpeg' })))
  return decode
}

test('a big photo is shrunk to a JPEG before the size check', async () => {
  stubShrink()
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user } = open({ kind: 'photos' })
  await user.upload(screen.getByLabelText('الملف'), new File([new Uint8Array(11 * 1024 * 1024)], 'IMG_1.jpeg', { type: 'image/jpeg' }))
  await user.click(await screen.findByRole('button', { name: 'رفع مستند' }))
  const sent = await sentFile(post)
  expect([sent.name, sent.type, sent.size]).toEqual(['IMG_1.jpg', 'image/jpeg', 400_000])
})

test('a photo the browser cannot shrink is sent unchanged', async () => {
  stubShrink(() => Promise.reject(new Error('decode')))
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user } = open({ kind: 'photos' })
  const png = new File([new Uint8Array(1.6 * 1024 * 1024)], 'scan.png', { type: 'image/png' })
  await user.upload(screen.getByLabelText('الملف'), png)
  await user.click(await screen.findByRole('button', { name: 'رفع مستند' }))
  const sent = await sentFile(post)
  expect([sent.name, sent.type, sent.size]).toEqual(['scan.png', 'image/png', png.size])
})

test('a photo too big to decode safely is refused without shrinking', async () => {
  const decode = stubShrink()
  mockFetch({})
  const { user } = open({ kind: 'photos' })
  await user.upload(screen.getByLabelText('الملف'), new File([new Uint8Array(41 * 1024 * 1024)], 'huge.jpg', { type: 'image/jpeg' }))
  expect(await screen.findByText(TOO_LARGE)).toBeInTheDocument()
  expect(decode).not.toHaveBeenCalled()
})

test('a 413 answer says the server refused the size and clears the file', async () => {
  mockFetch({ [POST]: () => new Response('<html>', { status: 413 }) })
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByText('الملف أكبر من الحد الذي يقبله الخادم.')).toBeInTheDocument()
  expect(screen.queryByText(TOO_LARGE)).toBeNull()
  expect(screen.getByText('لم يُختر ملف')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled()
  expect(onClose).not.toHaveBeenCalled()
})

test('a network failure offers Retry', async () => {
  const post = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(created())
  vi.stubGlobal('fetch', post)
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByText('تعذّر الاتصال.')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect(post).toHaveBeenCalledTimes(2)
})

test('a server error offers Retry like a network failure', async () => {
  const post = vi.fn().mockReturnValueOnce(new Response('<html>', { status: 503, statusText: 'Service Unavailable' })).mockResolvedValueOnce(created())
  vi.stubGlobal('fetch', post)
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByText('تعذّر الاتصال.')).toBeInTheDocument()
  expect(screen.queryByText('Service Unavailable')).toBeNull()
  await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
})

test('a replace whose Retry answers not found had landed the first time, so the dialog closes', async () => {
  const post = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(json(404, { error: { code: 'not_found', message: 'غير موجود' } }))
  vi.stubGlobal('fetch', post)
  const { user, onClose } = open({ replaces: { id: 'd0', kind: 'criminal_record', expires_at: null } })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  await user.click(await screen.findByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect(screen.queryByText('غير موجود')).toBeNull()
})

const pending = () => {
  const call: { answer: (r: Response) => void } = { answer: () => {} }
  vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((done) => (call.answer = done))))
  return call
}

test('the upload dialog cannot be closed while it uploads', async () => {
  const call = pending()
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByRole('button', { name: 'جارٍ الرفع…' })).toBeDisabled()
  await user.keyboard('{Escape}')
  await user.click(screen.getByRole('button', { name: 'إغلاق' }))
  expect(onClose).not.toHaveBeenCalled()
  call.answer(created())
  await vi.waitFor(() => expect(onClose).toHaveBeenCalledTimes(1))
})

const VIEW = ['employee_document.view.all']
const doc = (kind: string, expires_at: string | null = null) => ({ id: `d-${kind}-${expires_at}`, kind, expires_at, original_name: 'scan.pdf', mime: 'application/pdf', size_bytes: 250_000, note: null, created_at: '2026-11-01T08:00:00Z', uploader_name: 'HR' })
const missingLine = (_: string, el: Element | null) => el?.tagName === 'P' && !!el.textContent?.startsWith('لم يُرفع بعد:')
const showDocs = (documents: object[], permissions = perms) => renderWithAuth(<Documents emp={{ id: 'e1', documents }} />, permissions, { router: false })

test('an empty list shows one sentence and one Upload button', () => {
  const { unmount } = showDocs([])
  expect(screen.getByText('لم يُرفع أي مستند بعد.')).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'رفع مستند' })).toHaveLength(1)
  unmount()
  showDocs([], VIEW)
  expect(screen.getByText('لم يُرفع أي مستند بعد.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'رفع مستند' })).toBeNull()
})

test('the header counts documents and the missing line lists the other kinds', async () => {
  const user = userEvent.setup()
  const { unmount } = showDocs([doc('national_id_copy')])
  expect(screen.getByRole('heading', { name: 'المستندات · 1' })).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'رفع مستند' })).toHaveLength(1)
  const line = screen.getByText(missingLine)
  expect(within(line).getAllByRole('button').map((b) => b.textContent)).toEqual(['عقد عمل', 'صحيفة الحالة الجنائية', 'المؤهل الدراسي', 'شهادة الميلاد', 'استمارة رقم 6 (التأمينات الاجتماعية)', 'صور شخصية', 'الموقف من التجنيد'])
  // «و» never wraps alone: it shares an unbreakable span with the kind after it
  expect(within(line).getByRole('button', { name: 'شهادة الميلاد' }).parentElement).toHaveTextContent(/^وشهادة الميلاد$/)
  await user.click(within(line).getByRole('button', { name: 'صحيفة الحالة الجنائية' }))
  expect(await screen.findByLabelText('المستند')).toHaveValue('criminal_record')
  unmount()
  showDocs([doc('national_id_copy')], VIEW)
  expect(screen.queryByText(missingLine)).toBeNull()
  expect(screen.queryByRole('button', { name: 'رفع مستند' })).toBeNull()
})

test('expiry badges', () => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-03T10:00:00Z'))
  try {
    const badge = (expires: string) => {
      const { unmount } = showDocs([doc('work_stub', expires)])
      const text = screen.getByRole('row', { name: /عقد عمل/ }).textContent
      unmount()
      return text
    }
    expect(badge('2026-11-02')).toContain('منتهي الصلاحية')
    expect(badge('2026-11-03')).toContain('ينتهي اليوم')
    expect(badge('2026-12-03')).toContain('ينتهي خلال 30 يومًا')
    expect(badge('2026-12-04')).not.toMatch(/ينتهي|منتهي/)
  } finally {
    vi.useRealTimers()
  }
})

test('a row the server sent without its file is plain text, whatever the permission says', () => {
  const { unmount } = showDocs([{ id: 'd1', kind: 'work_stub', expires_at: null }], [])
  expect(screen.getByText('عقد عمل')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'عقد عمل' })).toBeNull()
  expect(screen.queryByText('scan.pdf')).toBeNull()
  unmount()
  // view.own: the server sends file fields only for employees in reach today
  showDocs([doc('work_stub'), { id: 'd2', kind: 'photos', expires_at: null }], VIEW)
  expect(screen.getByRole('button', { name: 'عقد عمل' })).toBeInTheDocument()
  expect(screen.getByText('scan.pdf')).toBeInTheDocument()
  expect(screen.getByText('صور شخصية')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'صور شخصية' })).toBeNull()
  expect(screen.getAllByText(/بايت/)).toHaveLength(1)
})

test('sizes read in KB under 1 MB and in MB above, one decimal at most', () => {
  showDocs([doc('work_stub'), { ...doc('photos'), size_bytes: 12.44 * 1024 * 1024 }, { ...doc('other'), size_bytes: 30 * 1024 }])
  expect(screen.getByText('244.1 كيلوبايت')).toBeInTheDocument()
  expect(screen.getByText('12.4 ميجابايت')).toBeInTheDocument()
  expect(screen.getByText('30 كيلوبايت')).toBeInTheDocument()
})

const DOC_PATH = '/api/v1/employees/e1/documents/d1'
const d1 = { ...doc('criminal_record', '2027-01-31'), id: 'd1', note: 'قديم' }
async function rowAction(item: string, user = userEvent.setup()) {
  showDocs([d1])
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: item }))
  return user
}

test('edit details patches kind, expiry and note', async () => {
  const patch = vi.fn(() => json(200, { data: d1 }))
  mockFetch({ [`PATCH ${DOC_PATH}`]: patch })
  const user = await rowAction('تعديل البيانات')
  const dialog = await screen.findByRole('dialog', { name: 'تعديل البيانات' })
  expect(dialog).toHaveAccessibleDescription('صحيفة الحالة الجنائية')
  expect(within(dialog).getByLabelText('المستند')).toHaveValue('criminal_record')
  await user.clear(within(dialog).getByLabelText('تاريخ الانتهاء'))
  await user.clear(within(dialog).getByLabelText('ملاحظات'))
  await user.type(within(dialog).getByLabelText('ملاحظات'), 'نسخة جديدة')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await vi.waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ kind: 'criminal_record', expires_at: null, note: 'نسخة جديدة' })
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('delete asks first and sends DELETE', async () => {
  const del = vi.fn(() => new Response(null, { status: 204 }))
  mockFetch({ [`DELETE ${DOC_PATH}`]: del })
  const user = await rowAction('حذف')
  const dialog = await screen.findByRole('dialog', { name: 'حذف صحيفة الحالة الجنائية؟' })
  expect(within(dialog).getByText('scan.pdf').tagName).toBe('BDI')
  expect(dialog).toHaveTextContent('سيختفي من القائمة ويبقى أثره في سجل التدقيق.')
  expect(del).not.toHaveBeenCalled()
  expect(within(dialog).getByRole('button', { name: 'حذف' })).toHaveClass('bg-destructive')
  await user.click(within(dialog).getByRole('button', { name: 'حذف' }))
  await vi.waitFor(() => expect(del).toHaveBeenCalledTimes(1))
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('a save that finishes after its dialog closed leaves the next dialog open', async () => {
  const call = pending()
  const user = await rowAction('تعديل البيانات')
  const edit = await screen.findByRole('dialog', { name: 'تعديل البيانات' })
  await user.click(within(edit).getByRole('button', { name: 'حفظ' }))
  await user.keyboard('{Escape}')
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'حذف' }))
  const del = await screen.findByRole('dialog')
  call.answer(json(200, { data: d1 }))
  await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
  await new Promise((r) => setTimeout(r, 50))
  expect(del).toBeInTheDocument()
})

test('replace sends replaces with the prefilled kind', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const user = await rowAction('استبدال الملف')
  const dialog = await screen.findByRole('dialog', { name: 'استبدال الملف' })
  expect(within(dialog).getByLabelText('المستند')).toHaveValue('criminal_record')
  await user.upload(within(dialog).getByLabelText('الملف'), pdf())
  await user.click(within(dialog).getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(post).toHaveBeenCalled())
  expect([body(post).get('replaces'), body(post).get('kind')]).toEqual(['d1', 'criminal_record'])
})

test('no row actions without employee_document.edit', () => {
  showDocs([d1], VIEW)
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
})

test('edit details without the view permission leaves the unseen note out', async () => {
  const patch = vi.fn(() => json(200, { data: d1 }))
  mockFetch({ [`PATCH ${DOC_PATH}`]: patch })
  const user = userEvent.setup()
  showDocs([{ id: 'd1', kind: 'work_stub', expires_at: null }], ['employee_document.edit.all'])
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل البيانات' }))
  const dialog = await screen.findByRole('dialog', { name: 'تعديل البيانات' })
  expect(within(dialog).queryByLabelText('ملاحظات')).toBeNull()
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await vi.waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ kind: 'work_stub', expires_at: null })
})

const FILE = `GET ${DOC_PATH}/file`
const html = () => new Response('x', { headers: { 'Content-Type': 'text/html' } })
async function view(over: object = {}, file: () => Response = html, pdfViewer = true) {
  Object.defineProperty(navigator, 'pdfViewerEnabled', { value: pdfViewer, configurable: true })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  const urls = { create: vi.fn(() => 'blob:doc-1'), revoke: vi.fn() }
  vi.stubGlobal('URL', class extends URL { static createObjectURL = urls.create; static revokeObjectURL = urls.revoke })
  const get = vi.fn(file)
  mockFetch({ [FILE]: get })
  const user = userEvent.setup()
  showDocs([{ ...d1, ...over }], VIEW)
  await user.click(screen.getByRole('button', { name: 'صحيفة الحالة الجنائية' }))
  const dialog = await screen.findByRole('dialog', { name: 'صحيفة الحالة الجنائية' })
  const blobType = () => (urls.create.mock.calls[0] as unknown as [Blob])[0].type
  return { user, dialog, urls, get, blobType }
}

test('an archived employee’s documents open but offer no upload, edit or delete', async () => {
  vi.stubGlobal('URL', class extends URL { static createObjectURL = () => 'blob:doc-1'; static revokeObjectURL = () => {} })
  mockFetch({ [FILE]: html })
  const user = userEvent.setup()
  renderWithAuth(<Documents emp={{ id: 'e1', documents: [d1] }} readOnly />, perms, { router: false })
  expect(screen.queryByRole('button', { name: 'رفع مستند' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
  expect(screen.queryByText(missingLine)).toBeNull()
  await user.click(screen.getByRole('button', { name: 'صحيفة الحالة الجنائية' }))
  const dialog = await screen.findByRole('dialog', { name: 'صحيفة الحالة الجنائية' })
  expect(await within(dialog).findByRole('link', { name: 'تنزيل' })).toHaveAttribute('href', 'blob:doc-1')
})

test('an image opens as an img built from the stored mime', async () => {
  const { dialog, blobType } = await view({ mime: 'image/png', original_name: 'id.png' })
  const img = await within(dialog).findByRole('img')
  expect(img).toHaveAttribute('src', 'blob:doc-1')
  expect(img).toHaveAttribute('alt', 'صحيفة الحالة الجنائية: id.png')
  expect(blobType()).toBe('image/png')
})

test('a PDF opens in an iframe when the browser shows PDFs', async () => {
  const { dialog, blobType } = await view()
  const frame = await vi.waitFor(() => dialog.querySelector('iframe')!)
  expect(frame).toHaveAttribute('title', 'صحيفة الحالة الجنائية')
  expect(frame).toHaveAttribute('src', 'blob:doc-1')
  expect(blobType()).toBe('application/pdf')
  expect(within(dialog).queryByRole('img')).toBeNull()
  // iOS may draw only page 1 inside the iframe: the footer also opens the whole file
  const open = within(dialog).getByRole('link', { name: 'فتح' })
  expect(open).toHaveAttribute('href', 'blob:doc-1')
  expect(open).toHaveAttribute('target', '_blank')
  expect(open).toHaveAttribute('rel', 'noopener')
})

test('a PDF without an in-page viewer gets an Open link', async () => {
  const { dialog } = await view({}, html, false)
  expect(await within(dialog).findByRole('link', { name: 'فتح' })).toHaveAttribute('href', 'blob:doc-1')
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('an unknown stored mime never renders inline', async () => {
  const { dialog, blobType } = await view({ mime: 'text/html' })
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  expect(within(dialog).queryByRole('link', { name: 'فتح' })).toBeNull() // Open is for PDFs only
  expect(blobType()).toBe('application/octet-stream')
  expect(within(dialog).queryByRole('img')).toBeNull()
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('a stored mime named like an object key is not allow-listed', async () => {
  const { dialog, blobType } = await view({ mime: 'constructor' })
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  expect(blobType()).toBe('application/octet-stream')
  expect(within(dialog).queryByRole('img')).toBeNull()
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('the download name takes its extension from the stored mime', async () => {
  const { dialog } = await view({ original_name: 'x.html' })
  expect(await within(dialog).findByRole('link', { name: 'تنزيل' })).toHaveAttribute('download', 'x.pdf')
})

test('a name with no base downloads as document', async () => {
  const { dialog } = await view({ original_name: '.pdf', mime: 'image/jpeg' })
  expect(await within(dialog).findByRole('link', { name: 'تنزيل' })).toHaveAttribute('download', 'document.jpg')
})

test('closing revokes the URL', async () => {
  const { user, dialog, urls } = await view()
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  await user.click(within(dialog).getByRole('button', { name: 'إغلاق' }))
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(urls.revoke).toHaveBeenCalledWith('blob:doc-1')
})

test('a failed fetch shows Retry', async () => {
  const { user, dialog, get } = await view({}, () => json(500, { error: { code: 'x', message: 'x' } }))
  expect(await within(dialog).findByText('تعذّر تحميل الملف.')).toBeInTheDocument()
  get.mockImplementation(html)
  await user.click(within(dialog).getByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(dialog.querySelector('iframe')).not.toBeNull())
  expect(get).toHaveBeenCalledTimes(2)
})

test('the viewer says who uploaded the file and when', async () => {
  const { dialog } = await view()
  expect(dialog).toHaveTextContent(/scan\.pdf · رُفع في .*2026.* بواسطة HR/)
  expect(within(dialog).getByText('HR').tagName).toBe('BDI') // a Latin name ending in a digit must not reorder the Arabic sentence
})
