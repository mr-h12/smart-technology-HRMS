import {
  Bus,
  ChevronDown,
  House,
  Languages,
  LogOut,
  MapPin,
  Menu,
  Moon,
  PanelLeft,
  ScrollText,
  ShieldCheck,
  Sun,
  UserCog,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { Toaster } from 'sonner'
import logoMark from '@/assets/logo-mark.png'
import { UserAvatar } from '@/components/common/UserAvatar'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipProvider } from '@/components/ui/misc'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/lib/auth'
import { cn } from '@/lib/utils'
import { SegmentedControl } from './SegmentedControl'

export const NAV: { group: string; items: { path: string; key: string; icon: LucideIcon; ability: string }[] }[] = [
  { group: 'nav.group.hr', items: [{ path: '/employees', key: 'nav.employees', icon: Users, ability: 'employee.view' }] },
  {
    group: 'nav.group.settings',
    items: [
      { path: '/sites', key: 'nav.sites', icon: MapPin, ability: 'site.view' },
      { path: '/residences', key: 'nav.residences', icon: House, ability: 'site.view' },
      { path: '/fares', key: 'nav.fares', icon: Bus, ability: 'site.view' },
    ],
  },
  {
    group: 'nav.group.admin',
    items: [
      { path: '/console/accounts', key: 'nav.accounts', icon: UserCog, ability: 'user.view' },
      { path: '/console/roles', key: 'nav.roles', icon: ShieldCheck, ability: 'role.view' },
      { path: '/console/audit', key: 'nav.audit', icon: ScrollText, ability: 'audit_log.view' },
    ],
  },
]

/** The first page the user may open; the fallback is the 1a landing page. */
export const homePath = (can: (ability: string) => boolean) =>
  NAV.flatMap((g) => g.items).find((n) => can(n.ability))?.path ?? '/employees'

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'

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

export function ThemeToggle() {
  const { t } = useTranslation()
  const { theme, setTheme } = useTheme()
  return (
    <SegmentedControl
      label={t('toggle.theme')}
      value={theme}
      onChange={setTheme}
      options={[
        { value: 'light', label: t('toggle.light'), content: <Sun className="size-4" aria-hidden /> },
        { value: 'dark', label: t('toggle.dark'), content: <Moon className="size-4" aria-hidden /> },
      ]}
    />
  )
}

/** The Smart Technology mark. On dark surfaces it sits on a light tile so its charcoal blocks stay visible. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-md dark:bg-white dark:p-0.5', className)}>
      <img src={logoMark} alt="" className="size-full object-contain" draggable={false} />
    </span>
  )
}

/** "SMART technology" wordmark in the logo's colours, with the product name underneath. */
export function BrandName({ product }: { product: string }) {
  return (
    <span className="flex flex-col leading-none">
      <span className="text-[15px] font-extrabold tracking-tight whitespace-nowrap" dir="ltr">
        <span className="text-[#1b2a4a] dark:text-white">SMART</span> <span className="text-[#5b8bd0]">technology</span>
      </span>
      <span className="mt-0.5 text-[10px] font-medium tracking-[0.18em] text-muted-foreground uppercase">{product}</span>
    </span>
  )
}

