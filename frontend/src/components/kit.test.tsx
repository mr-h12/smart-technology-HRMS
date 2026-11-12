import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Download, Plus, Users } from 'lucide-react'
import { useState } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { expect, test, vi } from 'vitest'
import '@/i18n'
import { DataList, type DataListProps } from '@/components/common/DataList'
import { type HeaderAction, PageHeader } from '@/components/common/PageHeader'
import { RowActions } from '@/components/common/RowActions'
import { StatRow } from '@/components/common/StatRow'
import { StatusBadge } from '@/components/common/StatusBadge'
import { Alert } from '@/components/common/Alert'
import { ErrorBanner, intlLocale } from '@/components/form'
import { Pagination } from '@/components/tables/Pagination'
import { ActionBar } from '@/components/layout/ActionBar'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ApiError } from '@/lib/api'

const user = userEvent.setup()

test('Card, StatusBadge and Tabs render and switch panels', async () => {
  render(
    <Card>
      <CardHeader><CardTitle>عنوان</CardTitle></CardHeader>
      <CardContent>
        <StatusBadge variant="success" label="نشط" />
        <Tabs defaultValue="a">
          <TabsList>
            <TabsTrigger value="a">أ</TabsTrigger>
            <TabsTrigger value="b">ب</TabsTrigger>
          </TabsList>
          <TabsContent value="a">لوحة أ</TabsContent>
          <TabsContent value="b">لوحة ب</TabsContent>
        </Tabs>
      </CardContent>
    </Card>,
  )
  expect(screen.getByText('عنوان')).toBeInTheDocument()
  expect(screen.getByText('نشط')).toBeInTheDocument()
  expect(screen.getByText('لوحة أ')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'ب' }))
  expect(screen.getByText('لوحة ب')).toBeInTheDocument()
  expect(screen.queryByText('لوحة أ')).toBeNull()
})

test('Pagination shows the range in Arabic and pages forward', async () => {
  const onPageChange = vi.fn()
  render(<Pagination page={1} pageSize={12} total={30} onPageChange={onPageChange} />)
  expect(screen.getByText('عرض 1–12 من 30')).toBeInTheDocument()
  expect(screen.getByText('صفحة 1 من 3')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'السابق' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  expect(onPageChange).toHaveBeenCalledWith(2)
})

test('RowActions opens its menu and runs the chosen item', async () => {
  const onSelect = vi.fn()
  render(<RowActions items={[{ label: 'تعديل', onSelect }]} />)
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  expect(onSelect).toHaveBeenCalledOnce()
})

test('Arabic numbers and dates use Latin digits', () => {
  expect(intlLocale('ar')).toBe('ar-EG-u-nu-latn')
  expect(intlLocale('en')).toBe('en')
  expect(new Intl.NumberFormat(intlLocale('ar'), { minimumFractionDigits: 2 }).format(7.5)).toBe('7.50')
})

test('Alert announces danger and success but leaves a static note silent', () => {
  const { rerender } = render(<Alert tone="danger">تعذّر الحفظ</Alert>)
  expect(screen.getByRole('alert')).toHaveTextContent('تعذّر الحفظ')
  rerender(<Alert tone="success" title="تم">تم الاستيراد</Alert>)
  expect(screen.getByRole('status')).toHaveTextContent('تمتم الاستيراد')
  for (const tone of ['info', 'neutral', 'warning'] as const) {
    rerender(<Alert tone={tone}>ملاحظة</Alert>)
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.queryByRole('status')).toBeNull()
    expect(screen.getByText('ملاحظة')).toBeInTheDocument()
  }
})

test('ErrorBanner draws a danger Alert with only the messages no field shows', () => {
  render(<ErrorBanner err={new ApiError('VALIDATION', 'invalid', 422, [{ code: 'required', field: 'name', message: 'الاسم مطلوب' }, { code: 'invalid', field: 'x', message: 'خطأ آخر' }])} shown={['name']} />)
  const alert = screen.getByRole('alert')
  expect(alert).toHaveTextContent('خطأ آخر')
  expect(alert).not.toHaveTextContent('الاسم مطلوب')
  expect(alert).toHaveClass('bg-destructive-bg')
})

