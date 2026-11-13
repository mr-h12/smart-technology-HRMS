import { Download } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cairoToday, ErrorBanner, localName, type Row, SelectField, TextField, useForm } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { apiBlob, ApiError } from '@/lib/api'
import { useApi, WORK_SITES } from '@/lib/query'
import { errorKey, STEP } from './attendance-import'

const MAX_DAYS = 31 // the server's own limit (SiteWorkbookTemplate::MAX_DAYS): one sheet per day

/** Today's half-month in Cairo: the 1st to the 15th, else the 16th to the month's last day. Built from the date's text, so no time zone moves it. */
function halfMonth() {
  const today = cairoToday()
  const month = today.slice(0, 7)
  if (Number(today.slice(8)) <= 15) return { from: `${month}-01`, to: `${month}-15` }
  const last = new Date(Date.UTC(Number(today.slice(0, 4)), Number(today.slice(5, 7)), 0)).getUTCDate() // day 0 of the next month
  return { from: `${month}-16`, to: `${month}-${last}` }
}

/** The days from `from` to `to`, both counted. A YYYY-MM-DD string parses as UTC midnight, so the difference is whole days. */
const span = (from: string, to: string) => (Date.parse(to) - Date.parse(from)) / 86_400_000 + 1

/**
 * HR's site workbook (ملف الموقع), the import tab's per-site side: pick an active work site and up to MAX_DAYS days (today's
 * half-month by default), then download the empty file. The steps after the download continue the same numbered list.
 */
export function SiteWorkbookTab() {
  const { t, i18n } = useTranslation()
  const { form, set } = useForm({ site_id: '', ...halfMonth() })
  const { site_id, from, to } = form as Record<'site_id' | 'from' | 'to', string>
  const { data: sites, err: sitesErr } = useApi<Row[]>(WORK_SITES)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<unknown>()
  // the server refuses both; saying so here keeps HR from a download that cannot work
  const why = from && to && (to < from ? t('attendance.import.site.reversed') : span(from, to) > MAX_DAYS ? t('attendance.import.site.tooLong', { max: MAX_DAYS }) : '')

  function download() {
    setBusy(true)
    setErr(undefined)
    apiBlob(`/attendance/site-workbook?${new URLSearchParams({ site_id, from, to })}`)
      .then((blob) => {
        const a = document.createElement('a')
        a.href = URL.createObjectURL(blob)
        a.download = `site-workbook-${from}-${to}.xlsx`
        a.click()
        setTimeout(() => URL.revokeObjectURL(a.href), 1000) // after the browser has started reading it
      }, setErr)
      .finally(() => setBusy(false))
  }
  // a refusal this UI knows follows the UI language, as on the per-type side
  const shownErr = err instanceof ApiError && i18n.exists(errorKey(err.code)) ? new Error(t(errorKey(err.code))) : err

  return (
    <Card className="grid gap-4 p-5">
      <p className="text-sm text-muted-foreground">{t('attendance.import.site.desc', { max: MAX_DAYS })}</p>
      <ol role="list" className="grid gap-4">
        <li className="flex items-start gap-3">
          <span aria-hidden className={STEP}>1</span>
          <div className="grid min-w-0 flex-1 gap-4">
            <div className="grid gap-4 sm:grid-cols-3 sm:items-start lg:max-w-3xl">
              <SelectField name="site_id" label={t('attendance.siteFilter')} values={form} set={set} errs={{}} blank={t('attendance.import.site.choose')} options={(sites ?? []).map((s): [string, string] => [s.id, localName(s, i18n.language)])} />
              <TextField name="from" type="date" label={t('attendance.import.site.from')} values={form} set={set} errs={{}} />
              <TextField name="to" type="date" label={t('attendance.import.site.to')} values={form} set={set} errs={why ? { to: why } : {}} />
            </div>
            <Button type="button" variant="outline" className="justify-self-start" disabled={busy || !site_id || !from || !to || !!why} onClick={download}>
              <Download aria-hidden /> {t('attendance.import.site.download')}
            </Button>
          </div>
        </li>
      </ol>
      <ErrorBanner err={shownErr ?? sitesErr} shown={[]} />
    </Card>
  )
}
