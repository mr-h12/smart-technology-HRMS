import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

/** Every custom @theme name the kit uses. theme.test.ts fails when index.css and this list disagree. */
export const THEME_NAMES = {
  text: ['body', 'table', 'page-title', 'section-title', 'card-title', 'kpi'],
  shadow: ['float'],
  spacing: ['tap'],
}

const merge = extendTailwindMerge({ extend: { theme: THEME_NAMES } })

export function cn(...inputs: ClassValue[]) {
  return merge(clsx(inputs))
}

/** The visible focus ring for controls without one of their own. */
export const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
/** A weekly-off or holiday day on the strip and the grid: bg-muted/50 would vanish on the grid's bg-muted head row. */
export const OFF = 'bg-muted-foreground/10'

/**
 * Arabic-Indic (٠–٩) and Persian (۰–۹) digits and the Arabic decimal separator «٫» to ASCII, so typed amounts, codes, phones and
 * national IDs validate. Text in, text out: leading zeros stay and money never becomes a float. The API's NormalizeDigits does the same.
 */
export const asciiDigits = (v: string) =>
  v.replace(/[٠-٩۰-۹]/g, (c) => String(c.charCodeAt(0) - (c < '۰' ? 0x660 : 0x6f0))).replace(/٫/g, '.')
