import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

export function TitlePage({ titleKey }: { titleKey: string }) {
  const { t } = useTranslation()
  return <h1 className="text-page-title">{t(titleKey)}</h1>
}

export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-start gap-3">
      <h1 className="text-page-title">{t('notFound.title')}</h1>
      <Link to="/employees" className="text-link underline">
        {t('notFound.back')}
      </Link>
    </div>
  )
}
