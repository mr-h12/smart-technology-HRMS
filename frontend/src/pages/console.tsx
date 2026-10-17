import { UserPlus } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { PageHeader } from '@/components/common/PageHeader'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { UserAvatar } from '@/components/common/UserAvatar'
import { type Controlled, ErrorBanner, FormDialog, type Row, SelectField, TextField, useCairoTime, useList } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'

function useRoleName() {
  const { i18n } = useTranslation()
  return (r: Row | null | undefined) => (r ? (i18n.language === 'ar' ? r.name_ar : r.name) : '—')
}

/** Without role.view the role list is not loaded, so the field stays locked to the account's current role. */
function AccountDialog({ account, roles, reload, ...dialog }: { account?: Row; roles: Row[]; reload: () => void } & Controlled) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const roleName = useRoleName()
  const pick = can('role.view')
  const label = t(account ? 'common.edit' : 'console.addAccount')
  const options = pick ? roles : account?.role ? [account.role] : []
  return (
    <FormDialog
      {...dialog}
      title={label}
      trigger={account ? undefined : <Button type="button"><UserPlus />{label}</Button>}
      init={{ name: account?.name ?? '', email: account?.email ?? '', role_id: account?.role?.id ?? '' }}
      submit={({ name, email, role_id, password }) =>
        account ? api(`/users/${account.id}`, { method: 'PATCH', body: { name, email, role_id } }) : api('/users', { method: 'POST', body: { name, email, role_id, password } })}
      onDone={reload}
      shown={['name', 'email', 'role_id', 'password']}
    >
      {(f) => (
        <>
          <TextField name="name" label={t('console.name')} {...f} />
          <TextField name="email" label={t('auth.email')} type="email" dir="ltr" {...f} />
          <SelectField name="role_id" label={t('console.role')} blank={pick} disabled={!pick} options={options.map((r) => [r.id, roleName(r)])} {...f} />
          {!account && <TextField name="password" label={t('auth.password')} type="password" autoComplete="new-password" {...f} />}
        </>
      )}
    </FormDialog>
  )
}

function PasswordDialog({ account, ...dialog }: { account: Row } & Controlled) {
  const { t } = useTranslation()
  return (
    <FormDialog
      {...dialog}
      title={`${t('console.setPassword')}: ${account.name}`}
      init={{}}
      submit={(form) => api(`/users/${account.id}/password`, { method: 'PUT', body: { password: form.password } })}
      onDone={() => {}}
      shown={['password']}
    >
      {(f) => <TextField name="password" label={t('auth.password')} type="password" dir="ltr" autoComplete="new-password" {...f} />}
    </FormDialog>
  )
}

/** Mounted only while the dialog is open, so it loads on open. */
function SessionList({ account }: { account: Row }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  const [rows, setRows] = useState<Row[]>()
  const [err, setErr] = useState<unknown>()
  const load = useCallback(() => {
    api<Row[]>(`/users/${account.id}/sessions`).then((s) => { setErr(undefined); setRows(s) }).catch(setErr)
  }, [account.id])
  useEffect(load, [load])
  const [busy, setBusy] = useState<string>()
  const revoke = (id: string) => {
    setBusy(id)
    api(`/users/${account.id}/sessions/${id}`, { method: 'DELETE' }).then(load).catch(setErr).finally(() => setBusy(undefined))
  }
  return (
    <>
      <ErrorBanner err={err} shown={[]} />
      {rows?.length === 0 && <p role="status">{t('console.noSessions')}</p>}
      {!!rows?.length && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('console.ip')}</TableHead>
              <TableHead className="hidden sm:table-cell">{t('console.userAgent')}</TableHead>
              <TableHead className="hidden sm:table-cell">{t('console.lastActivity')}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((s) => (
              <TableRow key={s.id}>
                <TableCell dir="ltr">{s.ip_address ?? '—'}</TableCell>
                <TableCell dir="ltr" className="hidden w-full max-w-0 truncate sm:table-cell" title={s.user_agent ?? ''}>{s.user_agent ?? '—'}</TableCell>
                <TableCell className="hidden sm:table-cell">{time(s.last_activity_at)}</TableCell>
                <TableCell>
                  <Button type="button" variant="outline" size="sm" disabled={busy === s.id} onClick={() => revoke(s.id)}>{t('console.revoke')}</Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </>
  )
}

function SessionsDialog({ account, open, onOpenChange }: { account: Row } & Controlled) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined} className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{`${t('console.sessions')}: ${account.name}`}</DialogTitle>
        </DialogHeader>
        <DialogBody>
          <SessionList account={account} />
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}

