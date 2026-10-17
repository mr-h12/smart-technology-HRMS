import { Bus, CalendarOff, House, MapPin, SlidersHorizontal } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/common/PageHeader'
import { ErrorBanner, type Row, TextField, toErrs, useForm } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { FaresTab, LeaveTypesTab, ResidencesTab, SitesTab } from './sites'

const GROUPS: [string, string[]][] = [
  ['time', ['month_days', 'day_hours', 'overtime_multiplier']],
  ['lateness', ['lateness_multiplier_office', 'lateness_multiplier_engineer', 'lateness_multiplier_permanent_worker', 'lateness_multiplier_day_laborer', 'office_grace_minutes']],
  ['schedule', ['office_shift_start', 'office_shift_end', 'weekly_off_office', 'weekly_off_field']],
  ['leave', ['leave_days_under_one_year', 'leave_days_one_year_plus', 'leave_casual_days']],
]
const KEYS = GROUPS.flatMap(([, keys]) => keys)
const INTEGER = ['month_days', 'office_grace_minutes', 'leave_days_under_one_year', 'leave_days_one_year_plus', 'leave_casual_days']
const DAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']

/** The 15 business-rule settings: loads them, then shows the form. */
function RulesTab() {
  const { t } = useTranslation()
  const { data, err } = useApi<Row>('/settings')
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{t('settings.tab.rules')}</CardTitle>
          <CardDescription>{t('settings.desc.rules')}</CardDescription>
        </div>
      </CardHeader>
      {data ? (
        <RulesForm initial={data} />
      ) : (
        <CardContent className="space-y-6">
          <ErrorBanner err={err} shown={[]} />
          {!err && <TableSkeleton rows={4} />}
        </CardContent>
      )}
    </Card>
  )
}

/** One form; values stay strings, weekly-off days are a comma list. It starts from the loaded settings and keeps its own `saved` baseline after a save. */
function RulesForm({ initial }: { initial: Row }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const manage = can('setting.manage')
  const send = useSave()
  const [saved, setSaved] = useState(initial)
  const { form, setForm, set } = useForm(initial)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const dirty = JSON.stringify(form) !== JSON.stringify(saved)
  const errs = toErrs(err)
  function save() {
    setBusy(true)
    setErr(undefined)
    send<Row>('/settings', { method: 'PUT', body: form })
      .then((d) => { setSaved(d); setForm(d); toast.success(t('common.saved')) }, setErr)
      .finally(() => setBusy(false))
  }
  const days = (k: string) => (form[k] ? form[k].split(',') : []) as string[]
  const field = (k: string) =>
    k.startsWith('weekly_off_') ? (
      <fieldset key={k} aria-describedby={errs[k] ? `${k}-err` : undefined} className="flex flex-col gap-1.5">
        <legend className="mb-1.5 text-[13px] font-medium leading-none text-foreground">{t(`rule.${k}`)}</legend>
        <div className="flex flex-wrap gap-x-4 gap-y-2">
          {DAYS.map((d) => (
            <label key={d} className="flex min-h-11 items-center gap-2 text-sm">
              <input type="checkbox" checked={days(k).includes(d)} onChange={(e) => set(k, (e.target.checked ? [...days(k), d] : days(k).filter((x) => x !== d)).join(','))} />
              {t(`weekday.${d}`)}
            </label>
          ))}
        </div>
        {errs[k] && <p id={`${k}-err`} className="text-[13px] text-destructive">{errs[k]}</p>}
      </fieldset>
    ) : (
      <TextField
        key={k}
        name={k}
        label={t(`rule.${k}`)}
        {...(k.startsWith('office_shift_') ? { type: 'time' } : { inputMode: INTEGER.includes(k) ? 'numeric' : 'decimal' })}
        values={form}
        set={set}
        errs={errs}
      />
    )
  return (
    <>
      <CardContent className="space-y-6">
        <ErrorBanner err={err} shown={KEYS} />
        {GROUPS.map(([group, keys]) => (
          <fieldset key={group} disabled={!manage}>
            <legend className="mb-3 text-sm font-semibold">{t(`rule.group.${group}`)}</legend>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{keys.map(field)}</div>
          </fieldset>
        ))}
      </CardContent>
      {manage && (
        <CardFooter className="justify-end">
          <Button type="button" disabled={!dirty || busy} onClick={save}>
            {t('common.saveChanges')}
          </Button>
        </CardFooter>
      )}
    </>
  )
}

const TABS = [
  { value: 'sites', key: 'settings.tab.sites', icon: MapPin, ability: 'site.view', Body: SitesTab },
  { value: 'residences', key: 'settings.tab.residences', icon: House, ability: 'site.view', Body: ResidencesTab },
  { value: 'leave-types', key: 'settings.tab.leaveTypes', icon: CalendarOff, ability: 'site.view', Body: LeaveTypesTab },
  { value: 'fares', key: 'settings.tab.fares', icon: Bus, ability: 'site.view', Body: FaresTab },
  { value: 'rules', key: 'settings.tab.rules', icon: SlidersHorizontal, ability: 'setting.view', Body: RulesTab },
]
/** One page of tabs synced to `?tab=`; a missing, unknown or hidden tab shows the first visible one. */
export function SettingsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs = TABS.filter((x) => can(x.ability))
  const tab = (tabs.find((x) => x.value === params.get('tab')) ?? tabs[0])?.value
  return (
    <div className="space-y-6">
      <PageHeader title={t('nav.settings')} description={t('settings.subtitle')} />
      {tab && (
        <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
          <TabsList>
            {tabs.map(({ value, key, icon: Icon }) => (
              <TabsTrigger key={value} value={value}>
                <Icon className="size-4" aria-hidden /> {t(key)}
              </TabsTrigger>
            ))}
          </TabsList>
          {tabs.map(({ value, Body }) => (
            <TabsContent key={value} value={value}>
              <Body />
            </TabsContent>
          ))}
        </Tabs>
      )}
    </div>
  )
}
