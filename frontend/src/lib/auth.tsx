import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router-dom'
import { api, getToken, setToken, setUnauthorizedHandler } from './api'

export type Me = { id: string; name: string; email: string; role: { id: string; slug: string; name: string; name_ar: string }; permissions: string[] }

type Auth = {
  me: Me | null
  loading: boolean
  login(email: string, password: string): Promise<void>
  logout(): Promise<void>
  can(ability: string): boolean
}

/** `resource.action` matches any scope; `resource.action.scope` is exact. */
export const canDo = (permissions: string[], ability: string) => permissions.some((p) => p === ability || p.startsWith(`${ability}.`))

export const AuthContext = createContext<Auth>({ me: null, loading: false, login: async () => {}, logout: async () => {}, can: () => false })

export const useAuth = () => useContext(AuthContext)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null)
  const [loading, setLoading] = useState(() => getToken() !== null)

  useEffect(() => {
    // a 401 has already cleared the token; with me null, RequireAuth sends the user to /login
    setUnauthorizedHandler(() => setMe(null))
    return () => setUnauthorizedHandler(() => {})
  }, [])

  useEffect(() => {
    const token = getToken()
    if (token === null) return
    api<Me>('/auth/me')
      .then((m) => getToken() === token && setMe(m), () => {}) // a sign-in during the request wins
      .finally(() => setLoading(false))
  }, [])

  const value: Auth = {
    me,
    loading,
    login: async (email, password) => {
      const r = await api<{ token: string; user: Me }>('/auth/login', { method: 'POST', body: { email, password } })
      setToken(r.token)
      setMe(r.user)
    },
    logout: async () => {
      await api('/auth/logout', { method: 'POST' }).catch(() => {})
      setToken(null)
      setMe(null)
    },
    can: (ability) => canDo(me?.permissions ?? [], ability),
  }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function RequireAuth({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const { me, loading } = useAuth()
  if (getToken() === null || (!me && !loading)) return <Navigate to="/login" replace />
  if (!me) return <p className="p-6 text-muted-foreground" role="status">{t('auth.loading')}</p>
  return children
}
