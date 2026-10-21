import * as React from 'react'
import { useTranslation } from 'react-i18next'
import * as SheetPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { cn } from '@/lib/utils'

export const Sheet = SheetPrimitive.Root
export const SheetClose = SheetPrimitive.Close

export function SheetContent({
  className,
  children,
  side = 'right',
  hideClose,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & { side?: 'left' | 'right'; hideClose?: boolean }) {
  const { t } = useTranslation()
  return (
    <SheetPrimitive.Portal>
      <SheetPrimitive.Overlay className="fixed inset-0 z-50 backdrop-brightness-[0.45] backdrop-blur-[2px] data-[state=open]:animate-fade-in" />
      <SheetPrimitive.Content
        className={cn(
          'fixed inset-y-0 z-50 flex h-full flex-col bg-card shadow-2 outline-none transition-transform',
          side === 'right' ? 'end-0 w-full border-s sm:max-w-md' : 'start-0 w-72 border-e',
          'data-[state=open]:animate-fade-in',
          className,
          'pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]',
        )}
        {...props}
      >
        {children}
        {!hideClose && (
          <SheetPrimitive.Close className="absolute top-[calc(0.5rem+env(safe-area-inset-top))] end-2 grid size-11 place-items-center rounded-md text-muted-foreground transition outline-none hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring">
            <X className="size-4" />
            <span className="sr-only">{t('common.close')}</span>
          </SheetPrimitive.Close>
        )}
      </SheetPrimitive.Content>
    </SheetPrimitive.Portal>
  )
}

export const SheetTitle = ({ className, ...props }: React.ComponentProps<typeof SheetPrimitive.Title>) => (
  <SheetPrimitive.Title className={cn('text-base font-semibold', className)} {...props} />
)
export const SheetDescription = ({ className, ...props }: React.ComponentProps<typeof SheetPrimitive.Description>) => (
  <SheetPrimitive.Description className={cn('text-[13px] text-muted-foreground', className)} {...props} />
)
