import * as React from 'react'
import { asciiDigits, cn } from '@/lib/utils'

/**
 * A numeric field (`inputMode` numeric, decimal or tel) reads Arabic and Persian digits as ASCII before its onChange sees the value,
 * so every amount, code, phone, national ID and count in the app takes them (owner, 2026-11-19). The value stays text.
 */
export function Input({ className, type, inputMode, onChange, ...props }: React.ComponentProps<'input'>) {
  const numeric = inputMode === 'numeric' || inputMode === 'decimal' || inputMode === 'tel'
  const change = numeric && onChange
    ? (e: React.ChangeEvent<HTMLInputElement>) => {
        const el = e.target
        const ascii = asciiDigits(el.value)
        if (ascii !== el.value) {
          const at = el.selectionStart
          el.value = ascii // same length: one character for one, so the caret stays where it was
          if (at !== null) el.setSelectionRange(at, at)
        }
        onChange(e)
      }
    : onChange
  return (
    <input
      type={type}
      inputMode={inputMode}
      onChange={change}
      className={cn(
        'flex h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 py-1 text-sm min-h-tap transition-colors outline-none placeholder:text-muted-foreground file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
        className,
      )}
      {...props}
    />
  )
}

export function Textarea({ className, ...props }: React.ComponentProps<'textarea'>) {
  return (
    <textarea
      className={cn(
        'flex min-h-20 w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    />
  )
}
