import * as React from 'react'
import * as TabsPrimitive from '@radix-ui/react-tabs'
import { cn } from '@/lib/utils'

export const Tabs = TabsPrimitive.Root

/** A strip that scrolls sideways on a phone keeps the selected tab in view, on mount and whenever the selection or the labels change (works in RTL). */
export function TabsList({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.List>) {
  const ref = React.useRef<HTMLDivElement>(null)
  React.useEffect(() => {
    const list = ref.current
    if (!list) return
    const show = () => list.querySelector('[data-state=active]')?.scrollIntoView({ inline: 'nearest', block: 'nearest' })
    show()
    const watch = new MutationObserver(show)
    watch.observe(list, { attributes: true, attributeFilter: ['data-state'], subtree: true, childList: true, characterData: true }) // a label change (language) moves the tabs too
    document.fonts?.addEventListener('loadingdone', show) // so does a font finishing its load
    return () => {
      document.fonts?.removeEventListener('loadingdone', show)
      watch.disconnect()
    }
  }, [])
  return (
    <TabsPrimitive.List
      ref={ref}
      className={cn('inline-flex h-10 max-w-full items-center gap-1 overflow-x-auto rounded-xl border bg-muted p-1 text-muted-foreground scrollbar-thin', className)}
      {...props}
    />
  )
}

export function TabsTrigger({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
  return (
    <TabsPrimitive.Trigger
      className={cn(
        'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg px-3 text-[13px] font-medium whitespace-nowrap transition-all outline-none hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring data-[state=active]:bg-card data-[state=active]:text-foreground data-[state=active]:shadow-1',
        className,
      )}
      {...props}
    />
  )
}

export function TabsContent({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.Content>) {
  return <TabsPrimitive.Content className={cn('mt-5 outline-none', className)} {...props} />
}
