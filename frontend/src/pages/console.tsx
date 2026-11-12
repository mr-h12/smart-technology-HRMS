import { UserPlus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { UserAvatar } from '@/components/common/UserAvatar'
import { type Controlled, ErrorBanner, FormDialog, type Row, SelectField, TextField, useCairoTime, useRoleName } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { USERS, useApi, useList, useSave } from '@/lib/query'

/**
 * Another account's password, shown as typed. A type=password + new-password field gets Safari's generated strong password
 * in place of what the developer types, so the account got a password nobody knew.
 */
const noGenerator = { type: 'text', dir: 'ltr', autoComplete: 'off', autoCapitalize: 'off', autoCorrect: 'off', spellCheck: false } as const

/** `roles` are the ones the signed-in user may give: the server leaves out any holding a permission they lack. */
function AccountDialog({ account, roles, ...dialog }: { account?: Row; roles: Row[] } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  const roleName = useRoleName()
  const label = t(account ? 'common.edit' : 'console.addAccount')
  return (
    <FormDialog
      {...dialog}
      title={label}
      init={{ name: account?.name ?? '', email: account?.email ?? '', role_id: account?.role?.id ?? '' }}
      submit={({ name, email, role_id, password }) =>
        account ? send(`/users/${account.id}`, { method: 'PATCH', body: { name, email, role_id } }) : send('/users', { body: { name, email, role_id, password } })}
      onDone={() => {}}
      shown={['name', 'email', 'role_id', 'password']}
    >
      {(f) => (
        <>
          <TextField name="name" label={t('console.name')} {...f} />
          <TextField name="email" label={t('auth.email')} type="email" dir="ltr" {...f} />
          <SelectField name="role_id" label={t('console.role')} blank options={roles.map((r) => [r.id, roleName(r)])} {...f} />
          {!account && <TextField name="password" label={t('auth.password')} {...noGenerator} {...f} />}
        </>
      )}
    </FormDialog>
  )
}

function PasswordDialog({ account, ...dialog }: { account: Row } & Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  return (
    <FormDialog
      {...dialog}
      title={`${t('console.setPassword')}: ${account.name}`}
      init={{}}
      submit={(form) => send(`/users/${account.id}/password`, { method: 'PUT', body: { password: form.password } })}
      onDone={() => {}}
      shown={['password']}
    >
      {(f) => <TextField name="password" label={t('auth.password')} {...noGenerator} {...f} />}
    </FormDialog>
  )
}

/** Confirming swaps the session to `account` and goes to `/`, so that account's landing page applies. */
function ViewAsDialog({ account, ...dialog }: { account: Row } & Controlled) {
  const { t } = useTranslation()
  const { viewAs } = useAuth()
  const navigate = useNavigate()
  return (
    <FormDialog
      {...dialog}
      title={`${t('console.viewAs')}: ${account.name}`}
      description={t('console.viewAsHint')}
      init={{}}
      submit={({ reason }) => viewAs(account.id, reason)}
      onDone={() => navigate('/')}
      shown={['reason']}
      footer="console.viewAs"
    >
      {(f) => <TextField name="reason" label={t('console.viewAsReason')} maxLength={500} {...f} />}
    </FormDialog>
  )
}

/** Mounted only while the dialog is open, so it loads on open. */
function SessionList({ account }: { account: Row }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  const send = useSave()
  const { data: rows, err: loadErr } = useApi<Row[]>(`/users/${account.id}/sessions`)
  const [revokeErr, setRevokeErr] = useState<unknown>()
  const err = loadErr ?? revokeErr
  const [busy, setBusy] = useState<string>()
  const revoke = (id: string) => {
    setBusy(id)
    setRevokeErr(undefined)
    send(`/users/${account.id}/sessions/${id}`, { method: 'DELETE' }).catch(setRevokeErr).finally(() => setBusy(undefined))
  }
  return (
    <>
      <ErrorBanner err={err} shown={[]} />
      {rows?.length === 0 && <p role="status">{t('console.noSessions')}</p>}
      {!!rows?.length && (
        <DataList
          label={t('console.sessions')}
          rows={rows}
          rowKey={(s) => s.id}
          title={{ header: t('console.ip'), cell: (s) => <bdi dir="ltr">{s.ip_address ?? '—'}</bdi> }}
          fields={[
            // the table's cells are nowrap: a capped width keeps a long agent from widening the dialog; the title holds the full text
            { header: t('console.userAgent'), cell: (s) => <div dir="ltr" className="truncate sm:max-w-60" title={s.user_agent ?? ''}>{s.user_agent ?? '—'}</div> },
            { header: t('console.lastActivity'), cell: (s) => time(s.last_activity_at) },
          ]}
          actions={(s) => <Button type="button" variant="outline" size="sm" disabled={busy === s.id} onClick={() => revoke(s.id)}>{t('console.revoke')}</Button>}
        />
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
  const { me, can } = useAuth()
  const roleName = useRoleName()
  const manage = can('user.manage')
  const impersonate = can('user.impersonate')
  // ponytail: one 100-account page; there are a handful of accounts
  const { rows, err, loading } = useList(USERS)
  const send = useSave()
  const { data: roles = [], err: rolesErr } = useApi<Row[]>(manage ? '/users/assignable-roles' : null)
  const [actErr, setActErr] = useState<unknown>()
  const [dlg, setDlg] = useState<{ type: 'add' } | { type: 'edit' | 'password' | 'sessions' | 'viewAs'; row: Row }>()
  const close = { open: true, onOpenChange: () => setDlg(undefined) }
  const toggle = (u: Row) => {
    setActErr(undefined)
    send(`/users/${u.id}/${u.is_active ? 'deactivate' : 'reactivate'}`, { method: 'PATCH' }).catch(setActErr)
  }
  const now = new Date()
  return (
    <div className="space-y-6">
      <PageHeader
        title={t('page.accounts')}
        description={t('console.accountsSubtitle')}
        action={manage ? { label: t('console.addAccount'), icon: UserPlus, onClick: () => setDlg({ type: 'add' }) } : undefined}
      />
      <ErrorBanner err={err ?? rolesErr ?? actErr} shown={[]} />
      <Card className="overflow-hidden">
        <DataList
          label={t('page.accounts')}
          rows={rows}
          rowKey={(u) => u.id}
          loading={loading}
          title={{
            header: t('console.user'),
            cell: (u) => (
              <div className="flex items-center gap-3 whitespace-normal">
                <UserAvatar name={u.name} />
                <span className="min-w-0 font-medium">{u.name}</span>
              </div>
            ),
          }}
          fields={[
            { header: t('auth.email'), cell: (u) => <bdi dir="ltr" className="text-muted-foreground">{u.email}</bdi>, tableFrom: 'md' },
            { header: t('console.role'), cell: (u) => <Badge variant="neutral">{roleName(u.role)}</Badge> },
          ]}
          status={{
            header: t('console.status'),
            cell: (u) => (
              <div className="flex flex-wrap gap-1">
                <StatusBadge variant={u.is_active ? 'success' : 'neutral'} label={t(u.is_active ? 'console.active' : 'console.inactive')} />
                {u.locked_until && new Date(u.locked_until) > now && <StatusBadge variant="danger" label={t('console.locked')} />}
              </div>
            ),
          }}
          actions={manage || impersonate ? (u) => (
            <RowActions
              items={[
                ...(manage && u.manageable ? [ // false on an account whose role holds a permission the signed-in user lacks
                  { label: t('common.edit'), onSelect: () => setDlg({ type: 'edit', row: u }) },
                  { label: t('console.setPassword'), onSelect: () => setDlg({ type: 'password', row: u }) },
                  { label: t(u.is_active ? 'console.deactivate' : 'console.reactivate'), onSelect: () => toggle(u), destructive: u.is_active },
                  { label: t('console.sessions'), onSelect: () => setDlg({ type: 'sessions', row: u }) },
                ] : []),
                ...(impersonate && u.is_active && u.id !== me?.id ? [{ label: t('console.viewAs'), onSelect: () => setDlg({ type: 'viewAs', row: u }) }] : []),
              ]}
            />
          ) : undefined}
        />
      </Card>
      {dlg?.type === 'add' && <AccountDialog roles={roles} {...close} />}
      {dlg?.type === 'edit' && <AccountDialog account={dlg.row} roles={roles} {...close} />}
      {dlg?.type === 'password' && <PasswordDialog account={dlg.row} {...close} />}
      {dlg?.type === 'sessions' && <SessionsDialog account={dlg.row} {...close} />}
      {dlg?.type === 'viewAs' && <ViewAsDialog account={dlg.row} {...close} />}
    </div>
  )
}

function RoleDialog(dialog: Controlled) {
  const { t } = useTranslation()
  const send = useSave()
  return (
    <FormDialog
      {...dialog}
      title={t('console.addRole')}
      init={{}}
      submit={({ slug, name, name_ar }) => send('/roles', { body: { slug, name, name_ar } })}
      onDone={() => {}}
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

const ACTIONS = ['view', 'create', 'edit', 'edit_code', 'delete', 'assign_manager', 'manage', 'lock']

/** One row per resource, one column per action; keyed by role and its grants so a saved role resets the controls. */
function Matrix({ role, perms, editable }: { role: Row; perms: Row[]; editable: boolean }) {
  const { t } = useTranslation()
  const send = useSave()
  const roleName = useRoleName()
  const granted = () => new Set(perms.filter((p) => role.permissions.includes(p.key)).map((p) => p.id as string))
  const [checked, setChecked] = useState(granted)
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const resources = [...new Set(perms.map((p) => p.resource as string))]
  // known actions in their usual order, then any other action the server seeds
  const actions = [...new Set([...ACTIONS, ...perms.map((p) => p.action as string)])].filter((a) => perms.some((p) => p.action === a))
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
    send(`/roles/${role.id}/permissions`, { method: 'PATCH', body: { permission_ids: [...checked] } })
      .then(() => toast.success(t('common.saved')))
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
        <SegmentedControl
          label={label}
          value={value}
          disabled={!editable || busy}
          className="inline-flex w-max"
          onChange={(s) => pick([own.id, all.id], { none: undefined, own: own.id, all: all.id }[s])}
          // the visible text is aria-hidden: the option's sr-only label already names it
          options={(['none', 'own', 'all'] as const).map((s) => ({ value: s, label: t(`perm.scope.${s}`), content: <span aria-hidden className="px-1.5 whitespace-nowrap">{t(`perm.scope.${s}`)}</span> }))}
        />
      )
    }
    if (ps.length === 1) {
      const id = ps[0].id as string
      return (
        <label className="inline-grid min-h-tap min-w-tap place-items-center">
          <input type="checkbox" aria-label={label} className="size-4" disabled={!editable || busy} checked={checked.has(id)} onChange={() => pick([id], checked.has(id) ? undefined : id)} />
        </label>
      )
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
  const view = can('role.view') // nothing is fetched without it
  const [picked, setPicked] = useState<string>()
  // xl is where the list and the matrix sit side by side; there the first role opens by default, on narrower screens nothing does
  const [wide] = useState(() => window.matchMedia?.('(min-width: 80rem)').matches ?? false)
  const [adding, setAdding] = useState(false)
  const { data: roles = [], err: rolesErr, loading } = useApi<Row[]>(view ? '/roles' : null)
  const { data: perms, err: permsErr, loading: permsLoading } = useApi<Row[]>(view ? '/permissions' : null)
  const err = rolesErr ?? permsErr
  const selected = picked ?? (wide ? roles[0]?.id : undefined)
  const role = roles.find((r) => r.id === selected)
  // a wide screen counts as having a role open while it loads, so the header and the body never flip once the default arrives
  const pending = loading || permsLoading
  const open = !!role || (wide && pending)
  // while a role is open the matrix's Save changes is the screen's one solid button
  const add = manage ? { label: t('console.addRole'), onClick: () => setAdding(true) } : undefined
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.roles')} description={t('console.rolesSubtitle')} action={open ? undefined : add} secondary={open && add ? [add] : undefined} />
      {adding && <RoleDialog open onOpenChange={setAdding} />}
      <ErrorBanner err={err} shown={[]} />
      <div className="grid gap-6 xl:grid-cols-[18rem_1fr]">
        <Card className="flex flex-col gap-1 self-start p-2">
          {loading && <TableSkeleton rows={4} />}
          {roles.map((r) => (
            <button key={r.id} type="button" aria-pressed={r.id === selected} onClick={() => setPicked(r.id)} className="w-full rounded-lg border-s-[3px] border-transparent px-3 py-2.5 text-start hover:bg-muted aria-pressed:border-primary aria-pressed:bg-muted">
              <div className="font-medium">{roleName(r)}</div>
              <div className="font-mono text-xs text-muted-foreground"><span dir="ltr">{r.slug}</span></div>
              <div className="text-xs text-muted-foreground">{t('console.usersCount', { count: r.users_count })}</div>
            </button>
          ))}
        </Card>
        {/* Matrix seeds its checks once from perms, so it mounts only after they load: empty perms would save as "grant nothing" */}
        {role && perms ? (
          <Matrix key={`${role.id}:${role.permissions.join()}`} role={role} perms={perms} editable={manage} />
        ) : open && pending ? (
          <Card className="min-w-0 overflow-hidden">
            <TableSkeleton />
          </Card>
        ) : (
          <Card className="p-5 text-sm text-muted-foreground">{t('console.pickRole')}</Card>
        )}
      </div>
    </div>
  )
}