test('ErrorBanner lists the dates a refusal names, each left to right', () => {
  render(<ErrorBanner err={new ApiError('leave_cancel_blocked', 'مرفوض', 409, [], ['2026-11-11', '2026-11-12'])} shown={[]} />)
  const alert = screen.getByRole('alert')
  expect(within(alert).getByText('مرفوض')).toBeInTheDocument()
  expect(within(alert).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['2026-11-11', '2026-11-12'])
  expect(within(alert).getByText('2026-11-11')).toHaveAttribute('dir', 'ltr')
})

test('SheetHeader leaves room for the close button and SheetFooter sits at the bottom', () => {
  render(
    <Sheet open>
      <SheetContent>
        <SheetHeader data-testid="head"><SheetTitle>سجل</SheetTitle></SheetHeader>
        <SheetFooter data-testid="foot"><Button>حفظ</Button></SheetFooter>
      </SheetContent>
    </Sheet>,
  )
  expect(screen.getByTestId('head')).toHaveClass('border-b', 'pe-14')
  expect(screen.getByTestId('head')).toHaveTextContent('سجل')
  expect(screen.getByTestId('foot')).toHaveClass('mt-auto', 'border-t')
  expect(within(screen.getByTestId('foot')).getByRole('button', { name: 'حفظ' })).toBeInTheDocument()
})

/** The documented usage (S2a bulk bar): a sibling after the list's Card, so sticky binds to the page scroll. */
function BulkBar() {
  const [times, setTimes] = useState(false)
  return (
    <div className="space-y-4">
      <Card className="overflow-hidden">الصفوف</Card>
      <ActionBar label="إجراءات المحدد">
        <span>2 محدد</span>
        <Button variant="ghost">مسح</Button>
        <Button variant="outline">حاضر</Button>
        <Button variant="outline" onClick={() => setTimes(true)}>تعديل الأوقات</Button>
      </ActionBar>
      <Dialog open={times} onOpenChange={setTimes}>
        <DialogContent aria-describedby={undefined}><DialogTitle>تعديل الأوقات</DialogTitle></DialogContent>
      </Dialog>
    </div>
  )
}

test('ActionBar sticks to the bottom with the safe area and no clipping ancestor in its documented usage', () => {
  render(<BulkBar />)
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveClass('sticky', 'bottom-0', 'pb-[calc(0.75rem+env(safe-area-inset-bottom))]')
  // sticky stops working inside any overflow box between the bar and the page scroll
  for (let el = bar.parentElement; el; el = el.parentElement) expect(el.className).not.toMatch(/\boverflow-(?:hidden|auto|scroll|clip)\b/)
})

test('ActionBar holds a count, Clear, actions and a button that opens a dialog', async () => {
  render(<BulkBar />)
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveTextContent('2 محدد')
  expect(within(bar).getByRole('button', { name: 'مسح' })).toBeInTheDocument()
  await user.click(within(bar).getByRole('button', { name: 'تعديل الأوقات' }))
  expect(await screen.findByRole('dialog', { name: 'تعديل الأوقات' })).toBeInTheDocument()
})

test('PageHeader draws one solid main action after at most two ghost alternatives', async () => {
  const add = vi.fn()
  render(
    <PageHeader
      title="المواقع"
      description="من 26 أكتوبر إلى 25 نوفمبر"
      action={{ label: 'إضافة موقع', icon: Plus, onClick: add }}
      secondary={[{ label: 'تصدير', icon: Download, onClick: () => {} }, { label: 'استيراد', onClick: () => {} }]}
    />,
  )
  expect(screen.getByRole('heading', { level: 1, name: 'المواقع' })).toBeInTheDocument()
  const buttons = screen.getAllByRole('button')
  expect(buttons.map((b) => b.textContent)).toEqual(['تصدير', 'استيراد', 'إضافة موقع'])
  expect(buttons.filter((b) => b.className.includes('bg-primary'))).toEqual([buttons[2]])
  expect(buttons[2]).toHaveClass('max-sm:w-full')
  for (const b of buttons.slice(0, 2)) expect(b.className).not.toMatch(/\bbg-primary\b|\bborder\b/)
  await user.click(buttons[2])
  expect(add).toHaveBeenCalledOnce()
})

// @ts-expect-error a link cannot be disabled, so `disabled` is only accepted with onClick
const linkCannotBeDisabled: HeaderAction = { label: 'x', to: '/', disabled: true }
void linkCannotBeDisabled

