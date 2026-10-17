import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import '@/i18n'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { intlLocale } from '@/components/form'
import { Pagination } from '@/components/tables/Pagination'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'

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
