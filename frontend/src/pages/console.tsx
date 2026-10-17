import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, FormDialog, type Row, SelectField, TextField, useCairoTime, useList } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'

const badge = 'inline-block rounded-md border px-2 py-0.5 text-xs'

function useRoleName() {
  const { i18n } = useTranslation()
  return (r: Row | null | undefined) => (r ? (i18n.language === 'ar' ? r.name_ar : r.name) : '—')
}

function AccountDialog({ account, roles, reload }: { account?: Row; roles: Row[]; reload: () => void }) {
  const { t } = useTranslation()
  const roleName = useRoleName()
  const label = t(account ? 'common.edit' : 'console.addAccount')
  return (
    <FormDialog
      title={label}
      trigger={<Button type="button" variant={account ? 'outline' : 'default'}>{label}</Button>}
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
          <SelectField name="role_id" label={t('console.role')} blank options={roles.map((r) => [r.id, roleName(r)])} {...f} />
          {!account && <TextField name="password" label={t('auth.password')} type="password" autoComplete="new-password" {...f} />}
        </>
      )}
    </FormDialog>
  )
}

function PasswordDialog({ account }: { account: Row }) {
  const { t } = useTranslation()
  return (
    <FormDialog
      title={`${t('console.setPassword')}: ${account.name}`}
      trigger={<Button type="button" variant="outline">{t('console.setPassword')}</Button>}
      init={{}}
      submit={(form) => api(`/users/${account.id}/password`, { method: 'PUT', body: { password: form.password } })}
      onDone={() => {}}
      shown={['password']}
    >
      {(f) => <TextField name="password" label={t('auth.password')} type="password" autoComplete="new-password" {...f} />}
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
              <TableHead>{t('console.userAgent')}</TableHead>
              <TableHead>{t('console.lastActivity')}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((s) => (
              <TableRow key={s.id}>
                <TableCell dir="ltr">{s.ip_address ?? '—'}</TableCell>
                <TableCell dir="ltr" className="max-w-xs truncate" title={s.user_agent ?? ''}>{s.user_agent ?? '—'}</TableCell>
                <TableCell>{time(s.last_activity_at)}</TableCell>
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

function SessionsDialog({ account }: { account: Row }) {
  const { t } = useTranslation()
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button type="button" variant="outline">{t('console.sessions')}</Button>
      </DialogTrigger>
      <DialogContent aria-describedby={undefined}>
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
  // ponytail: one 100-account page; there are a handful of accounts
  const { rows, err, reload } = useList('/users?per_page=100')
  const [roles, setRoles] = useState<Row[]>([])
  const [actErr, setActErr] = useState<unknown>()
  useEffect(() => {
    if (manage) api<Row[]>('/roles').then(setRoles).catch(setActErr)
  }, [manage])
  const [busy, setBusy] = useState<string>()
  const toggle = (u: Row) => {
    setActErr(undefined)
    setBusy(u.id)
    api(`/users/${u.id}/${u.is_active ? 'deactivate' : 'reactivate'}`, { method: 'PATCH' }).then(reload).catch(setActErr).finally(() => setBusy(undefined))
  }
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-page-title">{t('page.accounts')}</h1>
        {manage && <AccountDialog roles={roles} reload={reload} />}
      </div>
      <ErrorBanner err={err ?? actErr} shown={[]} />
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('console.name')}</TableHead>
            <TableHead>{t('auth.email')}</TableHead>
            <TableHead>{t('console.role')}</TableHead>
            <TableHead>{t('console.status')}</TableHead>
            {manage && <TableHead />}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((u) => (
            <TableRow key={u.id}>
              <TableCell>{u.name}</TableCell>
              <TableCell dir="ltr" className="text-start">{u.email}</TableCell>
              <TableCell>{roleName(u.role)}</TableCell>
              <TableCell>
                <div className="flex flex-wrap gap-1">
                  <span className={badge}>{t(u.is_active ? 'console.active' : 'console.inactive')}</span>
                  {u.locked_until && new Date(u.locked_until) > new Date() && <span className={`${badge} border-destructive text-destructive`}>{t('console.locked')}</span>}
                </div>
              </TableCell>
              {manage && (
                <TableCell>
                  <div className="flex flex-wrap gap-2">
                    <AccountDialog account={u} roles={roles} reload={reload} />
                    <Button type="button" variant="outline" disabled={busy === u.id} onClick={() => toggle(u)}>{t(u.is_active ? 'console.deactivate' : 'console.reactivate')}</Button>
                    <PasswordDialog account={u} />
                    <SessionsDialog account={u} />
                  </div>
                </TableCell>
              )}
            </TableRow>
          ))}
        </TableBody>
      </Table>
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

/** One row per resource, one checkbox per permission; keyed by role and its grants so a reload resets the ticks. */
function Matrix({ role, perms, editable, onSaved }: { role: Row; perms: Row[]; editable: boolean; onSaved: () => void }) {
  const { t } = useTranslation()
  const granted = () => new Set(perms.filter((p) => role.permissions.includes(p.key)).map((p) => p.id as string))
  const [checked, setChecked] = useState(granted)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const resources = [...new Set(perms.map((p) => p.resource as string))]
  const flip = (id: string) => setChecked((c) => {
    const n = new Set(c)
    if (!n.delete(id)) n.add(id)
    return n
  })
  const save = () => {
    setBusy(true)
    setErr(undefined)
    api(`/roles/${role.id}/permissions`, { method: 'PATCH', body: { permission_ids: [...checked] } }).then(onSaved).catch((e) => { setErr(e); setChecked(granted()) }).finally(() => setBusy(false))
  }
  return (
    <div className="flex flex-col gap-3">
      <ErrorBanner err={err} shown={[]} />
      <Table>
        <TableBody>
          {resources.map((res) => (
            <TableRow key={res}>
              {perms.filter((p) => p.resource === res).map((p) => (
                <TableCell key={p.id}>
                  <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" disabled={!editable} checked={checked.has(p.id)} onChange={() => flip(p.id)} />
                    {`${t(`perm.${p.resource}.${p.action}`)} ${t(`scope.${p.scope}`)}`}
                  </label>
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {editable && (
        <div>
          <Button type="button" disabled={busy} onClick={save}>{t('common.save')}</Button>
        </div>
      )}
    </div>
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
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-page-title">{t('page.roles')}</h1>
        {manage && <RoleDialog reload={load} />}
      </div>
      <ErrorBanner err={err} shown={[]} />
      <div className="flex flex-wrap gap-2">
        {roles.map((r) => (
          <Button key={r.id} type="button" variant={r.id === selected ? 'default' : 'outline'} aria-pressed={r.id === selected} onClick={() => setSelected(r.id)}>
            {roleName(r)}
          </Button>
        ))}
      </div>
      {role ? <Matrix key={`${role.id}:${role.permissions.join()}`} role={role} perms={perms} editable={manage} onSaved={load} /> : <p className="text-sm text-muted-foreground">{t('console.pickRole')}</p>}
    </div>
  )
}
