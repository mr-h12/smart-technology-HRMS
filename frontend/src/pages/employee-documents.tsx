import { FileText, Upload } from 'lucide-react'
import { Fragment, useEffect, useState } from 'react'
import type { TFunction } from 'i18next'
import { Trans, useTranslation } from 'react-i18next'
import { DataList } from '@/components/common/DataList'
import { RowActions } from '@/components/common/RowActions'
import { cairoToday, ErrorBanner, Field, FormDialog, intlLocale, type Row, SelectField, TextField, toErrs, useForm } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/misc'
import { ApiError, apiBlob } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

const DOC_KINDS = ['national_id_copy', 'work_stub', 'criminal_record', 'qualification', 'birth_certificate', 'insurance_form_6', 'photos', 'military_status', 'other']
/** The only types the app accepts, uploads and renders, with their file extension (the server's EmployeeDocument::EXT; it checks the bytes against the same list). */
const EXT: Record<string, string> = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'application/pdf': 'pdf' }
const ACCEPT = Object.keys(EXT)
const MB = 1024 * 1024
const MAX_BYTES = 10 * MB // EmployeeDocumentController::MAX_KB
const SHRINK_OVER = 1.5 * MB
// ponytail: iOS Safari can kill the tab decoding a huge image instead of throwing; above this the size check refuses it unshrunk
const SHRINK_UNDER = 40 * MB
const EXPIRY_SOON_DAYS = 30 // until Release 3 makes it a setting: the lead time for ID and document expiry (docs/hrms-module-plan.md)
/** The one number format for every file size the tab shows. */
const sizeFormat = (lng: string) => new Intl.NumberFormat(intlLocale(lng), { maximumFractionDigits: 1 })
const isHeic = (f: File) => /^image\/hei[cf](-sequence)?$/.test(f.type) || /\.hei[cf]$/i.test(f.name)
/** The browser's type, or (when the OS gave none) the one its extension names. */
const typeOf = (f: File) => f.type || ACCEPT.find((m) => f.name.toLowerCase().replace(/\.jpeg$/, '.jpg').endsWith(`.${EXT[m]}`)) || ''

