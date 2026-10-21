import * as React from 'react'
import { cn } from '@/lib/utils'

export function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      className={cn(
        'flex h-9 w-full min-w-0 rounded-md border border-input bg-card px-3 py-1 text-sm max-sm:h-11 max-sm:text-base transition-colors outline-none placeholder:text-muted-foreground file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
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
        'flex min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm max-sm:text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    />
  )
}
