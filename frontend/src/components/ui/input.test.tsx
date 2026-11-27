import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { expect, test } from 'vitest'
import { asciiDigits } from '@/lib/utils'
import { Input } from './input'

const user = userEvent.setup()

function Box({ label, inputMode }: { label: string; inputMode?: React.ComponentProps<'input'>['inputMode'] }) {
  const [v, setV] = useState('')
  return (
    <>
      <Input aria-label={label} inputMode={inputMode} value={v} onChange={(e) => setV(e.target.value)} />
      <output aria-label={`${label} value`}>{v}</output>
    </>
  )
}

test('asciiDigits reads English, Arabic-Indic and Persian digits and «٫», and keeps text and leading zeros', () => {
  expect(asciiDigits('0123456789')).toBe('0123456789')
  expect(asciiDigits('٠١٢٣٤٥٦٧٨٩')).toBe('0123456789')
  expect(asciiDigits('۰۱۲۳۴۵۶۷۸۹')).toBe('0123456789')
  expect(asciiDigits('٣٥٠٫٥٠')).toBe('350.50')
  expect(asciiDigits('٠٠٢٩٨')).toBe('00298')
  expect(asciiDigits('١٢a')).toBe('12a') // still malformed: its own validation refuses it
})

test('a numeric, decimal or tel field takes Arabic and Persian digits as ASCII', async () => {
  render(<><Box label="phone" inputMode="numeric" /><Box label="wage" inputMode="decimal" /><Box label="tel" inputMode="tel" /></>)
  await user.type(screen.getByRole('textbox', { name: 'phone' }), '٠١٠۱۲۳٤٥٦٧٨')
  await user.type(screen.getByRole('textbox', { name: 'wage' }), '٣٥٠٫٥٠')
  await user.type(screen.getByRole('textbox', { name: 'tel' }), '۰۱۰')
  expect(screen.getByRole('status', { name: 'phone value' })).toHaveTextContent('01012345678')
  expect(screen.getByRole('textbox', { name: 'phone' })).toHaveValue('01012345678')
  expect(screen.getByRole('status', { name: 'wage value' })).toHaveTextContent('350.50')
  expect(screen.getByRole('status', { name: 'tel value' })).toHaveTextContent('010')
})

test('a text field keeps what was typed', async () => {
  render(<Box label="note" />)
  await user.type(screen.getByRole('textbox', { name: 'note' }), 'شارع ٥')
  expect(screen.getByRole('status', { name: 'note value' })).toHaveTextContent('شارع ٥')
})
