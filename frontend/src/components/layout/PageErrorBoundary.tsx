import { Component, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { Button } from '@/components/ui/button'

function PageError() {
  const { t } = useTranslation()
  return (
    <Alert tone="danger" title={t('pageError.title')}>
      <p>{t('pageError.body')}</p>
      <Button type="button" variant="outline" onClick={() => window.location.reload()}>
        {t('pageError.reload')}
      </Button>
    </Alert>
  )
}

export class PageErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    return this.state.failed ? <PageError /> : this.props.children
  }
}
