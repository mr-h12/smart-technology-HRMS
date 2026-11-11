import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Alert } from '@/components/common/Alert'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError, type OpenItem } from '@/lib/api'
import { cn, FOCUS } from '@/lib/utils'

export type Row = Record<string, any> // eslint-disable-line @typescript-eslint/no-explicit-any
export type Errs = Record<string, string>

export const selectCls =
  'flex h-9 w-full rounded-lg border border-input bg-card px-3 text-sm min-h-tap outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive'

export const toErrs = (e: unknown): Errs =>
  e instanceof ApiError ? Object.fromEntries(e.details.filter((d) => d.field).map((d) => [d.field!, d.message])) : {}
/** Shows every error message that no rendered field displays (non-422s, or fields hidden for this type). */
export function ErrorBanner({ err, shown }: { err: unknown; shown: string[] }) {
  if (!err) return null
  const msgs =
    err instanceof ApiError && err.details.length
      ? err.details.filter((d) => !d.field || !shown.includes(d.field)).map((d) => d.message)
      : [err instanceof Error ? err.message : String(err)]
  const dates = err instanceof ApiError ? err.dates : []
  const items = err instanceof ApiError ? err.items : []
  return msgs.length ? (
    <Alert tone="danger">
      {msgs.map((m, i) => (
        <p key={i}>{m}</p>
      ))}
      {!!dates.length && <Dates dates={dates} />}
      {!!items.length && <OpenItems items={items} />}
    </Alert>
  ) : null
}