test('PageHeader renders a `to` action as a link styled as the solid button', () => {
  render(<MemoryRouter><PageHeader title="الموظفون" action={{ label: 'إضافة موظف', to: '/employees/new' }} /></MemoryRouter>)
  const link = screen.getByRole('link', { name: 'إضافة موظف' })
  expect(link).toHaveAttribute('href', '/employees/new')
  expect(link).toHaveClass('bg-primary')
  expect(screen.queryByRole('button')).toBeNull()
})

test('PageHeader still renders legacy actions until employees.tsx moves', () => {
  render(<PageHeader title="الموظفون" actions={<button type="button">قديم</button>} />)
  expect(screen.getByRole('button', { name: 'قديم' })).toBeInTheDocument()
})

test('StatRow draws exactly one hero figure, untoned, and tones only the secondary numbers', () => {
  render(
    <StatRow
      label="ملخص اليوم"
      hero={{ label: 'الإجمالي', value: 120, detail: 'تم تسجيل 90 من 120', progress: 0.75 }}
      stats={[{ label: 'حاضر', value: 80, tone: 'success' }, { label: 'غائب', value: 10, tone: 'destructive' }, { label: 'متبقي', value: 30 }]}
    />,
  )
  const row = screen.getByRole('group', { name: 'ملخص اليوم' })
  const kpi = row.querySelectorAll('.text-kpi')
  expect(kpi).toHaveLength(1)
  expect(kpi[0]).toHaveTextContent('120')
  expect(kpi[0].className).not.toMatch(/text-(?:success|warning|destructive|info)\b/)
  expect(screen.getByText('80')).toHaveClass('text-lg', 'text-success')
  expect(screen.getByText('30')).toHaveClass('text-lg')
  expect(screen.getByText('تم تسجيل 90 من 120')).toBeInTheDocument()
  expect(row.querySelector('[style*="width: 75%"]')).not.toBeNull()
  expect(screen.queryAllByRole('button')).toHaveLength(0)
  // the visible label names each tile; an aria-label would make it read twice
  for (const tile of within(row).getAllByRole('group')) expect(tile).not.toHaveAttribute('aria-label')
})

test('StatRow tiles put the hero and every stat in one row from lg, the hero two columns wide', () => {
  render(
    <StatRow
      label="ملخص اليوم"
      hero={{ label: 'الإجمالي', value: 120, progress: 0.5 }}
      stats={[{ label: 'حاضر', value: 80 }, { label: 'غائب', value: 10 }, { label: 'إجازة', value: 5 }, { label: 'متبقي', value: 25 }]}
    />,
  )
  const row = screen.getByRole('group', { name: 'ملخص اليوم' })
  expect(row).toHaveClass('grid', 'lg:grid-flow-col', 'lg:auto-cols-fr')
  const hero = within(row).getByText('الإجمالي').closest('[role="group"]')!
  expect(hero).toHaveClass('lg:col-span-2')
  const stats = within(row).getByText('حاضر').closest('[role="group"]')!.parentElement!
  // below lg the stats sit 2-up under the hero; from lg their wrapper drops out so each tile is a column of the row
  expect(stats).toHaveClass('grid-cols-2', 'lg:contents')
  expect(stats.children).toHaveLength(4)
})

test('StatRow strip: one hero with its detail over one hairline-divided strip of every secondary', () => {
  render(
    <StatRow
      layout="strip"
      label="الرصيد"
      hero={{ label: 'المتبقي', value: 11, detail: 'السبب' }}
      stats={[{ label: 'السنوي', value: 21 }, { label: 'المستخدم', value: 10 }, { label: 'مرضي', value: 4 }]}
    />,
  )
  const row = screen.getByRole('group', { name: 'الرصيد' })
  expect(row.querySelectorAll('.text-kpi')).toHaveLength(1)
  expect(row.querySelector('.text-kpi')).toHaveTextContent('11')
  expect(within(row).getByText('السبب')).toBeInTheDocument()
  const strip = within(row).getByText('السنوي').closest('[role="group"]')!.parentElement!
  // one strip for all of them, so no tile sits alone on its row
  expect(strip).toHaveClass('divide-y', 'md:grid-flow-col', 'md:auto-cols-fr')
  expect(within(strip).getAllByRole('group')).toHaveLength(3)
  expect(within(strip).getByText('21')).toHaveClass('whitespace-nowrap')
  for (const tile of within(row).getAllByRole('group')) expect(tile).not.toHaveAttribute('aria-label')
})

