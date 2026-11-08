import type { ReactNode } from 'react'
import { cn, FOCUS } from '@/lib/utils'

export type StatTone = 'success' | 'warning' | 'destructive' | 'info'
const TONE: Record<StatTone, string> = { success: 'text-success', warning: 'text-warning', destructive: 'text-destructive', info: 'text-info' }
const COMPACT = 'min-h-tap justify-center px-3 py-2'

export type Stat = {
  label: string
  value: ReactNode
  /** One plain line under the value: an "as of" date, a baseline delta ("+3 vs Sep"), a count. */
  detail?: ReactNode
  /** Makes the tile a filter toggle: <button aria-pressed>. A pressed tile gets border-primary + ring-1 (a non-colour cue). */
  select?: { pressed: boolean; onSelect: () => void }
}
export type StatRowProps = {
  /** The row's group name. */
  label: string
  /** The one big number: text-kpi (30 px), nowrap, untoned. `progress` (0..1) draws a bar under it. */
  hero: Stat & { progress?: number }
  /** Secondary tiles, text-lg, nowrap; tone colours the number only, for real categories (rule 9). No icon prop. */
  stats?: (Stat & { tone?: StatTone })[]
  /** `strip`: one bordered block, the hero over a hairline-divided strip of the stats (rows on a phone, one row of columns from md), so
   * no stat sits alone and the hero never stretches. For read-only figures: `select` and `progress` are ignored.
   * `compact`: a short row of filter tiles, label and number on one line: one row from md, below it the hero over the stats 2-up. */
  layout?: 'tiles' | 'strip' | 'compact'
}

function Tile({ stat, figure, progress, className, inline }: { stat: Stat; figure: string; progress?: number; className?: string; inline?: boolean }) {
  const meter = progress !== undefined && (
    <div aria-hidden className={cn('rounded-full bg-muted', inline ? 'mt-1 h-1' : 'mt-3 h-1.5')}>
      <div className="h-full rounded-full bg-primary" style={{ width: `${Math.min(1, Math.max(0, progress)) * 100}%` }} />
    </div>
  )
  const body = inline ? (
    <>
      <span className="flex items-baseline gap-2 whitespace-nowrap">
        <span className="text-xs text-muted-foreground">{stat.label}</span>
        {stat.detail && <span className="text-xs text-muted-foreground">{stat.detail}</span>}
        <span className={cn('ms-auto', figure)}>{stat.value}</span>
      </span>
      {meter}
    </>
  ) : (
    <>
      <div className="text-xs text-muted-foreground">{stat.label}</div>
      <div className={cn('mt-1 whitespace-nowrap', figure)}>{stat.value}</div>
      {stat.detail && <div className="mt-1 text-xs text-muted-foreground">{stat.detail}</div>}
      {meter}
    </>
  )
  // flex-col keeps a stretched button's content at the top, as in a div
  const tile = cn('flex w-full flex-col rounded-xl border bg-card p-4 text-start', className)
  return stat.select ? (
    <button
      type="button"
      aria-pressed={stat.select.pressed}
      onClick={stat.select.onSelect}
      className={cn(tile, FOCUS, 'transition-colors hover:bg-muted/60', stat.select.pressed && 'border-primary ring-1 ring-primary')}
    >
      {body}
    </button>
  ) : (
    <div role="group" className={tile}>
      {body}
    </div>
  )
}

/** One hero and its secondaries (rules 1, 20). Below lg the hero spans the row and the stats sit 2-up under it; from lg one row, the hero two columns wide. */
export function StatRow({ label, hero, stats, layout = 'tiles' }: StatRowProps) {
  if (layout === 'compact')
    return (
      <div role="group" aria-label={label} className="grid grid-cols-2 gap-2 md:grid-flow-col md:auto-cols-fr">
        <Tile inline stat={hero} figure="text-lg leading-tight font-semibold" progress={hero.progress} className={cn(COMPACT, 'col-span-2')} />
        {stats?.map((s) => (
          // an odd stat alone on its phone row takes the whole row
          <Tile inline key={s.label} stat={s} figure={cn('text-base leading-tight font-semibold', s.tone && TONE[s.tone])} className={cn(COMPACT, 'even:last:col-span-2 md:even:last:col-span-1')} />
        ))}
      </div>
    )
  if (layout === 'strip')
    return (
      <div role="group" aria-label={label} className="rounded-xl border bg-card">
        <Tile stat={{ ...hero, select: undefined }} figure="text-kpi" className="border-0" />
        {!!stats?.length && (
          <div className="grid divide-y border-t md:grid-flow-col md:auto-cols-fr md:divide-x md:divide-y-0">
            {stats.map((s) => (
              <div role="group" key={s.label} className="flex items-baseline justify-between gap-4 px-4 py-2.5 md:flex-col md:items-start md:py-3">
                <div className="text-xs text-muted-foreground">{s.label}</div>
                <div className={cn('font-semibold whitespace-nowrap md:text-lg md:leading-tight', s.tone && TONE[s.tone])}>{s.value}</div>
              </div>
            ))}
          </div>
        )}
      </div>
    )
  return (
    <div role="group" aria-label={label} className={cn('grid gap-3', stats?.length && 'lg:grid-flow-col lg:auto-cols-fr')}>
      <Tile stat={hero} figure="text-kpi" progress={hero.progress} className={stats?.length ? 'lg:col-span-2' : undefined} />
      {!!stats?.length && (
        <div className="grid grid-cols-2 gap-3 lg:contents">
          {stats.map((s) => (
            <Tile key={s.label} stat={s} figure={cn('text-lg leading-tight font-semibold', s.tone && TONE[s.tone])} />
          ))}
        </div>
      )}
    </div>
  )
}
