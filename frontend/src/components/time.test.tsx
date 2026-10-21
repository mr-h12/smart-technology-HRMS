import { act, fireEvent, render, renderHook, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { type Errs, formatTime, TextField, useCairoTime, useForm } from '@/components/form'

const user = userEvent.setup()

beforeEach(() => i18n.changeLanguage('en'))
afterEach(() => {
  vi.restoreAllMocks()
  return i18n.changeLanguage('ar')
})

test.each([
  ['00:00', '12:00 AM', '12:00 ص'],
  ['00:30', '12:30 AM', '12:30 ص'],
  ['09:00', '9:00 AM', '9:00 ص'],
  ['12:00', '12:00 PM', '12:00 م'],
  ['13:05', '1:05 PM', '1:05 م'],
  ['17:30:00', '5:30 PM', '5:30 م'],
])('formatTime(%s) is 12-hour', (v, en, ar) => {
  expect(formatTime(v, 'en')).toBe(en)
  expect(formatTime(v, 'ar')).toBe(ar)
})

test('formatTime shows a dash when there is no time', () => {
  expect(formatTime(null, 'en')).toBe('—')
  expect(formatTime('', 'ar')).toBe('—')
})

test('formatTime builds one formatter per language, not one per call', () => {
  const spy = vi.spyOn(Intl, 'DateTimeFormat')
  for (const v of ['08:00', '09:15', '17:45']) formatTime(v, 'en-GB')
  formatTime('10:00', 'fr')
  expect(spy).toHaveBeenCalledTimes(2)
})

test('useCairoTime shows 12-hour in English', () => {
  const { result } = renderHook(() => useCairoTime())
  expect(result.current('2026-10-16T22:30:00Z')).toBe('10/17/26, 1:30:00 AM')
  expect(result.current('2026-10-17T13:05:00Z')).toBe('10/17/26, 4:05:00 PM')
})

/** A time field in a form; the form's value is shown in `out` so a test reads what would be sent. */
function Form({ init = '', errs = {} }: { init?: string; errs?: Errs }) {
  const { form, set, setForm } = useForm({ t: init })
  return (
    <>
      <TextField name="t" label="Start" type="time" values={form} set={set} errs={errs} />
      <output data-testid="out">{form.t}</output>
      <button type="button" onClick={() => setForm({ t: '08:15' })}>reload</button>
      <button type="button">elsewhere</button>
    </>
  )
}
const box = () => screen.getByLabelText('Start')
const out = () => screen.getByTestId('out').textContent
const pm = () => screen.getByRole('button', { name: 'PM' })
const am = () => screen.getByRole('button', { name: 'AM' })

test('a stored 24-hour time shows as 12-hour with its AM/PM choice', () => {
  render(<Form init="17:30" />)
  expect(box()).toHaveValue('5:30')
  expect(pm()).toHaveAttribute('aria-pressed', 'true')
  expect(am()).toHaveAttribute('aria-pressed', 'false')
  expect(box()).toHaveAccessibleDescription('PM')
  expect(out()).toBe('17:30')
})

test('an empty time has no AM/PM chosen', () => {
  render(<Form />)
  expect(am()).toHaveAttribute('aria-pressed', 'false')
  expect(pm()).toHaveAttribute('aria-pressed', 'false')
  expect(box()).toHaveAccessibleDescription('No AM or PM chosen')
})

test('a 12-hour time typed with no AM/PM is not guessed: it asks for one and goes out as typed until chosen', async () => {
  render(<Form />)
  await user.type(box(), '3:30')
  expect(out()).toBe('3:30') // as typed: the server rejects it, nothing is stored as 03:30
  expect(screen.getByText('Choose AM or PM.')).toBeVisible()
  expect(box()).toHaveAttribute('aria-invalid', 'true')
  expect(box()).toHaveAccessibleDescription('Choose AM or PM.')
  await user.click(pm())
  expect(out()).toBe('15:30')
  expect(screen.queryByText('Choose AM or PM.')).toBeNull()
  expect(box()).not.toHaveAttribute('aria-invalid')
  expect(box()).toHaveAccessibleDescription('PM')
})

test('after an AM/PM click the box has the shared focus ring, not the warning one', async () => {
  render(<Form />)
  await user.click(pm()) // the click puts the cursor in the box
  expect(box()).toHaveFocus()
  expect(box()).toHaveClass('focus-visible:outline-ring')
  expect(box()).not.toHaveClass('focus-visible:outline-destructive')
})

test('a box that needs AM/PM has the warning ring', async () => {
  render(<Form />)
  await user.type(box(), '3:30')
  expect(box()).toHaveClass('focus-visible:outline-destructive')
  expect(box()).not.toHaveClass('focus-visible:outline-ring')
})

test('choosing AM/PM while the box is not focused puts the cursor in the box', async () => {
  render(<Form />)
  await user.click(pm())
  expect(box()).toHaveFocus()
  expect(pm()).toHaveAttribute('aria-pressed', 'true')
  expect(out()).toBe('')
})

test('typing a time then choosing AM or PM gives the 24-hour value', async () => {
  render(<Form />)
  await user.type(box(), '9:30')
  await user.click(pm())
  expect(out()).toBe('21:30')
  expect(box()).toHaveFocus() // choosing AM/PM leaves the cursor in the box
  await user.click(am())
  expect(out()).toBe('09:30')
})

test.each([
  ['12:00', 'AM', '00:00'],
  ['12:00', 'PM', '12:00'],
  ['12:30', 'AM', '00:30'],
  ['1230', 'PM', '12:30'],
  ['930', 'AM', '09:30'],
  ['930', 'PM', '21:30'],
  ['7', 'PM', '19:00'],
  ['٩:٣٠', 'AM', '09:30'],
  // 24-hour typing needs no choice and ignores one: 13–23, 0, or a leading zero
  ['23:59', null, '23:59'],
  ['1730', 'AM', '17:30'],
  ['0030', 'PM', '00:30'],
  ['0', null, '00:00'],
  ['0930', 'PM', '09:30'],
  ['09', 'PM', '09:00'],
  ['09:30', null, '09:30'],
])('"%s" with %s is %s', async (typed, period, want) => {
  render(<Form />)
  if (period) await user.click(screen.getByRole('button', { name: period }))
  await user.type(box(), typed)
  expect(out()).toBe(want)
})

test('a 24-hour entry is taken as typed and shows as 12-hour once the box is left', async () => {
  render(<Form />)
  await user.type(box(), '1730')
  expect(out()).toBe('17:30')
  await user.click(screen.getByRole('button', { name: 'elsewhere' }))
  expect(box()).toHaveValue('5:30')
  expect(pm()).toHaveAttribute('aria-pressed', 'true')
  expect(out()).toBe('17:30')
})

test('clearing the box gives no value and keeps the choice; an unreadable time is sent as typed so the server names the field', async () => {
  render(<Form init="21:00" />)
  await user.clear(box())
  expect(out()).toBe('')
  await user.type(box(), '8')
  expect(out()).toBe('20:00')
  await user.clear(box())
  await user.type(box(), '25:00')
  expect(out()).toBe('25:00')
})

test('a value set from outside (a reload after save) shows in the box', async () => {
  render(<Form init="17:30" />)
  await user.click(screen.getByRole('button', { name: 'reload' }))
  expect(box()).toHaveValue('8:15')
  expect(am()).toHaveAttribute('aria-pressed', 'true')
})

test('the label names the box and a server error is tied to it', () => {
  render(<Form init="09:00" errs={{ t: 'The start must be a time.' }} />)
  expect(box()).toHaveAccessibleDescription('The start must be a time. AM')
  expect(box()).toHaveAttribute('aria-invalid', 'true')
  expect(screen.getByRole('group', { name: 'AM or PM: Start' })).toBeInTheDocument()
})

test('Arabic names the choice ص / م', async () => {
  await act(() => i18n.changeLanguage('ar'))
  render(<Form init="13:00" />)
  expect(screen.getByRole('button', { name: 'مساءً' })).toHaveTextContent('م')
  expect(screen.getByRole('button', { name: 'صباحًا' })).toHaveTextContent('ص')
  fireEvent.change(box(), { target: { value: '2' } })
  expect(out()).toBe('14:00')
})