export function AccountsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const roleName = useRoleName()
  const manage = can('user.manage')
  const viewRoles = can('role.view')
  // ponytail: one 100-account page; there are a handful of accounts
  const { rows, err, loading, reload } = useList('/users?per_page=100')
  const [roles, setRoles] = useState<Row[]>([])
  const [actErr, setActErr] = useState<unknown>()
  useEffect(() => {
    if (manage && viewRoles) api<Row[]>('/roles').then(setRoles).catch(setActErr)
  }, [manage, viewRoles])
  const [dlg, setDlg] = useState<{ type: 'edit' | 'password' | 'sessions'; row: Row }>()
  const close = { open: true, onOpenChange: () => setDlg(undefined) }
  const toggle = (u: Row) => {
    setActErr(undefined)
    api(`/users/${u.id}/${u.is_active ? 'deactivate' : 'reactivate'}`, { method: 'PATCH' }).then(reload).catch(setActErr)
  }
  const now = new Date()
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.accounts')} description={t('console.accountsSubtitle')} actions={manage && viewRoles && <AccountDialog roles={roles} reload={reload} />} />
      <ErrorBanner err={err ?? actErr} shown={[]} />
      <Card className="overflow-hidden">
        {loading ? (
          <TableSkeleton />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('console.user')}</TableHead>
                <TableHead className="hidden md:table-cell">{t('auth.email')}</TableHead>
                <TableHead className="hidden sm:table-cell">{t('console.role')}</TableHead>
                <TableHead>{t('console.status')}</TableHead>
                {manage && (
                  <TableHead>
                    <span className="sr-only">{t('common.actions')}</span>
                  </TableHead>
                )}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((u) => (
                <TableRow key={u.id}>
                  <TableCell className="whitespace-normal">
                    <div className="flex items-center gap-3">
                      <UserAvatar name={u.name} />
                      <div className="min-w-0">
                        <div className="font-medium">{u.name}</div>
                        <div className="mt-1 sm:hidden">
                          <Badge variant="neutral">{roleName(u.role)}</Badge>
                        </div>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell dir="ltr" className="hidden text-start text-muted-foreground md:table-cell">{u.email}</TableCell>
                  <TableCell className="hidden sm:table-cell">
                    <Badge variant="neutral">{roleName(u.role)}</Badge>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1">
                      <StatusBadge variant={u.is_active ? 'success' : 'neutral'} label={t(u.is_active ? 'console.active' : 'console.inactive')} />
                      {u.locked_until && new Date(u.locked_until) > now && <StatusBadge variant="danger" label={t('console.locked')} />}
                    </div>
                  </TableCell>
                  {manage && (
                    <TableCell className="text-end">
                      <RowActions
                        items={[
                          { label: t('common.edit'), onSelect: () => setDlg({ type: 'edit', row: u }) },
                          { label: t('console.setPassword'), onSelect: () => setDlg({ type: 'password', row: u }) },
                          { label: t(u.is_active ? 'console.deactivate' : 'console.reactivate'), onSelect: () => toggle(u), destructive: u.is_active },
                          { label: t('console.sessions'), onSelect: () => setDlg({ type: 'sessions', row: u }) },
                        ]}
                      />
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
      {dlg?.type === 'edit' && <AccountDialog account={dlg.row} roles={roles} reload={reload} {...close} />}
      {dlg?.type === 'password' && <PasswordDialog account={dlg.row} {...close} />}
      {dlg?.type === 'sessions' && <SessionsDialog account={dlg.row} {...close} />}
    </div>
  )
}

function RoleDialog({ reload }: { reload: () => void }) {
  const { t } = useTranslation()
  return (
    <FormDialog
      title={t('console.addRole')}
      trigger={<Button type="button">{t('console.addRole')}</Button>}
      init={{}}
      submit={({ slug, name, name_ar }) => api('/roles', { method: 'POST', body: { slug, name, name_ar } })}
      onDone={reload}
      shown={['slug', 'name', 'name_ar']}
    >
      {(f) => (
        <>
          <TextField name="slug" label={t('console.slug')} dir="ltr" {...f} />
          <TextField name="name_ar" label={t('employee.name_ar')} {...f} />
          <TextField name="name" label={t('employee.name_en')} {...f} />
        </>
      )}
    </FormDialog>
  )
}

const ACTIONS = ['view', 'create', 'edit', 'delete', 'assign_manager', 'manage']

/** One row per resource, one column per action; keyed by role and its grants so a saved role resets the controls. */
function Matrix({ role, perms, editable, onSaved }: { role: Row; perms: Row[]; editable: boolean; onSaved: (role: Row) => void }) {
  const { t } = useTranslation()
  const roleName = useRoleName()
  const granted = () => new Set(perms.filter((p) => role.permissions.includes(p.key)).map((p) => p.id as string))
  const [checked, setChecked] = useState(granted)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const resources = [...new Set(perms.map((p) => p.resource as string))]
  const actions = ACTIONS.filter((a) => perms.some((p) => p.action === a))
  const initial = granted()
  const dirty = checked.size !== initial.size || [...checked].some((id) => !initial.has(id))
  /** Grants `id` (or nothing) in place of every id in `ids`. */
  const pick = (ids: string[], id?: string) => setChecked((c) => {
    const n = new Set(c)
    ids.forEach((x) => n.delete(x))
    if (id) n.add(id)
    return n
  })
  const save = () => {
    setBusy(true)
    setErr(undefined)
    api<Row>(`/roles/${role.id}/permissions`, { method: 'PATCH', body: { permission_ids: [...checked] } })
      .then((r) => { toast.success(t('common.saved')); onSaved(r) })
      .catch((e) => { setErr(e); setChecked(granted()) })
      .finally(() => setBusy(false))
  }
  const cell = (res: string, action: string) => {
    const label = t(`perm.${res}.${action}`)
    const ps = perms.filter((p) => p.resource === res && p.action === action)
    const own = ps.find((p) => p.scope === 'own')
    const all = ps.find((p) => p.scope === 'all')
    if (own && all) {
      const value = checked.has(all.id) ? 'all' : checked.has(own.id) ? 'own' : 'none'
      return (
        <div role="radiogroup" aria-label={label} className="inline-flex rounded-lg border bg-muted p-0.5 text-muted-foreground">
          {([['none', undefined], ['own', own.id], ['all', all.id]] as const).map(([s, id]) => (
            <label key={s}>
              <input type="radio" className="peer sr-only" name={`${res}.${action}`} disabled={!editable} checked={value === s} onChange={() => pick([own.id, all.id], id)} />
              <span className="inline-flex h-7 items-center rounded-md px-2.5 text-xs peer-checked:bg-card peer-checked:text-foreground peer-checked:shadow-1 peer-focus-visible:outline-2 peer-focus-visible:outline-ring">{t(`perm.scope.${s}`)}</span>
            </label>
          ))}
        </div>
      )
    }
    if (ps.length === 1) {
      const id = ps[0].id as string
      return <input type="checkbox" aria-label={label} className="size-4 accent-primary" disabled={!editable} checked={checked.has(id)} onChange={() => pick([id], checked.has(id) ? undefined : id)} />
    }
    return <span className="text-muted-foreground">—</span>
  }
  return (
    <Card className="min-w-0 overflow-hidden">
      <CardHeader>
        <CardTitle>{t('console.matrixTitle', { role: roleName(role) })}</CardTitle>
      </CardHeader>
      {!!err && (
        <div className="px-5 pb-3">
          <ErrorBanner err={err} shown={[]} />
        </div>
      )}
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead className="sticky start-0 bg-muted">{t('console.resource')}</TableHead>
            {actions.map((a) => <TableHead key={a}>{t(`perm.action.${a}`)}</TableHead>)}
          </TableRow>
        </TableHeader>
        <TableBody>
          {resources.map((res) => (
            <TableRow key={res} className="group hover:bg-muted">
              <TableCell className="sticky start-0 bg-card font-medium group-hover:bg-muted">{t(`perm.resource.${res}`)}</TableCell>
              {actions.map((a) => <TableCell key={a}>{cell(res, a)}</TableCell>)}
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {editable && (
        <CardFooter className="justify-end gap-3 bg-muted/40">
          <Button type="button" disabled={!dirty || busy} onClick={save}>{t('common.saveChanges')}</Button>
        </CardFooter>
      )}
    </Card>
  )
}

export function RolesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const roleName = useRoleName()
  const manage = can('role.manage')
  const [roles, setRoles] = useState<Row[]>([])
  const [perms, setPerms] = useState<Row[]>([])
  const [selected, setSelected] = useState<string>()
  const [err, setErr] = useState<unknown>()
  const load = useCallback(() => {
    Promise.all([api<Row[]>('/roles'), api<Row[]>('/permissions')])
      .then(([r, p]) => { setErr(undefined); setRoles(r); setPerms(p) })
      .catch(setErr)
  }, [])
  useEffect(load, [load])
  const role = roles.find((r) => r.id === selected)
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.roles')} description={t('console.rolesSubtitle')} actions={manage && <RoleDialog reload={load} />} />
      <ErrorBanner err={err} shown={[]} />
      <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <Card className="flex flex-col gap-1 self-start p-2">
          {roles.map((r) => (
            <button key={r.id} type="button" aria-pressed={r.id === selected} onClick={() => setSelected(r.id)} className="w-full rounded-lg border-s-[3px] border-transparent px-3 py-2.5 text-start hover:bg-muted aria-pressed:border-primary aria-pressed:bg-muted">
              <div className="font-medium">{roleName(r)}</div>
              <div className="font-mono text-xs text-muted-foreground"><span dir="ltr">{r.slug}</span></div>
              <div className="text-xs text-muted-foreground">{t('console.usersCount', { count: r.users_count })}</div>
            </button>
          ))}
        </Card>
        {role ? (
          <Matrix key={`${role.id}:${role.permissions.join()}`} role={role} perms={perms} editable={manage} onSaved={(saved) => setRoles((rs) => rs.map((r) => (r.id === saved.id ? saved : r)))} />
        ) : (
          <Card className="p-5 text-sm text-muted-foreground">{t('console.pickRole')}</Card>
        )}
      </div>
    </div>
  )
}
