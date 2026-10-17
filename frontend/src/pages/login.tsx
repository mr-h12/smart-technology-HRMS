import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router-dom'
import { ErrorBanner, TextField, useForm } from '@/components/form'
import { BrandMark, BrandName, LanguageToggle, ThemeToggle } from '@/components/layout/AppLayout'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
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
      <header className="flex justify-end gap-2 p-3 sm:p-4">
        <LanguageToggle />
        <ThemeToggle />
      </header>
      <main className="flex flex-1 items-start justify-center px-4 pt-[10vh]">
        <Card className="w-full max-w-sm">
          <form onSubmit={submit}>
            <CardContent className="space-y-5 p-6">
              <div className="flex items-center gap-3">
                <BrandMark className="size-9" />
                <BrandName product={t('app.product')} />
              </div>
              <h1 className="text-section-title">{t('auth.title')}</h1>
              <ErrorBanner err={error && t(`auth.error.${error}`, { defaultValue: t('auth.error.unknown') })} shown={[]} />
              <TextField name="email" label={t('auth.email')} values={form} set={set} errs={{}} type="email" dir="ltr" autoComplete="username" required />
              <TextField name="password" label={t('auth.password')} values={form} set={set} errs={{}} type="password" dir="ltr" autoComplete="current-password" required />
              <Button type="submit" className="w-full" disabled={busy}>
                {t('auth.submit')}
              </Button>
            </CardContent>
          </form>
        </Card>
      </main>
    </div>
  )
}
