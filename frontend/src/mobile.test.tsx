import { act, render, renderHook, screen } from '@testing-library/react'
import { afterEach, expect, test } from 'vitest'
import '@/i18n'
import { selectCls } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input, Textarea } from '@/components/ui/input'
import { Select, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useTheme } from '@/hooks/useTheme'
import indexHtml from '../index.html?raw'
import manifest from '../public/manifest.webmanifest?raw'

// the keys only: the files in public/, which the build copies to the site root
const published = Object.keys(import.meta.glob('../public/*')).map((f) => f.replace('../public', ''))

// iPhone zooms into a field whose font is under 16 px; below sm every field is 16 px, from sm up it keeps today's 14 px.
test('every kit field is 16 px below sm and 14 px from sm up', () => {
  render(
    <>
      <Input aria-label="input" />
      <Textarea aria-label="textarea" />
      <Select><SelectTrigger aria-label="trigger"><SelectValue /></SelectTrigger></Select>
      <select aria-label="native" className={selectCls} />
    </>,
  )
  for (const name of ['input', 'textarea', 'trigger', 'native']) {
    const cls = (screen.getByLabelText(name) as HTMLElement).className.split(' ')
    expect(cls, name).toContain('max-sm:text-base')
    expect(cls, name).toContain('text-sm')
  }
})

test('a caller that shrinks a field still gets 16 px on a phone', () => {
  render(<Input aria-label="small" className="text-xs" />)
  expect(screen.getByLabelText('small').className.split(' ')).toContain('max-sm:text-base')
})

test('buttons, fields and tabs are 44 px tall below sm, whatever the size', () => {
  render(
    <>
      <Button>default</Button>
      <Button size="sm">sm</Button>
      <Button size="xs">xs</Button>
      <Button size="lg">lg</Button>
      <Button size="icon" aria-label="icon" />
      <Button size="icon-sm" aria-label="icon-sm" />
      <Button size="sm" className="h-7">override</Button>
      <Input aria-label="input" />
      <Select><SelectTrigger aria-label="trigger"><SelectValue /></SelectTrigger></Select>
      <select aria-label="native" className={selectCls} />
      <Tabs defaultValue="a"><TabsList><TabsTrigger value="a">tab</TabsTrigger></TabsList></Tabs>
    </>,
  )
  for (const name of ['default', 'sm', 'xs', 'lg', 'override']) expect(screen.getByRole('button', { name }).className, name).toMatch(/(^| )max-sm:h-11( |$)/)
  for (const name of ['icon', 'icon-sm']) expect(screen.getByRole('button', { name }).className, name).toMatch(/(^| )max-sm:size-11( |$)/)
  for (const name of ['input', 'trigger', 'native']) expect(screen.getByLabelText(name).className, name).toMatch(/(^| )max-sm:h-11( |$)/)
  expect(screen.getByRole('tab', { name: 'tab' }).className).toMatch(/(^| )max-sm:h-11( |$)/)
})

test('the manifest is valid and its icons exist', () => {
  const m = JSON.parse(manifest)
  expect(m).toMatchObject({ name: 'سمارت تكنولوجي — الموارد البشرية', short_name: 'HR سمارت', start_url: '/', scope: '/', display: 'standalone', dir: 'rtl', lang: 'ar' })
  expect(m.orientation).toBeUndefined()
  expect(m.theme_color).toMatch(/^#[0-9a-f]{6}$/i)
  expect(m.background_color).toMatch(/^#[0-9a-f]{6}$/i)
  expect(m.icons.length).toBeGreaterThan(0)
  for (const icon of m.icons) expect(published, icon.src).toContain(icon.src)
})

test('index.html links the manifest, allows pinch zoom and asks for a full-screen app', () => {
  const doc = new DOMParser().parseFromString(indexHtml, 'text/html')
  const meta = (name: string) => doc.querySelector(`meta[name="${name}"]`)?.getAttribute('content')
  expect(doc.querySelector('link[rel="manifest"]')?.getAttribute('href')).toBe('/manifest.webmanifest')
  expect(meta('viewport')).toBe('width=device-width, initial-scale=1, viewport-fit=cover')
  expect(meta('apple-mobile-web-app-capable')).toBe('yes')
  expect(meta('mobile-web-app-capable')).toBe('yes')
  expect(meta('apple-mobile-web-app-status-bar-style')).toBe('default')
  expect(meta('apple-mobile-web-app-title')).toBe('HR سمارت')
  const media = [...doc.querySelectorAll('meta[name="theme-color"]')].map((m) => m.getAttribute('media'))
  expect(media).toEqual(['(prefers-color-scheme: light)', '(prefers-color-scheme: dark)'])
})

// The bar colour is the header's (--card), read from the stylesheet, so it never drifts from the tokens.
const tokens = Object.assign(document.createElement('style'), { textContent: ':root { --card: #ffffff; } .dark { --card: #111827; }' })
afterEach(() => {
  tokens.remove()
  document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.remove())
  document.documentElement.classList.remove('dark')
})

test('the browser bar colour follows the in-app theme toggle, whatever the OS asks for', () => {
  document.head.append(tokens)
  for (const media of ['(prefers-color-scheme: light)', '(prefers-color-scheme: dark)']) {
    document.head.append(Object.assign(document.createElement('meta'), { name: 'theme-color', content: '#000000', media }))
  }
  const bars = () => [...document.querySelectorAll<HTMLMetaElement>('meta[name="theme-color"]')].map((m) => m.content)
  const { result } = renderHook(() => useTheme())
  act(() => result.current.setTheme('dark'))
  expect(bars()).toEqual(['#111827', '#111827'])
  act(() => result.current.setTheme('light'))
  expect(bars()).toEqual(['#ffffff', '#ffffff'])
})
