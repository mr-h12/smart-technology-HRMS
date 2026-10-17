import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { Tabs, TabsList, TabsTrigger } from './tabs'

// jsdom has no layout: give the strip 0..100 and each tab a box, some off either edge
const boxes: Record<string, [number, number]> = { a: [-80, -20], b: [10, 60], c: [150, 200] }
const rect = (l: number, r: number) => ({ left: l, right: r }) as DOMRect

afterEach(() => vi.restoreAllMocks())

test('the strip scrolls sideways to the selected tab on mount and on every selection change, and nothing else scrolls', async () => {
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
    const box = boxes[this.textContent ?? '']
    return this.getAttribute('role') === 'tab' && box ? rect(...box) : rect(0, 100)
  })
  const scrollBy = vi.spyOn(Element.prototype, 'scrollBy')
  const windowScroll = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

  render(
    <Tabs defaultValue="c" dir="rtl">
      <TabsList>
        {['a', 'b', 'c'].map((v) => <TabsTrigger key={v} value={v}>{v}</TabsTrigger>)}
      </TabsList>
    </Tabs>,
  )
  const list = screen.getByRole('tablist')
  expect(scrollBy.mock.contexts).toEqual([list])
  expect(scrollBy).toHaveBeenLastCalledWith({ left: 100 }) // c sticks out past the right edge
  await userEvent.click(screen.getByRole('tab', { name: 'a' }))
  expect(scrollBy).toHaveBeenLastCalledWith({ left: -80 }) // a sticks out past the left edge
  const before = scrollBy.mock.calls.length
  await userEvent.click(screen.getByRole('tab', { name: 'b' }))
  expect(scrollBy).toHaveBeenCalledTimes(before) // b is already in view
  expect(windowScroll).not.toHaveBeenCalled()
})
