import { Languages, Undo2 } from "lucide-react"
import * as React from "react"

import { MicButton } from "@/components/ui/mic-button"
import { sendJson } from "@/lib/http"
import { useTrans } from "@/lib/i18n"
import { cn } from "@/lib/utils"

type TextareaProps = Omit<React.ComponentProps<"textarea">, "translate"> & {
  /** The app's microphone under the field (owner's task of November 25). On by default for every typed field. */
  voice?: boolean
  /** "Translate to English" (fields whose text goes to the customer); "Undo" puts back the text before. */
  translatable?: boolean
  /** More buttons in the row under the field (e.g. "Write with AI"). */
  actions?: React.ReactNode
  wrapperClassName?: string
}

/** Puts a value in the field as if typed, so the page's own onChange gets it. */
export function setFieldValue(field: HTMLTextAreaElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, "value")?.set
  setter?.call(field, value)
  field.dispatchEvent(new Event("input", { bubbles: true }))
}

function Textarea({
  className,
  voice = true,
  translatable = false,
  actions,
  wrapperClassName,
  ref,
  ...props
}: TextareaProps) {
  const t = useTrans()
  const inner = React.useRef<HTMLTextAreaElement | null>(null)
  // The cursor counts only once the person has been in the field; before that words go at the end.
  const touched = React.useRef(false)
  const [undo, setUndo] = React.useState<string | null>(null)
  const [translating, setTranslating] = React.useState(false)
  const [failed, setFailed] = React.useState(false)

  const setRefs = (node: HTMLTextAreaElement | null) => {
    inner.current = node
    if (typeof ref === "function") ref(node)
    else if (ref) ref.current = node
  }

  const controlled = typeof props.value === "string" && typeof props.onChange === "function"
  const editable = controlled && !props.disabled && !props.readOnly
  const withMic = voice && editable
  const value = typeof props.value === "string" ? props.value : ""

  const field = (
    <textarea
      data-slot="textarea"
      ref={setRefs}
      className={cn(
        "border-input placeholder:text-muted-foreground da-input flex min-h-20 w-full border px-3.5 py-2.5 text-base transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 aria-invalid:border-destructive",
        className
      )}
      {...props}
      onFocus={(event) => {
        touched.current = true
        props.onFocus?.(event)
      }}
    />
  )

  if (!withMic && !(translatable && editable) && !actions) return field

  const set = (next: string) => {
    if (inner.current) setFieldValue(inner.current, next)
  }

  const runTranslate = async () => {
    if (value.trim() === "" || translating) return
    setTranslating(true)
    setFailed(false)
    try {
      const response = await sendJson("/ai/translate", "POST", { text: value })
      const data = (await response.json()) as { text?: string }
      if (!response.ok || typeof data.text !== "string") throw new Error("failed")
      setUndo(value)
      set(data.text)
    } catch {
      setFailed(true)
    } finally {
      setTranslating(false)
    }
  }

  return (
    <div className={cn("w-full space-y-1.5", wrapperClassName)} data-slot="textarea-wrapper">
      {field}
      <div className="flex flex-wrap items-center justify-end gap-1.5">
        {failed && (
          <span role="alert" className="mr-auto text-sm font-semibold text-red-600" data-test="translate-error">
            {t("ui.translate.failed")}
          </span>
        )}
        {actions}
        {translatable && editable && undo !== null && (
          <button
            type="button"
            className="da-press inline-flex h-11 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold"
            onClick={() => {
              set(undo)
              setUndo(null)
            }}
            data-test="translate-undo"
          >
            <Undo2 className="size-4" /> {t("ui.translate.undo")}
          </button>
        )}
        {translatable && editable && (
          <button
            type="button"
            className="da-soft da-press inline-flex h-11 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold text-navy disabled:opacity-50"
            disabled={translating || value.trim() === ""}
            onClick={runTranslate}
            data-test="translate-button"
          >
            <Languages className="size-4" /> {translating ? t("ui.translate.working") : t("ui.translate.button")}
          </button>
        )}
        {withMic && (
          <MicButton
            value={value}
            onChange={set}
            selection={() => {
              const el = inner.current
              if (!el || !touched.current) return null

              return [el.selectionStart, el.selectionEnd]
            }}
          />
        )}
      </div>
    </div>
  )
}

export { Textarea }
