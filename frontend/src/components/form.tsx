import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/utils'

export type Row = Record<string, any> // eslint-disable-line @typescript-eslint/no-explicit-any
export type Errs = Record<string, string>

export const selectCls =
  'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive'

export const toErrs = (e: unknown): Errs =>
  e instanceof ApiError ? Object.fromEntries(e.details.filter((d) => d.field).map((d) => [d.field!, d.message])) : {}
/** Shows every error message that no rendered field displays (non-422s, or fields hidden for this type). */
export function ErrorBanner({ err, shown }: { err: unknown; shown: string[] }) {
  if (!err) return null
  const msgs =
    err instanceof ApiError && err.details.length
      ? err.details.filter((d) => !d.field || !shown.includes(d.field)).map((d) => d.message)
      : [err instanceof Error ? err.message : String(err)]
  return msgs.length ? (
    <div role="alert" className="rounded-md border border-destructive p-3 text-sm text-destructive">
      {msgs.map((m, i) => (
        <p key={i}>{m}</p>
      ))}
    </div>
  ) : null
}

/** A record's name in the UI language: in English `name_en` when set, else `fallback`, else `name_ar`; Arabic always shows `name_ar`. */
export const localName = (row: Row, lang: string, fallback?: string): string => (lang === 'en' ? row.name_en || fallback || row.name_ar : row.name_ar)

/** Intl locale for a UI language; Arabic keeps Latin digits. */
export const intlLocale = (lng: string) => (lng === 'ar' ? 'ar-EG-u-nu-latn' : lng)

/** `text` with each ISO date (YYYY-MM-DD) in an LTR isolate: inside RTL text, after an Arabic word, 2026-10-25 would draw as 25-10-2026. */
export const ltrDates = (text: string) => text.split(/(\d{4}-\d{2}-\d{2})/).map((part, i) => (i % 2 ? <bdi key={i} dir="ltr">{part}</bdi> : part))

/** Today's date in Cairo as YYYY-MM-DD. */
export const cairoToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Cairo' })

/** Formats a stored UTC timestamp as Cairo wall time (12-hour) in the current language. */
export function useCairoTime() {
  const { i18n } = useTranslation()
  const f = new Intl.DateTimeFormat(intlLocale(i18n.language), { timeZone: 'Africa/Cairo', dateStyle: 'short', timeStyle: 'medium', hour12: true })
  return (iso: string | null | undefined) => (iso ? f.format(new Date(iso)) : '—')
}

const timeFormats = new Map<string, Intl.DateTimeFormat>()

/** A stored "HH:MM" (or "HH:MM:SS") time as 12-hour text: "9:00 AM", «9:00 ص»; '—' when blank. */
export function formatTime(v: string | number | null | undefined, lng: string) {
  const m = /^(\d{2}):(\d{2})/.exec(String(v ?? ''))
  if (!m) return v == null || v === '' ? '—' : String(v)
  let f = timeFormats.get(lng)
  if (!f) timeFormats.set(lng, (f = new Intl.DateTimeFormat(intlLocale(lng), { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'UTC' })))
  return f.format(Date.UTC(1970, 0, 1, +m[1], +m[2]))
}

export function useMoney() {
  const { i18n } = useTranslation()
  const f = new Intl.NumberFormat(intlLocale(i18n.language), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return (v: string | null | undefined) => (v == null ? '—' : f.format(v as unknown as number)) // string input is formatted exactly by Intl
}

/** Label + control + server error wired with aria-describedby. */
export function Field({ name, id = name, label, errs, children }: { name: string; id?: string; label: string; errs: Errs; children: (p: Row) => React.ReactNode }) {
  const err = errs[name]
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children({ id, name, 'aria-invalid': !!err || undefined, 'aria-describedby': err ? `${id}-err` : undefined })}
      {err && (
        <p id={`${id}-err`} className="text-[13px] text-destructive">
          {err}
        </p>
      )}
    </div>
  )
}

/** Arabic-Indic / Eastern Arabic digits and the Arabic decimal separator to ASCII, so typed amounts validate. */
export const asciiDigits = (v: string) =>
  v.replace(/[٠-٩۰-۹]/g, (c) => String(c.charCodeAt(0) - (c < '۰' ? 0x660 : 0x6f0))).replace(/٫/g, '.')

/** `type="time"` renders the 12-hour `TimeInput`; its value stays "HH:MM" (24-hour). */
export function TextField({ name, label, values, set, errs, ns = '', ...rest }: { name: string; label: string; values: Row; set: (k: string, v: string) => void; errs: Errs; ns?: string } & Omit<React.ComponentProps<'input'>, 'form'>) {
  return (
    <Field name={name} id={ns + name} label={label} errs={errs}>
      {(p) =>
        rest.type === 'time' ? (
          <TimeInput {...p} label={label} disabled={rest.disabled} value={values[name] ?? ''} onChange={(v) => set(name, v)} />
        ) : (
          <Input {...p} {...rest} value={values[name] ?? ''} onChange={(e) => set(name, rest.inputMode === 'decimal' || rest.inputMode === 'numeric' ? asciiDigits(e.target.value) : e.target.value)} />
        )
      }
    </Field>
  )
}

