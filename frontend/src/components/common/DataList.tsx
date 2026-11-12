import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState } from '@/components/common/EmptyState'
import { ErrorBanner } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'

/** One column. Its header is also the label on the phone card, so the card can never lose a label. */
export type Field<T> = {
  header: string
  cell: (row: T) => ReactNode
  /** Money and counts: end-aligned header and cell, nowrap, in both layouts (rule 15). */
  numeric?: boolean
  /** Table layout only: the column shows from this width up. Cards always show it. */
  tableFrom?: 'md' | 'lg' | 'xl'
}
export type DataColumns<T> = {
  /** First table column; the card's heading, with no label. */
  title: { header: string; cell: (row: T) => ReactNode }
  /** Label/value lines on the card; the middle columns of the table. */
  fields?: Field<T>[]
  /** One pill: the last data column in the table, at the top of the card under the heading. */
  status?: { header: string; cell: (row: T) => ReactNode; tableFrom?: Field<T>['tableFrom'] }
  /** The trailing cell (sr-only header `common.actions`); top-end of the card. */
  actions?: (row: T) => ReactNode
  /** The figure the row is about: the last data column under a visible end-aligned header; top-end of the card, labelled. Takes the card spot of `actions`, so pass one or the other. */
  hero?: { header: string; cell: (row: T) => ReactNode }
}
export type DataListProps<T> = DataColumns<T> & {
  /** The table's accessible name. */
  label: string
  /** Already the current page. */
  rows: readonly T[]
  rowKey: (row: T) => string
  loading?: boolean
  /** A danger Alert above the list; suppresses the empty state. */
  error?: unknown
  /** Rule 7: pass `action` only when the page header has no main action. */
  empty?: { icon: LucideIcon; title: string; description?: string; action?: ReactNode }
  /** Each title becomes a link there, stretched over its row or card, so the whole row opens it and it can open in a new tab. */
  to?: (row: T) => string
  /** Router state the link carries, from the row and its index on this page (the list the employee screen steps through); a new tab opens without it. */
  state?: (row: T, i: number) => unknown
  /** Pagination shows only when total > pageSize. */
  page?: { page: number; pageSize: number; total: number; onPageChange: (p: number) => void }
  /** The row a link pointed at (aria-current, accent background). */
  current?: (row: T) => boolean
}

// literal classes so Tailwind sees them
const FROM = { md: 'sm:max-md:hidden', lg: 'sm:max-lg:hidden', xl: 'sm:max-xl:hidden' } as const
const CELL = 'px-4 py-3 align-middle sm:whitespace-nowrap sm:first:ps-5 sm:last:pe-5 max-sm:p-0'
// below sm a row is a card: [title | actions] / [status] / [label … value]*
const CARD = 'max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-2 max-sm:px-4 max-sm:py-3'
// Safari computes `position` on a table row to `static`, so every stretched link would cover the whole table (the last row
// taking every click); clip-path keeps each one inside its own row. The focus ring sits on the row for the same reason.
// ponytail: the clip also cuts anything a cell draws past its row (menus are portaled), and in Safari a row scrolled
// sideways opens only from the part first in view; drop the clip once Safari honours `relative` on a table row.
const LINK_ROW = 'relative [clip-path:inset(0)] has-[td:first-child>a:focus-visible]:outline-2 has-[td:first-child>a:focus-visible]:-outline-offset-2 has-[td:first-child>a:focus-visible]:outline-ring'

/**
 * A list as a table from 640 px up and as one card per row below it (rule 19): one render, switched by CSS. Cards are
 * separated by hairlines inside the page's Card (not a card in a card). Each field cell carries its header as an
 * aria-hidden visible label; explicit roles keep the table semantics Safari drops when display changes.
 * States in order: loading → skeleton, error → Alert, no rows → EmptyState, else rows + Pagination.
 * No selection or sorting; `to` is the only row click; `current` only marks a row. Matrices and the day sheet use ui/table directly.
 */
export function DataList<T>({ label, rows, rowKey, title, fields = [], status, actions, hero, to, state, loading, error, empty, page, current }: DataListProps<T>) {
  const { t } = useTranslation()
  if (loading) return <TableSkeleton rows={8} />
  return (
    <>
      {!!error && (
        <div className="p-4">
          <ErrorBanner err={error} shown={[]} />
        </div>
      )}
      {rows.length ? (
        <Table role="table" aria-label={label} className="max-sm:block">
          <TableHeader role="rowgroup" className="max-sm:sr-only">
            <TableRow role="row">
              <TableHead role="columnheader">{title.header}</TableHead>
              {fields.map((f) => (
                <TableHead role="columnheader" key={f.header} className={cn(f.numeric && 'text-end', f.tableFrom && FROM[f.tableFrom])}>
                  {f.header}
                </TableHead>
              ))}
              {status && <TableHead role="columnheader" className={cn(status.tableFrom && FROM[status.tableFrom])}>{status.header}</TableHead>}
              {hero && <TableHead role="columnheader" className="text-end text-foreground">{hero.header}</TableHead>}
              {actions && (
                <TableHead role="columnheader">
                  <span className="sr-only">{t('common.actions')}</span>
                </TableHead>
              )}
            </TableRow>
          </TableHeader>
          <TableBody role="rowgroup" className="max-sm:block">
            {rows.map((r, i) => (
              <TableRow role="row" key={rowKey(r)} aria-current={current?.(r) || undefined} className={cn(CARD, to && LINK_ROW, current?.(r) && 'bg-accent hover:bg-accent')}>
                <td role="cell" className={cn(CELL, 'sm:whitespace-normal max-sm:col-start-1 max-sm:row-start-1 max-sm:font-medium')}>
                  {to ? (
                    <Link to={to(r)} state={state?.(r, i)} className="outline-none after:absolute after:inset-0 hover:underline">
                      {title.cell(r)}
                    </Link>
                  ) : (
                    title.cell(r)
                  )}
                </td>
                {fields.map((f) => (
                  <td
                    role="cell"
                    key={f.header}
                    className={cn(
                      CELL,
                      'max-sm:col-span-2 max-sm:flex max-sm:items-baseline max-sm:justify-between max-sm:gap-4',
                      f.numeric && 'text-end whitespace-nowrap',
                      f.tableFrom && FROM[f.tableFrom],
                    )}
                  >
                    <span aria-hidden="true" className="shrink-0 text-muted-foreground sm:hidden">
                      {f.header}
                    </span>
                    <div className="min-w-0 [overflow-wrap:anywhere] max-sm:text-end">{f.cell(r)}</div>
                  </td>
                ))}
                {status && (
                  <td role="cell" className={cn(CELL, 'max-sm:col-span-2 max-sm:row-start-2', status.tableFrom && FROM[status.tableFrom])}>
                    {status.cell(r)}
                  </td>
                )}
                {hero && (
                  <td role="cell" className={cn(CELL, 'text-end whitespace-nowrap max-sm:col-start-2 max-sm:row-start-1')}>
                    <span aria-hidden="true" className="block text-xs text-muted-foreground sm:hidden">
                      {hero.header}
                    </span>
                    <div className="font-semibold max-sm:text-2xl">{hero.cell(r)}</div>
                  </td>
                )}
                {actions && (
                  <td role="cell" className={cn(CELL, 'text-end max-sm:col-start-2 max-sm:row-start-1', to && 'relative')}>
                    {actions(r)}
                  </td>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      ) : (
        !error && empty && <EmptyState icon={empty.icon} title={empty.title} description={empty.description} action={empty.action} />
      )}
      {page && page.total > page.pageSize && <Pagination {...page} />}
    </>
  )
}