test('StatRow compact: every figure shares one row from md, label and number on one line, and tiles stay filter toggles', async () => {
  const pick = vi.fn()
  render(
    <StatRow
      layout="compact"
      label="ملخص"
      hero={{ label: 'الإجمالي', value: 5, detail: '3 من 5', progress: 0.6, select: { pressed: true, onSelect: () => pick('all') } }}
      stats={[{ label: 'حاضر', value: 3, tone: 'success', select: { pressed: false, onSelect: () => pick('present') } }]}
    />,
  )
  const row = screen.getByRole('group', { name: 'ملخص' })
  expect(row).toHaveClass('grid', 'md:grid-flow-col', 'md:auto-cols-fr')
  expect(row.querySelector('.text-kpi')).toBeNull()
  expect(screen.getByText('3 من 5')).toBeInTheDocument()
  expect(row.querySelector('[style*="width: 60%"]')).not.toBeNull()

  const all = screen.getByRole('button', { name: /الإجمالي/ })
  const present = screen.getByRole('button', { name: /حاضر/ })
  expect(all).toHaveAttribute('aria-pressed', 'true')
  expect(all).toHaveClass('border-primary', 'min-h-tap')
  expect(present).toHaveClass('min-h-tap')
  expect(within(present).getByText('3')).toHaveClass('text-success')
  await user.click(present)
  expect(pick).toHaveBeenCalledWith('present')
})

test('StatRow tiles with select are pressed toggles', async () => {
  const pick = vi.fn()
  render(
    <StatRow
      label="ملخص"
      hero={{ label: 'الإجمالي', value: 5, select: { pressed: true, onSelect: () => pick('all') } }}
      stats={[{ label: 'حاضر', value: 3, select: { pressed: false, onSelect: () => pick('present') } }]}
    />,
  )
  expect(screen.getByRole('button', { name: /الإجمالي/ })).toHaveAttribute('aria-pressed', 'true')
  const present = screen.getByRole('button', { name: /حاضر/ })
  expect(present).toHaveAttribute('aria-pressed', 'false')
  await user.click(present)
  expect(pick).toHaveBeenCalledWith('present')
})

type Person = { id: string; name: string; site: string; pay: string; code: string }
const people: Person[] = [
  { id: '1', name: 'أحمد علي', site: 'سموحة', pay: '1,250.00', code: 'E-1' },
  { id: '2', name: 'منى سعيد', site: 'العجمي', pay: '980.50', code: 'E-2' },
]
const list = (over: Partial<DataListProps<Person>> = {}) => (
  <DataList<Person>
    label="الموظفون"
    rows={people}
    rowKey={(p) => p.id}
    title={{ header: 'الاسم', cell: (p) => p.name }}
    fields={[
      { header: 'الموقع', cell: (p) => p.site },
      { header: 'الأجر', cell: (p) => p.pay, numeric: true },
      { header: 'الكود', cell: (p) => p.code, tableFrom: 'xl' },
    ]}
    status={{ header: 'الحالة', cell: () => 'نشط' }}
    actions={(p) => <Button variant="ghost" size="sm">فتح {p.name}</Button>}
    empty={{ icon: Users, title: 'لا يوجد موظفون', action: <Button>إضافة موظف</Button> }}
    {...over}
  />
)

test('DataList is one table that becomes a card per row below 640 px, labelled by its own headers', () => {
  render(list())
  const table = screen.getByRole('table', { name: 'الموظفون' })
  expect(table).toHaveClass('max-sm:block')
  expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['الاسم', 'الموقع', 'الأجر', 'الكود', 'الحالة', 'الإجراءات'])
  expect(within(table).getAllByRole('rowgroup')[0]).toHaveClass('max-sm:sr-only')
  const rows = within(table).getAllByRole('row').slice(1)
  expect(rows).toHaveLength(2)
  expect(rows[0]).toHaveClass('max-sm:grid')
  const cells = within(rows[0]).getAllByRole('cell')
  expect(cells.map((c) => c.textContent)).toEqual(['أحمد علي', 'الموقعسموحة', 'الأجر1,250.00', 'الكودE-1', 'نشط', 'فتح أحمد علي'])
  // the card label is the column header, hidden from the table layout and from screen readers (the thead already names it)
  // a UUID has no break point; it must wrap inside the card instead of being clipped
  expect(within(cells[1]).getByText('سموحة')).toHaveClass('min-w-0', '[overflow-wrap:anywhere]', 'max-sm:text-end')
  const label = within(cells[1]).getByText('الموقع')
  expect(label).toHaveClass('sm:hidden')
  expect(label).toHaveAttribute('aria-hidden', 'true')
})

