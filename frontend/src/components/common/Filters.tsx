import { Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { selectCls } from '@/components/form'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

export function SearchInput({ value, onChange, placeholder, className }: { value: string; onChange: (v: string) => void; placeholder: string; className?: string }) {
  const { t } = useTranslation()
  return (
    <div className={cn('relative w-full sm:w-72', className)}>
      <Search className="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="pe-8 ps-9" aria-label={placeholder} />
      {value && (
        <button type="button" onClick={() => onChange('')} className="absolute top-1/2 end-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:bg-muted" aria-label={t('common.clearSearch')}>
          <X className="size-3.5" />
        </button>
      )}
    </div>
  )
}

export function FilterSelect({
  value, onChange, label, allLabel, options,
}: { value: string; onChange: (v: string) => void; label: string; allLabel: string; options: [string, string][] }) {
  return (
    <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} className={cn(selectCls, 'w-full sm:w-44', value !== '' && 'border-primary/40 bg-primary/5 text-foreground')}>
      <option value="">{allLabel}</option>
      {options.map(([v, l]) => (
        <option key={v} value={v}>
          {l}
        </option>
      ))}
    </select>
  )
}
