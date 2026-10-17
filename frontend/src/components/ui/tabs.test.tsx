import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import { Tabs, TabsList, TabsTrigger } from './tabs'

const strip = (value: string) => (
  <Tabs defaultValue={value} dir="rtl">
    <TabsList>
      {['a', 'b', 'c'].map((v) => <TabsTrigger key={v} value={v}>{v}</TabsTrigger>)}
    </TabsList>
  </Tabs>
)

test('the selected tab is scrolled into view on mount and whenever the selection changes', async () => {
  const spy = vi.spyOn(Element.prototype, 'scrollIntoView')
  render(strip('c'))
  expect(spy).toHaveBeenCalledTimes(1)
  expect(spy.mock.contexts[0]).toBe(screen.getByRole('tab', { name: 'c' }))
  expect(spy).toHaveBeenCalledWith({ inline: 'nearest', block: 'nearest' })
  await userEvent.click(screen.getByRole('tab', { name: 'a' }))
  expect(spy.mock.contexts.at(-1)).toBe(screen.getByRole('tab', { name: 'a' }))
  spy.mockRestore()
})
