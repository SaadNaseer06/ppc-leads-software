import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { X } from "lucide-react";

type ConfirmOptions = {
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Destructive actions use the danger button */
  tone?: "default" | "danger";
  /** When set, the confirm button stays disabled until the user types this exact string */
  requireText?: string;
  requireTextLabel?: string;
};

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn>(async () => false);

type Pending = ConfirmOptions & {
  resolve: (value: boolean) => void;
};

/** Promise-based themed confirm dialog — drop-in replacement for window.confirm / prompt. */
export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<Pending | null>(null);
  const [typed, setTyped] = useState("");
  const dialogRef = useRef<HTMLDialogElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const confirm = useCallback<ConfirmFn>((options) => {
    return new Promise<boolean>((resolve) => {
      setTyped("");
      setPending({ ...options, resolve });
    });
  }, []);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (pending && !dialog.open) dialog.showModal();
    if (!pending && dialog.open) dialog.close();
    if (pending?.requireText) {
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [pending]);

  function settle(value: boolean) {
    pending?.resolve(value);
    setPending(null);
    setTyped("");
  }

  const requireOk = !pending?.requireText || typed === pending.requireText;
  const tone = pending?.tone ?? "danger";

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <dialog
        ref={dialogRef}
        className="modal"
        onClose={() => settle(false)}
        onClick={(e) => {
          if (e.target === dialogRef.current) settle(false);
        }}
      >
        {pending ? (
          <div className="flex max-h-[calc(100vh-2rem)] flex-col">
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
              <div>
                <h2 className="text-base font-semibold text-slate-900">{pending.title}</h2>
                {pending.description ? (
                  <p className="mt-1 whitespace-pre-wrap text-sm leading-5 text-slate-500">{pending.description}</p>
                ) : null}
              </div>
              <button type="button" className="btn-ghost -mr-2 -mt-1 p-1.5" onClick={() => settle(false)} aria-label="Close">
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="space-y-4 px-5 py-4">
              {pending.requireText ? (
                <div>
                  <label className="label" htmlFor="confirm-require-text">
                    {pending.requireTextLabel ?? `Type ${pending.requireText} to confirm`}
                  </label>
                  <input
                    ref={inputRef}
                    id="confirm-require-text"
                    className="input font-mono"
                    value={typed}
                    onChange={(e) => setTyped(e.target.value)}
                    autoComplete="off"
                    spellCheck={false}
                  />
                </div>
              ) : null}
              <div className="flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={() => settle(false)}>
                  {pending.cancelLabel ?? "Cancel"}
                </button>
                <button
                  type="button"
                  className={tone === "danger" ? "btn-danger" : "btn-primary"}
                  disabled={!requireOk}
                  onClick={() => settle(true)}
                >
                  {pending.confirmLabel ?? "Confirm"}
                </button>
              </div>
            </div>
          </div>
        ) : null}
      </dialog>
    </ConfirmContext.Provider>
  );
}

export function useConfirm(): ConfirmFn {
  return useContext(ConfirmContext);
}
