import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** The visible focus ring for controls without one of their own. */
export const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
