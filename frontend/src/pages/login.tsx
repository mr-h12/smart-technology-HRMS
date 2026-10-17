import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router-dom'
import logoMark from '@/assets/logo-mark.png'
import { ErrorBanner, TextField, useForm } from '@/components/form'
import { LanguageToggle } from '@/components/layout/AppLayout'
import { Button } from '@/components/ui/button'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'

export function LoginPage() {
  const { t } = useTranslation()
  const { me, login } = useAuth()
  const { form, set } = useForm()
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(form.email ?? '', form.password ?? '') // sets me: the Navigate below takes over
    } catch (err) {
      setError(err instanceof ApiError ? err.code : 'unknown')
      setBusy(false)
    }
  }
  if (me) return <Navigate to="/" replace />

  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <header className="flex justify-end p-3 sm:p-4">
        <LanguageToggle />
      </header>
      <main className="flex flex-1 items-start justify-center px-4 pt-[10vh]">
        <form onSubmit={submit} className="flex w-full max-w-sm flex-col gap-4 rounded-xl border bg-card p-6 shadow-1">
          <div className="flex items-center gap-3">
            <img src={logoMark} alt="" className="size-8 object-contain dark:rounded-md dark:bg-white dark:p-0.5" draggable={false} />
            <h1 className="text-[15px] font-semibold">{t('app.name')}</h1>
          </div>
          <ErrorBanner err={error && t(`auth.error.${error}`, { defaultValue: t('auth.error.unknown') })} shown={[]} />
          <TextField name="email" label={t('auth.email')} values={form} set={set} errs={{}} type="email" dir="ltr" autoComplete="username" required />
          <TextField name="password" label={t('auth.password')} values={form} set={set} errs={{}} type="password" dir="ltr" autoComplete="current-password" required />
          <Button type="submit" disabled={busy}>
            {t('auth.submit')}
          </Button>
        </form>
      </main>
    </div>
  )
}