const pad = (n: number) => String(n).padStart(2, '0')

/**
 * Typed text ("9:30", "930", "9", Arabic digits) and the AM/PM choice (null: none yet) → "HH:MM" (24-hour); '' when blank,
 * 'period' when an hour 1–12 has no AM/PM chosen yet, null when it is not a time. 12 AM is 00, 12 PM is 12.
 * 24-hour typing needs no choice and ignores one: an hour of 13–23, or one written with a leading zero ("0", "09", "0030").
 */
export function to24(text: string, pm: boolean | null): string | null {
  const s = asciiDigits(text).trim()
  if (!s) return ''
  const m = /^(\d{1,2})(?::?(\d{2}))?$/.exec(s)
  if (!m) return null
  const h = +m[1]
  const min = +(m[2] ?? 0)
  if (h > 23 || min > 59) return null
  if (h > 12 || m[1][0] === '0') return `${pad(h)}:${pad(min)}`
  return pm === null ? 'period' : `${pad((h % 12) + (pm ? 12 : 0))}:${pad(min)}`
}
const isHhmm = (v: string | null): v is string => !!v && /^\d{2}:\d{2}$/.test(v)

/** "HH:MM" → the box text ("5:30") and whether it is PM; blank or anything else has no AM/PM chosen. */
const to12 = (v: string): { text: string; pm: boolean | null } => {
  const m = /^(\d{2}):(\d{2})/.exec(v)
  return m ? { text: `${+m[1] % 12 || 12}:${m[2]}`, pm: +m[1] >= 12 } : { text: v, pm: null }
}

/**
 * Stops a Save while a time box near `from` (its form or dialog, else the page) still waits for AM/PM: the first such box gets the
 * cursor and keeps its hint. A period-less "10:15" must never reach the API, which would read it as a valid 24-hour time.
 */
export function stopAtMissingPeriod(from: Element): boolean {
  const box = (from.closest('form, [role="dialog"]') ?? document).querySelector<HTMLElement>('[data-needs-period]')
  box?.focus()
  return !!box
}

/**
 * A 12-hour time: a box for the hour and minutes plus an AM/PM (ص/م) choice. `value` in and out is "HH:MM" (24-hour), '' for none.
 * A blank value has no AM/PM chosen; a 12-hour time typed without one is never guessed: the box asks for the choice, and it, like an
 * unreadable entry, goes out as typed; a Save calls `stopAtMissingPeriod` first. `label` names the AM/PM group; `quietHint` keeps the
 * hint for screen readers only; the rest goes to the box.
 */
export function TimeInput({ value, onChange, label, quietHint, className, disabled, ...rest }: { value: string; onChange: (v: string) => void; label: string; quietHint?: boolean } & Omit<React.ComponentProps<'input'>, 'value' | 'onChange' | 'onBlur' | 'type'>) {
  const { t } = useTranslation()
  const descId = useId()
  const root = useRef<HTMLDivElement>(null)
  const box = useRef<HTMLInputElement>(null)
  const [shown, setShown] = useState(value) // the value the box and the choice stand for
  const [draft, setDraft] = useState(() => to12(value))
  if (value !== shown) {
    setShown(value)
    setDraft(to12(value))
  }
  const emit = (text: string, pm: boolean | null) => {
    const r = to24(text, pm)
    const v = r === '' || isHhmm(r) ? r : text
    setDraft({ text, pm })
    setShown(v)
    onChange(v)
  }
  const needsChoice = to24(draft.text, draft.pm) === 'period'
  return (
    <div ref={root} className="grid w-full gap-1">
      <div className="flex gap-1.5">
        <Input {...rest} ref={box} type="text" dir="ltr" inputMode="numeric" autoComplete="off" placeholder="--:--" disabled={disabled} value={draft.text}
          aria-invalid={rest['aria-invalid'] || needsChoice || undefined} data-needs-period={needsChoice || undefined} aria-describedby={[rest['aria-describedby'], descId].filter(Boolean).join(' ')}
          // the shared focus ring, except on a box that needs AM/PM or has an error: there it turns to the warning colour
          className={cn('h-11 flex-1 tabular sm:h-9 rtl:text-right', (rest['aria-invalid'] || needsChoice) && 'focus-visible:border-destructive focus-visible:outline-destructive', className)}
          onChange={(e) => emit(e.target.value, draft.pm)}
          onBlur={() => { const v = to24(draft.text, draft.pm); if (isHhmm(v)) setDraft(to12(v)) }} />
        <div role="group" aria-label={t('time.period', { field: label })} className="flex shrink-0 overflow-hidden rounded-md border border-input bg-card">
          {[false, true].map((pm) => (
            // mousedown keeps the cursor where it is, so a click here is not the box being left; from outside, the click moves it into the box
            <button key={String(pm)} type="button" aria-pressed={draft.pm === pm} aria-label={t(pm ? 'time.pmName' : 'time.amName')} disabled={disabled}
              className="min-w-11 px-2 text-sm font-semibold text-muted-foreground outline-none hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-solid focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-primary aria-pressed:text-primary-foreground sm:min-w-9"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => { emit(draft.text, pm); if (!root.current?.contains(document.activeElement)) box.current?.focus() }}>
              {t(pm ? 'time.pm' : 'time.am')}
            </button>
          ))}
        </div>
      </div>
      {/* the box's description: the hint while a choice is missing, otherwise (for screen readers only) the chosen AM/PM */}
      <p id={descId} className={needsChoice && !quietHint ? 'text-[13px] text-warning' : 'sr-only'}>
        {needsChoice ? t('time.choosePeriod') : draft.pm === null ? t('time.noPeriod') : t(draft.pm ? 'time.pmName' : 'time.amName')}
      </p>
    </div>
  )
}

