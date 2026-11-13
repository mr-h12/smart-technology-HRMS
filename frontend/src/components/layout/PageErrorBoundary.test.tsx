import { render, screen } from '@testing-library/react'
import { expect, test, vi } from 'vitest'
import { PageErrorBoundary } from './PageErrorBoundary'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

function BrokenPage(): never {
  throw new Error('Simulated page error')
}

test('a page render failure preserves the surrounding UI and navigation resets the boundary', () => {
  const { rerender } = render(
    <>
      <nav>Application menu</nav>
      <PageErrorBoundary key="first"><BrokenPage /></PageErrorBoundary>
    </>,
    { onCaughtError: () => {} },
  )

  expect(screen.getByRole('navigation')).toHaveTextContent('Application menu')
  expect(screen.getByRole('alert')).toHaveTextContent('pageError.title')
  expect(screen.getByRole('button', { name: 'pageError.reload' })).toBeEnabled()

  rerender(
    <>
      <nav>Application menu</nav>
      <PageErrorBoundary key="next"><h1>Next page</h1></PageErrorBoundary>
    </>,
  )

  expect(screen.getByRole('heading', { name: 'Next page' })).toBeVisible()
  expect(screen.queryByRole('alert')).toBeNull()
})
