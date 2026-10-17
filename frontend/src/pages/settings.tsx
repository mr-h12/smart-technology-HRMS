import { Bus, CalendarOff, House, MapPin } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/common/PageHeader'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/lib/auth'
import { FaresTab, LeaveTypesTab, ResidencesTab, SitesTab } from './sites'

const TABS = [
  { value: 'sites', key: 'settings.tab.sites', icon: MapPin, ability: 'site.view', Body: SitesTab },
  { value: 'residences', key: 'settings.tab.residences', icon: House, ability: 'site.view', Body: ResidencesTab },
  { value: 'leave-types', key: 'settings.tab.leaveTypes', icon: CalendarOff, ability: 'site.view', Body: LeaveTypesTab },
  { value: 'fares', key: 'settings.tab.fares', icon: Bus, ability: 'site.view', Body: FaresTab },
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