/** `groups` render as `<optgroup>`s after the plain `options`. */
export function SelectField({ name, label, values, set, errs, options, groups, blank, disabled }: { name: string; label: string; values: Row; set: (k: string, v: string) => void; errs: Errs; options: [string, string][]; groups?: [label: string, options: [string, string][]][]; blank?: boolean | string; disabled?: boolean }) {
  return (
    <Field name={name} label={label} errs={errs}>
      {(p) => (
        <select {...p} disabled={disabled} className={selectCls} value={values[name] ?? ''} onChange={(e) => set(name, e.target.value)}>
          {blank && <option value="">{typeof blank === 'string' ? blank : ''}</option>}
          {options.map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
          {groups?.map(([g, opts]) => (
            <optgroup key={g} label={g}>
              {opts.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      )}
    </Field>
  )
}

export function useForm(initial: Row = {}) {
  const [form, setForm] = useState<Row>(initial)
  return { form, setForm, set: (k: string, v: string) => setForm((f) => ({ ...f, [k]: v })) }
}

export { useList } from '@/lib/query'

/** Controlled-dialog props for a dialog opened from a row menu; it mounts only while open. */
export type Controlled = { open?: boolean; onOpenChange?: (open: boolean) => void }

export type FormApi = { values: Row; set: (k: string, v: string) => void; errs: Record<string, string> }

/** Dialog whose form state lives in the body, so it is fresh on every open (Radix unmounts closed content). Passing `open` makes it controlled. */
export function FormDialog({ title, trigger, open: openProp, onOpenChange, init, submit, onDone, shown, children, footer = 'common.save' }: {
  title: string
  trigger?: React.ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
  init: Row
  submit: (form: Row) => Promise<unknown>
  onDone: () => void
  shown: string[]
  children: (f: FormApi) => React.ReactNode
  footer?: string
}) {
  const [own, setOwn] = useState(false)
  const open = openProp ?? own
  const setOpen = (o: boolean) => (openProp === undefined ? setOwn(o) : onOpenChange?.(o))
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {trigger && <span onClick={() => setOpen(true)}>{trigger}</span>}
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <FormBody init={init} submit={submit} shown={shown} footer={footer} onDone={() => { setOpen(false); onDone() }}>
          {children}
        </FormBody>
      </DialogContent>
    </Dialog>
  )
}

function FormBody({ init, submit, onDone, shown, footer, children }: { init: Row; submit: (form: Row) => Promise<unknown>; onDone: () => void; shown: string[]; footer: string; children: (f: FormApi) => React.ReactNode }) {
  const { t } = useTranslation()
  const { form, set } = useForm(init)
  const [err, setErr] = useState<unknown>()
  const busy = useRef(false)
  const [, rerender] = useState(0)
  async function go() {
    if (busy.current) return
    busy.current = true
    rerender((n) => n + 1)
    setErr(undefined)
    try {
      await submit(form)
      onDone()
    } catch (e) {
      setErr(e)
    } finally {
      busy.current = false
      rerender((n) => n + 1)
    }
  }
  return (
    <>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={err} shown={shown} />
        {children({ values: form, set, errs: toErrs(err) })}
      </DialogBody>
      <DialogFooter>
        <Button type="button" disabled={busy.current} onClick={go}>
          {t(footer)}
        </Button>
      </DialogFooter>
    </>
  )
}