function SidebarNav({ collapsed = false, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  return (
    <nav aria-label={t('nav.main')} className="flex flex-col">
      {NAV.map(({ group, items }) => {
        const visible = items.filter((n) => can(n.ability))
        if (visible.length === 0) return null
        return (
          <div key={group}>
            {collapsed ? (
              <div aria-hidden className="mx-3 mt-2 mb-1 border-t" />
            ) : (
              <p className="px-3 pt-3 pb-1 text-start text-[11px] font-medium tracking-[0.08em] text-muted-foreground/80 uppercase">{t(group)}</p>
            )}
            <ul className="flex flex-col gap-1">
              {visible.map(({ path, key, icon: Icon }) => {
                const label = t(key)
                const link = (
                  <NavLink
                    to={path}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                      cn(
                        'group relative flex min-h-11 items-center gap-3 rounded-lg px-3 text-foreground transition-colors hover:bg-muted',
                        FOCUS,
                        isActive && 'bg-muted font-medium',
                        collapsed && 'justify-center px-0',
                      )
                    }
                  >
                    {({ isActive }) => (
                      <>
                        <span
                          aria-hidden
                          className={cn(
                            'absolute inset-y-2 start-0 w-[3px] origin-center rounded-e-full bg-primary transition-[transform,opacity] duration-200',
                            isActive ? 'scale-y-100 opacity-100' : 'scale-y-0 opacity-0',
                          )}
                        />
                        <Icon aria-hidden className={cn('size-5 shrink-0 transition-colors group-hover:text-primary', isActive ? 'text-primary' : 'text-muted-foreground')} />
                        <span className={collapsed ? 'sr-only' : 'min-w-0 flex-1 truncate'}>{label}</span>
                      </>
                    )}
                  </NavLink>
                )
                return (
                  <li key={path}>
                    {collapsed ? (
                      <Tooltip content={label} side="right">
                        {link}
                      </Tooltip>
                    ) : (
                      link
                    )}
                  </li>
                )
              })}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}

/** Sidebar header: the CRM puts the collapse control here, beside the product name. */
function SidebarHeader({ collapsed = false, onToggleCollapse }: { collapsed?: boolean; onToggleCollapse?: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  return (
    <div className={cn('flex min-h-16 items-center gap-3 border-b py-3', collapsed ? 'justify-center px-0' : 'px-4')}>
      {onToggleCollapse ? (
        <button
          type="button"
          onClick={onToggleCollapse}
          aria-expanded={!collapsed}
          aria-label={t(collapsed ? 'nav.expand' : 'nav.collapse')}
          className={cn('grid size-9 shrink-0 place-items-center rounded-lg border bg-card text-muted-foreground transition-colors hover:bg-muted hover:text-foreground', FOCUS)}
        >
          <PanelLeft className="size-5 rtl:-scale-x-100" aria-hidden />
        </button>
      ) : (
        <BrandMark />
      )}
      {!collapsed && (
        <Link to={homePath(can)} className={cn('flex min-w-0 items-center gap-2 rounded-md', FOCUS)} aria-label={t('app.name')}>
          {onToggleCollapse && <BrandMark className="size-7" />}
          <BrandName product={t('app.product')} />
        </Link>
      )}
    </div>
  )
}

function ProfileMenu() {
  const { t, i18n } = useTranslation()
  const { me, logout } = useAuth()
  const { theme, setTheme } = useTheme()
  if (!me) return null
  const ar = i18n.language === 'ar'
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className={cn('flex min-h-10 items-center gap-2 rounded-xl border p-1 transition-colors hover:bg-muted lg:ps-1.5 lg:pe-3', FOCUS)}
          aria-label={t('nav.profile')}
        >
          <UserAvatar name={me.name} size="sm" />
          <div className="hidden text-start leading-tight lg:block">
            <div className="text-[13px] font-semibold">{me.name}</div>
            <div className="text-[11px] text-muted-foreground">{ar ? me.role.name_ar : me.role.name}</div>
          </div>
          <ChevronDown className="hidden size-3.5 text-muted-foreground lg:block" aria-hidden />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <div className="flex items-center gap-3 px-2.5 py-2">
          <UserAvatar name={me.name} size="md" />
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold">{me.name}</div>
            <div className="truncate text-xs text-muted-foreground">{me.email}</div>
          </div>
        </div>
        <DropdownMenuSeparator />
        <DropdownMenuItem className="sm:hidden" onSelect={() => i18n.changeLanguage(ar ? 'en' : 'ar')}>
          <Languages /> <span lang={ar ? 'en' : 'ar'}>{ar ? 'English' : 'العربية'}</span>
        </DropdownMenuItem>
        <DropdownMenuItem className="sm:hidden" onSelect={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>
          {theme === 'dark' ? <Sun /> : <Moon />} {t(theme === 'dark' ? 'toggle.light' : 'toggle.dark')}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={() => logout()}>
          <LogOut className="rtl:-scale-x-100" /> {t('auth.logout')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** The CRM's top context bar: menu (below lg), breadcrumb, then preferences and the account menu. */
function Navbar({ onOpenMobile }: { onOpenMobile: () => void }) {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const page = NAV.flatMap(({ group, items }) => items.map((item) => ({ ...item, group })))
    .filter((item) => pathname === item.path || pathname.startsWith(`${item.path}/`))
    .sort((a, b) => b.path.length - a.path.length)[0]
  return (
    <header className="sticky top-0 z-30 flex min-h-16 items-center gap-2 border-b bg-card px-3 py-2 sm:gap-3 sm:px-4">
      <Button variant="ghost" size="icon" className="lg:hidden" onClick={onOpenMobile} aria-label={t('nav.open')}>
        <Menu className="size-5" aria-hidden />
      </Button>
      <nav aria-label={t('nav.breadcrumb')} className="me-auto hidden min-w-0 items-center gap-2 text-table text-muted-foreground md:flex">
        {page && (
          <>
            <span className="truncate">{t(page.group)}</span>
            <span aria-hidden>/</span>
            <span className="truncate text-section-title text-foreground">{t(page.key)}</span>
          </>
        )}
      </nav>
      <div className="flex min-w-0 flex-1 items-center justify-end gap-1.5 sm:gap-2 md:flex-none">
        <div className="hidden sm:block">
          <LanguageToggle />
        </div>
        <div className="hidden sm:block">
          <ThemeToggle />
        </div>
        <ProfileMenu />
      </div>
    </header>
  )
}

/**
 * The CRM application shell:
 *   LTR: [Sidebar] [Top context bar / Page content]
 *   RTL: mirrored by `dir`; the sidebar is the inline-start child of one flex row.
 */
export function AppLayout() {
  const { t, i18n } = useTranslation()
  const { theme } = useTheme()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const { pathname } = useLocation()

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [pathname])

  const rtl = i18n.language === 'ar'

  return (
    <TooltipProvider>
      <div className="flex min-h-dvh bg-background text-foreground">
        <Toaster theme={theme} dir={rtl ? 'rtl' : 'ltr'} position={rtl ? 'top-left' : 'top-right'} richColors closeButton toastOptions={{ className: 'font-sans' }} />
        <a
          href="#page-content"
          className="sr-only rounded-lg border border-input bg-card px-3 py-2 shadow-2 focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:outline-2 focus:outline-offset-2 focus:outline-ring"
        >
          {t('skip')}
        </a>

        <aside
          className={cn(
            'sticky top-0 hidden h-dvh shrink-0 flex-col border-e bg-sidebar transition-[width] duration-200 ease-out lg:flex',
            collapsed ? 'w-[72px]' : 'w-64',
          )}
        >
          <SidebarHeader collapsed={collapsed} onToggleCollapse={() => setCollapsed((c) => !c)} />
          <div className="flex-1 overflow-y-auto overscroll-contain px-2 py-3 scrollbar-thin">
            <SidebarNav collapsed={collapsed} />
          </div>
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          <Navbar onOpenMobile={() => setMobileOpen(true)} />
          <main id="page-content" className="mx-auto w-full max-w-[1600px] flex-1 scroll-mt-20 p-4 sm:p-6">
            <div key={pathname} className="animate-fade-in">
              <Outlet />
            </div>
          </main>
        </div>

        <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
          <SheetContent side="left" className="w-64 p-0">
            <SheetTitle className="sr-only">{t('nav.main')}</SheetTitle>
            <SheetDescription className="sr-only">{t('app.name')}</SheetDescription>
            <SidebarHeader />
            <div className="flex-1 overflow-y-auto overscroll-contain px-2 py-3">
              <SidebarNav onNavigate={() => setMobileOpen(false)} />
            </div>
          </SheetContent>
        </Sheet>
      </div>
    </TooltipProvider>
  )
}