/** A big phone photo as a JPEG of at most 2400 px on its long edge (transparency on white); the original when that is not smaller or anything fails. */
async function shrinkPhoto(file: File): Promise<File> {
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const scale = Math.min(1, 2400 / Math.max(bmp.width, bmp.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bmp.width * scale)
    canvas.height = Math.round(bmp.height * scale)
    const ctx = canvas.getContext('2d')!
    ctx.fillStyle = '#fff' // JPEG has no alpha: a PNG's transparent parts would turn black
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height)
    bmp.close()
    const blob = await new Promise<Blob | null>((done) => canvas.toBlob(done, 'image/jpeg', 0.85))
    return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

/** The trailing word of a list separator (ar « و», en ", and "), kept with the item after it; punctuation stays behind. */
const GLUE = /\p{L}+\s*$/u
const kindName = (t: TFunction, kind: string) => t(`enum.document.${kind}`)
const kindOptions = (t: TFunction): [string, string][] => DOC_KINDS.map((k) => [k, kindName(t, k)])

type Preset = { kind?: string; replaces?: Doc } | null
/** A document row from `GET /employees/{id}`: only id, kind and expiry without `employee_document.view`. */
type Doc = { id: string; kind: string; expires_at: string | null } & Partial<{ original_name: string; mime: string; size_bytes: number; note: string | null; created_at: string; uploader_name: string | null }>

/** The employee's documents (API order: kind, then newest); editors upload, and see which kinds are still missing. `readOnly` (archived): open and download only. */
export function Documents({ emp, readOnly = false }: { emp: Row; readOnly?: boolean }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [preset, setPreset] = useState<Preset>(null)
  const [dlg, setDlg] = useState<{ type: 'edit' | 'delete'; doc: Doc } | null>(null)
  const [viewing, setViewing] = useState<Doc | null>(null)
  const send = useSave()
  const docs: Doc[] = emp.documents
  // the server decides per employee (view.all, or view.own within reach today): a row carries its file fields only if this user may open it
  const hasFile = (d: Doc) => 'original_name' in d
  const editor = can('employee_document.edit') && !readOnly
  const lng = intlLocale(i18n.language)
  const date = new Intl.DateTimeFormat(lng, { dateStyle: 'medium', timeZone: 'UTC' })
  const num = sizeFormat(i18n.language)
  const size = (b = 0) => (b < MB ? t('document.sizeKb', { size: num.format(b / 1024) }) : t('document.size', { size: num.format(b / MB) }))
  const today = Date.parse(cairoToday())
  // this render's dialog only: a save that answers after its dialog closed must not close one opened since
  const closeDlg = () => setDlg((d) => (d === dlg ? null : d))
  const missing = DOC_KINDS.filter((k) => k !== 'other' && !docs.some((d) => d.kind === k))
  const upload = (
    <Button type="button" onClick={() => setPreset({})}>
      <Upload aria-hidden /> {t('document.upload')}
    </Button>
  )

  function expiry(d: Doc) {
    if (!d.expires_at) return '—'
    const days = (Date.parse(d.expires_at) - today) / 86_400_000
    const badge =
      days < 0 ? <Badge variant="danger">{t('document.expired')}</Badge>
      : days === 0 ? <Badge variant="warning">{t('document.expiresToday')}</Badge>
      : days <= EXPIRY_SOON_DAYS && <Badge variant="warning">{t('document.expiresIn', { count: days })}</Badge>
    return (
      <span className="inline-flex flex-wrap items-center justify-end gap-2">
        {date.format(new Date(d.expires_at))}
        {badge}
      </span>
    )
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>{t('document.title', { count: docs.length })}</CardTitle>
        {docs.length > 0 && editor && upload}
      </CardHeader>
      <DataList
        label={t('document.title', { count: docs.length })}
        rows={docs}
        rowKey={(d) => d.id}
        title={{
          header: t('document.kind'),
          cell: (d) => (hasFile(d) ? <button type="button" className="inline-flex min-h-tap items-center text-start font-medium text-primary underline underline-offset-4" onClick={() => setViewing(d)}>{kindName(t, d.kind)}</button> : kindName(t, d.kind)),
        }}
        fields={[
          ...(docs.some(hasFile)
            ? [{ header: t('document.file'), cell: (d: Doc) => hasFile(d) && (
                <span className="inline-flex min-w-0 items-baseline gap-2">
                  <bdi className="block max-w-56 truncate" title={d.original_name}>{d.original_name}</bdi>
                  <span className="shrink-0 text-muted-foreground">{size(d.size_bytes)}</span>
                </span>
              ) }]
            : []),
          { header: t('document.expires_at'), cell: expiry },
        ]}
        actions={editor ? (d) => (
          <RowActions
            items={[
              { label: t('document.replace'), onSelect: () => setPreset({ replaces: d }) },
              { label: t('document.editDetails'), onSelect: () => setDlg({ type: 'edit', doc: d }) },
              { label: t('document.delete'), onSelect: () => setDlg({ type: 'delete', doc: d }), destructive: true },
            ]}
          />
        ) : undefined}
        empty={{ icon: FileText, title: t('document.empty'), action: editor ? upload : undefined }}
      />
      {docs.length > 0 && editor && missing.length > 0 && (
        <p className="border-t px-5 py-3 text-sm text-muted-foreground">
          {t('document.missing')}{' '}
          {new Intl.ListFormat(lng, { type: 'conjunction' }).formatToParts(missing).map((part, a, parts) =>
            part.type === 'literal' ? (
              <Fragment key={a}>{part.value.replace(GLUE, '')}</Fragment>
            ) : (
              // the literal's last word («و», "and") sticks to the kind after it; a long kind can still wrap inside its button
              <span key={a} className="whitespace-nowrap">
                {parts[a - 1]?.value.match(GLUE)?.[0]}
                {/* a link inside a sentence: exempt from the 44 px target (WCAG 2.5.8) */}
                <button type="button" className="whitespace-normal text-primary underline-offset-4 hover:underline" onClick={() => setPreset({ kind: part.value })}>
                  {kindName(t, part.value)}
                </button>
              </span>
            ),
          )}
        </p>
      )}
      <UploadDialog empId={emp.id} preset={preset} onClose={() => setPreset(null)} />
      <Viewer empId={emp.id} doc={viewing} onClose={() => setViewing(null)} />
      {dlg?.type === 'edit' && (
        <FormDialog
          title={t('document.editDetails')}
          description={kindName(t, dlg.doc.kind)}
          open
          onOpenChange={(o) => !o && closeDlg()}
          init={{ kind: dlg.doc.kind, expires_at: dlg.doc.expires_at ?? '', note: dlg.doc.note ?? '' }}
          // without employee_document.view the row has no note: leave it out rather than erase a note this user never saw
          submit={(f) => send(`/employees/${emp.id}/documents/${dlg.doc.id}`, { method: 'PATCH', body: { kind: f.kind, expires_at: f.expires_at || null, ...('note' in dlg.doc && { note: f.note || null }) } })}
          onDone={closeDlg}
          shown={['kind', 'expires_at', 'note']}
        >
          {(p) => (
            <>
              <SelectField name="kind" label={t('document.kind')} options={kindOptions(t)} {...p} />
              <TextField name="expires_at" label={t('document.expires_at')} type="date" {...p} />
              {'note' in dlg.doc && <TextField name="note" label={t('document.note')} enterKeyHint="done" {...p} />}
            </>
          )}
        </FormDialog>
      )}
      {dlg?.type === 'delete' && (
        <FormDialog
          title={t('document.deleteTitle', { name: kindName(t, dlg.doc.kind) })}
          open
          onOpenChange={(o) => !o && closeDlg()}
          init={{}}
          submit={() => send(`/employees/${emp.id}/documents/${dlg.doc.id}`, { method: 'DELETE' })}
          onDone={closeDlg}
          shown={[]}
          footer="document.delete"
          destructive
          description={
            <span className="grid gap-1 text-foreground">
              {dlg.doc.original_name && <bdi className="font-medium [overflow-wrap:anywhere]">{dlg.doc.original_name}</bdi>}
              <span>{t('document.deleteConfirm')}</span>
            </span>
          }
        >
          {() => null}
        </FormDialog>
      )}
    </Card>
  )
}

/** Uploads one file for an employee; open while `preset` is not null. `replaces` prefills kind, expiry and note and sends the old document's id. */
export function UploadDialog({ empId, preset, onClose }: { empId: string; preset: Preset; onClose: () => void }) {
  const { t } = useTranslation()
  // here, not in the form: Escape, ✕ and the scrim are ignored while a photo shrinks or the file uploads
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open={preset !== null} onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t(preset?.replaces ? 'document.replace' : 'document.upload')}</DialogTitle>
        </DialogHeader>
        {preset && <UploadForm empId={empId} preset={preset} onClose={onClose} busy={busy} setBusy={setBusy} />}
      </DialogContent>
    </Dialog>
  )
}