/** The open items a refusal names, each a link to the screen where it is decided. */
function OpenItems({ items }: { items: OpenItem[] }) {
  const { t } = useTranslation()
  return (
    <ul>
      {items.map((it) => (
        <li key={`${it.kind}-${it.id}`}>
          <Link to={it.kind === 'leave_request' ? `/leave?request=${it.id}` : `/wage-proposals?proposal=${it.id}`} className={cn('inline-flex min-h-tap items-center font-medium text-primary underline underline-offset-4', FOCUS)}>
            <span>
              {it.kind === 'leave_request'
                ? ltrDates(t('employee.openItems.leave_request', { from: it.from_date, to: it.to_date, status: t(`leave.status.${it.status}`) }))
                : t('employee.openItems.wage_proposal')}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

/** ISO dates as a wrapping list, each in an LTR isolate. */
export const Dates = ({ dates }: { dates: string[] }) => <ul className="flex flex-wrap gap-x-4">{dates.map((d) => <li key={d}><bdi dir="ltr">{d}</bdi></li>)}</ul>

/** A record's name in the UI language: in English `name_en` when set, else `fallback`, else `name_ar`; Arabic always shows `name_ar`. */
export const localName = (row: Row, lang: string, fallback?: string): string => (lang === 'en' ? row.name_en || fallback || row.name_ar : row.name_ar)

/** Intl locale for a UI language; Arabic keeps Latin digits. */
export const intlLocale = (lng: string) => (lng === 'ar' ? 'ar-EG-u-nu-latn' : lng)

/** `text` with each ISO date (YYYY-MM-DD) in an LTR isolate: inside RTL text, after an Arabic word, 2026-10-25 would draw as 25-10-2026. */
export const ltrDates = (text: string) => text.split(/(\d{4}-\d{2}-\d{2})/).map((part, i) => (i % 2 ? <bdi key={i} dir="ltr" className="whitespace-nowrap">{part}</bdi> : part))

/** Today's date in Cairo as YYYY-MM-DD. */
export const cairoToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Cairo' })

const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
/** The `weekday.*` / `weekdayShort.*` key of an ISO date, read in UTC so no time zone moves it to another day. */
export const weekdayOf = (date: string) => WEEKDAYS[new Date(`${date}T00:00:00Z`).getUTCDay()]

/** A role's name in the UI language; '—' when there is none. */
export function useRoleName() {
  const { i18n } = useTranslation()
  return (r: Row | null | undefined) => (r ? (i18n.language === 'ar' ? r.name_ar : r.name) : '—')
}

/** Formats a stored UTC timestamp as Cairo wall time (12-hour) in the current language; date and time unless `format` says otherwise. */
export function useCairoTime(format: Intl.DateTimeFormatOptions = { dateStyle: 'short', timeStyle: 'medium' }) {
  const { i18n } = useTranslation()
  const f = new Intl.DateTimeFormat(intlLocale(i18n.language), { timeZone: 'Africa/Cairo', ...format, hour12: true })
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
export const isHhmm = (v: string | null): v is string => !!v && /^\d{2}:\d{2}$/.test(v)

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
 * unreadable entry, goes out as typed (with `needsPeriod` set, so "10:30" is not mistaken for 10:30); a Save calls `stopAtMissingPeriod` first. `label` names the AM/PM group; `quietHint` keeps the
 * hint for screen readers only; the rest goes to the box.
 */
export function TimeInput({ value, onChange, label, quietHint, className, disabled, ...rest }: { value: string; onChange: (v: string, needsPeriod: boolean) => void; label: string; quietHint?: boolean } & Omit<React.ComponentProps<'input'>, 'value' | 'onChange' | 'onBlur' | 'type'>) {
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
    onChange(v, r === 'period')
  }
  const needsChoice = to24(draft.text, draft.pm) === 'period'
  return (
    <div ref={root} className="grid w-full gap-1">
      <div className="flex gap-1.5">
        <Input {...rest} ref={box} type="text" dir="ltr" inputMode="numeric" autoComplete="off" placeholder="--:--" disabled={disabled} value={draft.text}
          aria-invalid={rest['aria-invalid'] || needsChoice || undefined} data-needs-period={needsChoice || undefined} aria-describedby={[rest['aria-describedby'], descId].filter(Boolean).join(' ')}
          // the shared focus ring, except on a box that needs AM/PM or has an error: there it turns to the warning colour
          className={cn('flex-1 rtl:text-right', (rest['aria-invalid'] || needsChoice) && 'focus-visible:border-destructive focus-visible:outline-destructive', className)}
          onChange={(e) => emit(e.target.value, draft.pm)}
          onBlur={() => { const v = to24(draft.text, draft.pm); if (isHhmm(v)) setDraft(to12(v)) }} />
        {/* an inset outline, not a border, draws the frame: the buttons fill the box's full height (44 px on touch) */}
        <div role="group" aria-label={t('time.period', { field: label })} className="flex shrink-0 overflow-hidden rounded-lg bg-card outline-1 -outline-offset-1 outline-solid outline-input">
          {[false, true].map((pm) => (
            // mousedown keeps the cursor where it is, so a click here is not the box being left; from outside, the click moves it into the box
            <button key={String(pm)} type="button" aria-pressed={draft.pm === pm} aria-label={t(pm ? 'time.pmName' : 'time.amName')} disabled={disabled}
              className="w-9 min-w-tap px-1.5 text-sm font-semibold text-muted-foreground outline-none hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-solid focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-primary aria-pressed:text-primary-foreground"
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

/**
 * Dialog whose form state lives in the body, so it is fresh on every open (Radix unmounts closed content). Passing `open` makes it controlled;
 * `clearErrOnEdit` drops the last error once a value changes; `description` opens the body as small muted text and is what screen readers
 * announce after the title. `validate` returns field → message for what must be fixed before anything is sent (empty: send).
 * `reloadKey` names the record version `init` came from: when it changes while the dialog is open, the form restarts from the new `init`
 * and says so, so a Save after a conflict sends what the user now sees. `onDone` gets what `submit` resolved to.
 */
export function FormDialog<R>({ title, description, trigger, open: openProp, onOpenChange, init, submit, validate, reloadKey, onDone, shown, children, footer = 'common.save', clearErrOnEdit, destructive }: {
  title: string
  description?: React.ReactNode
  trigger?: React.ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
  init: Row
  submit: (form: Row) => Promise<R>
  validate?: (form: Row) => Errs
  reloadKey?: React.Key
  onDone?: (result: R) => void
  shown: string[]
  children: (f: FormApi) => React.ReactNode
  footer?: string
  clearErrOnEdit?: boolean
  /** The footer button is red: the dialog confirms a delete. */
  destructive?: boolean
}) {
  const { t } = useTranslation()
  const [own, setOwn] = useState(false)
  const open = openProp ?? own
  const setOpen = (o: boolean) => (openProp === undefined ? setOwn(o) : onOpenChange?.(o))
  // the key the open dialog started from; while closed it follows the latest
  const [openedWith, setOpenedWith] = useState(reloadKey)
  if (!open && openedWith !== reloadKey) setOpenedWith(reloadKey)
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {trigger && <span onClick={() => setOpen(true)}>{trigger}</span>}
      <DialogContent {...(!description && { 'aria-describedby': undefined })}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <FormBody key={reloadKey} init={init} submit={submit} validate={validate} shown={shown} footer={footer} clearErrOnEdit={clearErrOnEdit} destructive={destructive} description={description}
          notice={open && openedWith !== reloadKey ? t('common.reloaded') : undefined}
          onDone={(r) => { setOpen(false); onDone?.(r) }}>
          {children}
        </FormBody>
      </DialogContent>
    </Dialog>
  )
}

function FormBody<R>({ init, submit, validate, onDone, shown, footer, clearErrOnEdit, destructive, description, notice, children }: { init: Row; submit: (form: Row) => Promise<R>; validate?: (form: Row) => Errs; onDone: (result: R) => void; shown: string[]; footer: string; clearErrOnEdit?: boolean; destructive?: boolean; description?: React.ReactNode; notice?: string; children: (f: FormApi) => React.ReactNode }) {
  const { t } = useTranslation()
  const { form, set } = useForm(init)
  const [err, setErr] = useState<unknown>()
  const [invalid, setInvalid] = useState<Errs>({})
  const busy = useRef(false)
  const [, rerender] = useState(0)
  async function go() {
    if (busy.current) return
    const bad = validate?.(form) ?? {}
    setInvalid(bad)
    if (Object.keys(bad).length) return
    busy.current = true
    rerender((n) => n + 1)
    setErr(undefined)
    try {
      onDone(await submit(form))
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
        {/* status: it replaces the error the user was reading, so a screen reader must hear it */}
        {notice && <div role="status"><Alert tone="warning">{notice}</Alert></div>}
        <ErrorBanner err={err} shown={shown} />
        {description && <DialogDescription className="text-sm">{description}</DialogDescription>}
        {children({ values: form, set: clearErrOnEdit ? (k, v) => { setErr(undefined); set(k, v) } : set, errs: { ...toErrs(err), ...invalid } })}
      </DialogBody>
      <DialogFooter>
        <Button type="button" variant={destructive ? 'destructive' : undefined} disabled={busy.current} onClick={go}>
          {t(footer)}
        </Button>
      </DialogFooter>
    </>
  )
}