test('DataList end-aligns numeric columns and hides a tableFrom column only in the narrow table', () => {
  render(list())
  const [head] = screen.getAllByRole('row')
  const cells = within(screen.getAllByRole('row')[1]).getAllByRole('cell')
  expect(within(head).getByText('الأجر')).toHaveClass('text-end')
  expect(cells[2]).toHaveClass('text-end', 'whitespace-nowrap')
  expect(within(head).getByText('الكود')).toHaveClass('sm:max-xl:hidden')
  expect(cells[3]).toHaveClass('sm:max-xl:hidden')
  expect(cells[3]).toHaveTextContent('E-1')
})

test('DataList shows a skeleton while loading, an error instead of the empty state, and the empty state with its one action', () => {
  const { rerender } = render(list({ loading: true }))
  expect(screen.getByLabelText('جارٍ التحميل…')).toHaveAttribute('aria-busy', 'true')
  expect(screen.queryByRole('table')).toBeNull()
  rerender(list({ rows: [], error: new Error('تعذّر التحميل') }))
  expect(screen.getByRole('alert')).toHaveTextContent('تعذّر التحميل')
  expect(screen.queryByText('لا يوجد موظفون')).toBeNull()
  rerender(list({ rows: [] }))
  expect(screen.getByText('لا يوجد موظفون')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'إضافة موظف' })).toBeInTheDocument()
  expect(screen.queryByRole('table')).toBeNull()
})

test('DataList with `to` makes each title a link to its row', async () => {
  render(<MemoryRouter>{list({ to: (p) => `/employees/${p.id}`, actions: undefined })}</MemoryRouter>)
  expect(screen.getByRole('link', { name: 'أحمد علي' })).toHaveAttribute('href', '/employees/1')
  expect(screen.getByRole('link', { name: 'منى سعيد' })).toHaveAttribute('href', '/employees/2')
})

test('DataList with `to` clips each stretched link to its own row and rings the row, since Safari ignores position on a table row', () => {
  const { rerender } = render(<MemoryRouter>{list({ to: (p) => `/employees/${p.id}`, actions: undefined })}</MemoryRouter>)
  for (const row of screen.getAllByRole('row').slice(1)) {
    expect(row).toHaveClass(
      'relative',
      '[clip-path:inset(0)]',
      'has-[td:first-child>a:focus-visible]:outline-2',
      'has-[td:first-child>a:focus-visible]:-outline-offset-2',
      'has-[td:first-child>a:focus-visible]:outline-ring',
    )
    const link = within(row).getByRole('link')
    expect(link).toHaveClass('after:absolute', 'after:inset-0')
    expect(link).not.toHaveClass('focus-visible:after:outline-2')
  }
  rerender(<MemoryRouter>{list({ actions: undefined })}</MemoryRouter>)
  for (const row of screen.getAllByRole('row').slice(1)) {
    expect(row).not.toHaveClass('relative')
    expect(row).not.toHaveClass('[clip-path:inset(0)]')
  }
})

test('DataList hero is the last column under a visible header, end-aligned like the numbers', () => {
  render(list({ actions: undefined, hero: { header: 'الرصيد', cell: (p) => p.pay } }))
  const [head, first] = screen.getAllByRole('row')
  const headers = within(head).getAllByRole('columnheader')
  expect(headers.at(-1)).toHaveTextContent('الرصيد')
  expect(headers.at(-1)).toHaveClass('text-end')
  expect(headers.at(-1)).not.toHaveClass('text-start')
  expect(within(head).getByText('الأجر')).not.toHaveClass('text-start')
  const hero = within(first).getAllByRole('cell').at(-1)!
  expect(hero).toHaveTextContent('الرصيد1,250.00')
  expect(hero).toHaveClass('text-end', 'whitespace-nowrap')
  expect(within(hero).getByText('الرصيد')).toHaveAttribute('aria-hidden', 'true')
})

test('DataList paginates only when there is more than one page', () => {
  const page = { page: 1, pageSize: 2, total: 2, onPageChange: () => {} }
  const { rerender } = render(list({ page }))
  expect(screen.queryByRole('button', { name: 'التالي' })).toBeNull()
  rerender(list({ page: { ...page, total: 3 } }))
  expect(screen.getByRole('button', { name: 'التالي' })).toBeInTheDocument()
})
