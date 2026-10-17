import { Menu, Moon, Sun, Users, MapPin, House, Banknote, LogOut } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import logoMark from '@/assets/logo-mark.png'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/lib/auth'
import { cn } from '@/lib/utils'
import { SegmentedControl } from './SegmentedControl'

const NAV = [
  { path: '/employees', key: 'nav.employees', icon: Users, ability: 'employee.view' },
  { path: '/sites', key: 'nav.sites', icon: MapPin, ability: 'site.view' },
  { path: '/residences', key: 'nav.residences', icon: House, ability: 'site.view' },
  { path: '/fares', key: 'nav.fares', icon: Banknote, ability: 'site.view' },
]

/** The first page the user may open; the fallback is the 1a landing page. */
export const homePath = (can: (ability: string) => boolean) => NAV.find((n) => can(n.ability))?.path ?? '/employees'

export function LanguageToggle() {
  const { t, i18n } = useTranslation()
  return (
    <SegmentedControl
      label={t('toggle.language')}
      value={i18n.language}
      onChange={(l) => i18n.changeLanguage(l)}
      options={[
        { value: 'en', label: 'English', content: <span lang="en" aria-hidden>EN</span> },
        { value: 'ar', label: 'العربية', content: <span lang="ar" aria-hidden>ع</span> },
      ]}
    />
  )
}

function Brand() {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-16 items-center gap-3 border-b px-4 py-3">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md dark:bg-white dark:p-0.5">
        <img src={logoMark} alt="" className="size-full object-contain" draggable={false} />
      </span>
      <span className="text-[15px] font-semibold">{t('app.name')}</span>
    </div>
  )
}

function Nav({ onNavigate }: { onNavigate?: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  return (
    <nav aria-label={t('nav.main')}>
      <ul className="flex flex-col gap-1">
        {NAV.filter((n) => can(n.ability)).map(({ path, key, icon: Icon }) => (
          <li key={path}>
            <NavLink
              to={path}
              onClick={onNavigate}
              className={({ isActive }) =>
                cn(
                  'flex min-h-11 items-center gap-3 rounded-lg px-3 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                  isActive && 'bg-muted font-medium text-primary',
                )
              }
            >
              <Icon className="size-5 shrink-0" />
              {t(key)}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}

export function AppLayout() {
  const { t } = useTranslation()
  const { theme, setTheme } = useTheme()
  const { me, logout } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)
  const { pathname } = useLocation()

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [pathname])

  return (
    <div className="flex min-h-dvh bg-background text-foreground">
      <a
        href="#page-content"
        className="sr-only rounded-lg border border-input bg-card px-3 py-2 shadow-2 focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:outline-2 focus:outline-offset-2 focus:outline-ring"
      >
        {t('skip')}
      </a>

      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-e bg-sidebar lg:flex">
        <Brand />
        <div className="flex-1 overflow-y-auto px-2 py-3">
          <Nav />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex min-h-16 items-center gap-2 border-b bg-card px-3 py-2 sm:px-4">
          <Button variant="ghost" size="icon" className="lg:hidden" onClick={() => setMobileOpen(true)} aria-label={t('nav.open')}>
            <Menu className="size-5" />
          </Button>
          <div className="ms-auto flex items-center gap-2">
            <span className="hidden text-sm font-medium sm:inline">{me?.name}</span>
            <LanguageToggle />
            <SegmentedControl
              label={t('toggle.theme')}
              value={theme}
              onChange={setTheme}
              options={[
                { value: 'light', label: t('toggle.light'), content: <Sun className="size-4" aria-hidden /> },
                { value: 'dark', label: t('toggle.dark'), content: <Moon className="size-4" aria-hidden /> },
              ]}
            />
            <Button variant="ghost" size="sm" onClick={logout}>
              <LogOut className="rtl:-scale-x-100" aria-hidden />
              {t('auth.logout')}
            </Button>
          </div>
        </header>
        <main id="page-content" className="mx-auto w-full max-w-[1600px] flex-1 scroll-mt-20 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>

      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-64 p-0">
          <SheetTitle className="sr-only">{t('nav.main')}</SheetTitle>
          <SheetDescription className="sr-only">{t('app.name')}</SheetDescription>
          <Brand />
          <div className="px-2 py-3">
            <Nav onNavigate={() => setMobileOpen(false)} />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