/** The dialog's body: mounted only while open, so every open starts empty (or from the preset). */
function UploadForm({ empId, preset, onClose, busy, setBusy }: { empId: string; preset: NonNullable<Preset>; onClose: () => void; busy: boolean; setBusy: (b: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const send = useSave()
  const old = preset.replaces
  const { form, set } = useForm({ kind: old?.kind ?? preset.kind ?? '', expires_at: old?.expires_at ?? '', note: old?.note ?? '' })
  const [file, setFile] = useState<File>()
  const [fileErr, setFileErr] = useState<string>()
  const [err, setErr] = useState<unknown>()
  const [network, setNetwork] = useState(false)
  const [preview, setPreview] = useState<string>()

  useEffect(() => {
    if (!file?.type.startsWith('image/')) return setPreview(undefined)
    const url = URL.createObjectURL(file)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [file])

  const mb = sizeFormat(i18n.language)
  const tooLarge = (f: File) =>
    [t('document.tooLarge', { size: mb.format(f.size / MB), max: mb.format(MAX_BYTES / MB) }), f.type === 'application/pdf' && t('document.tooLargePdf')].filter(Boolean).join(' ')

  // check the type, then shrink a big photo, then check the size: the limit must not refuse the photos the shrink is for
  async function pick(chosen: File | undefined) {
    setFile(undefined)
    setErr(undefined)
    setNetwork(false)
    if (!chosen) return setFileErr(undefined)
    if (isHeic(chosen)) return setFileErr(t('document.heic'))
    const type = typeOf(chosen)
    if (!ACCEPT.includes(type)) return setFileErr(t('document.badType'))
    const picked = type === chosen.type ? chosen : new File([chosen], chosen.name, { type })
    setBusy(true)
    const f = type.startsWith('image/') && picked.size > SHRINK_OVER && picked.size <= SHRINK_UNDER ? await shrinkPhoto(picked) : picked
    setBusy(false)
    if (f.size > MAX_BYTES) return setFileErr(tooLarge(f))
    setFileErr(undefined)
    setFile(f)
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!file || busy) return
    const body = new FormData()
    body.set('file', file)
    body.set('kind', form.kind)
    if (form.expires_at) body.set('expires_at', form.expires_at)
    if (form.note) body.set('note', form.note)
    if (old) body.set('replaces', old.id)
    const retry = network
    setBusy(true)
    setErr(undefined)
    setNetwork(false)
    try {
      await send(`/employees/${empId}/documents`, { body })
      onClose()
    } catch (e) {
      // no answer (or a gateway's): the upload may still have landed, so a Retry can add a duplicate (HR deletes it) or 404 on a replace
      if (retry && old && e instanceof ApiError && e.status === 404) return onClose() // the first replace landed and hid the old row
      if (e instanceof ApiError && e.status === 413) {
        setFile(undefined) // the server's limit is below ours: resending the same file cannot work
        setFileErr(t('document.tooLargeServer'))
      } else if (e instanceof ApiError && e.status < 500) setErr(e)
      else setNetwork(true)
    } finally {
      setBusy(false)
    }
  }

  const errs = { ...toErrs(err), ...(fileErr && { file: fileErr }) }
  return (
    <form className="contents" onSubmit={submit}>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={network ? new Error(t('document.network')) : err} shown={['file', 'kind', 'expires_at', 'note']} />
        <Field name="file" label={t('document.file')} errs={errs}>
          {(p) => (
            // the real input stays (named by the field label) but hidden: its own "Choose File" text is in the browser's language
            <div className="flex min-w-0 items-center gap-3">
              <input {...p} type="file" accept={ACCEPT.join(',')} className="peer sr-only" disabled={busy} onChange={(e) => void pick(e.target.files?.[0])} />
              <label htmlFor={p.id} aria-hidden className={cn(buttonVariants({ variant: 'outline' }), 'shrink-0 cursor-pointer peer-focus-visible:outline-2 peer-focus-visible:outline-solid peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring peer-disabled:pointer-events-none peer-disabled:opacity-50 peer-aria-invalid:border-destructive')}>
                <Upload aria-hidden /> {t('document.choose')}
              </label>
              <bdi className="min-w-0 truncate text-sm text-muted-foreground" title={file?.name}>{file?.name ?? t('document.noFile')}</bdi>
            </div>
          )}
        </Field>
        {preview && <img src={preview} alt="" className="max-h-48 w-full rounded-lg border object-contain" />}
        <SelectField name="kind" label={t('document.kind')} options={kindOptions(t)} blank={t('document.pickKind')} values={form} set={set} errs={errs} />
        <TextField name="expires_at" label={t('document.expires_at')} type="date" values={form} set={set} errs={errs} />
        <TextField name="note" label={t('document.note')} enterKeyHint="done" values={form} set={set} errs={errs} />
      </DialogBody>
      <DialogFooter>
        <Button type="submit" disabled={busy || !file || !form.kind}>
          {t(busy ? 'document.uploading' : network ? 'document.retry' : 'document.upload')}
        </Button>
      </DialogFooter>
    </form>
  )
}

/** Shows one document's file in the app: photos as an img, PDFs in an iframe where the browser can, otherwise an Open link; always a Download link. */
function Viewer({ empId, doc, onClose }: { empId: string; doc: Doc | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const date = new Intl.DateTimeFormat(intlLocale(i18n.language), { dateStyle: 'medium', timeZone: 'Africa/Cairo' })
  return (
    <Dialog open={doc !== null} onOpenChange={(o) => !o && onClose()}>
      {/* full screen under sm, below the notch (the close button sits at the top) */}
      <DialogContent className="top-[env(safe-area-inset-top)] max-h-none rounded-none sm:h-[85vh] sm:max-h-none sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{doc && kindName(t, doc.kind)}</DialogTitle>
          <DialogDescription className="[overflow-wrap:anywhere]">
            {/* the names are React children, never parsed as markup; <bdi> keeps a Latin name from reordering the Arabic */}
            {doc && <Trans i18nKey="document.uploadedBy" values={{ date: doc.created_at ? date.format(new Date(doc.created_at)) : '—' }} components={{ file: <bdi>{doc.original_name}</bdi>, name: <bdi>{doc.uploader_name ?? '—'}</bdi> }} />}
          </DialogDescription>
        </DialogHeader>
        {doc && <ViewerFile key={doc.id} empId={empId} doc={doc} />}
      </DialogContent>
    </Dialog>
  )
}

/** The dialog's body: mounted only while open, so the object URL lives exactly as long as the dialog. */
function ViewerFile({ empId, doc }: { empId: string; doc: Doc }) {
  const { t } = useTranslation()
  const kind = kindName(t, doc.kind)
  const [url, setUrl] = useState<string>()
  const [failed, setFailed] = useState(false)
  const [tries, setTries] = useState(0)
  // the Blob's type comes from the stored mime, allow-listed; never from the response, so a file can't turn itself into HTML
  const type = doc.mime && Object.hasOwn(EXT, doc.mime) ? doc.mime : 'application/octet-stream'
  // as the server's file route: the base name (or "document") plus the extension of the stored type, so x.html stored as a PDF saves as x.pdf
  const name = (doc.original_name?.includes('.') ? doc.original_name.slice(0, doc.original_name.lastIndexOf('.')) : doc.original_name) || 'document'

  useEffect(() => {
    let made: string | undefined
    let live = true
    setFailed(false)
    apiBlob(`/employees/${empId}/documents/${doc.id}/file`).then(
      (b) => live && setUrl((made = URL.createObjectURL(new Blob([b], { type })))),
      () => live && setFailed(true),
    )
    return () => {
      live = false
      if (made) URL.revokeObjectURL(made)
    }
  }, [empId, doc.id, type, tries])

  const center = 'm-auto grid justify-items-center gap-3 text-center'
  return (
    <>
      <DialogBody className="flex min-h-0 flex-col p-2 sm:p-4">
        {failed ? (
          <div className={center}>
            <p className="text-sm text-muted-foreground">{t('document.loadFailed')}</p>
            <Button type="button" variant="outline" onClick={() => setTries((n) => n + 1)}>{t('document.retry')}</Button>
          </div>
        ) : !url ? (
          <Skeleton aria-busy="true" aria-label={t('auth.loading')} className="min-h-64 flex-1" />
        ) : type.startsWith('image/') ? (
          <img src={url} alt={t('document.imageAlt', { kind, name: doc.original_name })} className="min-h-0 w-full flex-1 object-contain" />
        ) : type === 'application/pdf' && navigator.pdfViewerEnabled ? (
          // no sandbox: Chromium refuses to show a PDF inside one
          <iframe src={url} title={kind} className="min-h-0 w-full flex-1 rounded-lg border" />
        ) : type === 'application/pdf' ? (
          // a link the user taps, not window.open: iOS blocks a window opened after an await
          <a href={url} target="_blank" rel="noopener" className={cn(buttonVariants(), center)}>{t('document.open')}</a>
        ) : null}
      </DialogBody>
      {url && (
        <DialogFooter>
          {/* iOS Safari may draw only page 1 of a PDF inside the iframe; Open shows the whole file */}
          {type === 'application/pdf' && navigator.pdfViewerEnabled && <a href={url} target="_blank" rel="noopener" className={buttonVariants({ variant: 'secondary' })}>{t('document.open')}</a>}
          <a href={url} download={`${name}.${Object.hasOwn(EXT, type) ? EXT[type] : 'bin'}`} className={buttonVariants({ variant: 'secondary' })}>{t('document.download')}</a>
        </DialogFooter>
      )}
    </>
  )
}
