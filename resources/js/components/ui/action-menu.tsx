import { Fragment, KeyboardEvent, ReactNode, useEffect, useLayoutEffect, useRef, useState } from "react";
import { MoreHorizontal } from "lucide-react";

export type MenuAction = {
  label: string;
  icon?: ReactNode;
  onSelect: () => void;
  danger?: boolean;
  disabled?: boolean;
};

/** A "..." button for the less frequent actions. Each group is separated by a divider, and empty groups are skipped. */
export function ActionMenu({ label, groups }: { label: string; groups: MenuAction[][] }) {
  const [open, setOpen] = useState(false);
  const [nudge, setNudge] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const filled = groups.filter((group) => group.length > 0);

  const items = () => Array.from(root.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not(:disabled)') ?? []);

  // Lines up with the button's right edge, moving right only as far as it takes to stay on screen
  useLayoutEffect(() => {
    if (open && trigger.current && menu.current) {
      setNudge(Math.max(0, menu.current.offsetWidth + 8 - trigger.current.getBoundingClientRect().right));
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;
    items()[0]?.focus();
    const closeOutside = (e: MouseEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", closeOutside);
    return () => document.removeEventListener("mousedown", closeOutside);
  }, [open]);

  if (filled.length === 0) return null;

  const close = () => {
    setOpen(false);
    trigger.current?.focus();
  };

  const onKeyDown = (e: KeyboardEvent) => {
    if (e.key === "Escape") {
      e.preventDefault();
      close();
    } else if (e.key === "Tab") {
      setOpen(false);
    } else if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      const list = items();
      const at = list.indexOf(document.activeElement as HTMLButtonElement);
      list[(at + (e.key === "ArrowDown" ? 1 : -1) + list.length) % list.length]?.focus();
    }
  };

  return (
    <div ref={root} className="relative" onKeyDown={open ? onKeyDown : undefined}>
      <button
        ref={trigger}
        type="button"
        className="btn-secondary btn-sm px-2"
        aria-label={label}
        title={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <MoreHorizontal className="h-4 w-4" />
      </button>
      {open ? (
        <div
          ref={menu}
          role="menu"
          aria-label={label}
          style={{ right: -nudge }}
          className="absolute z-30 mt-1.5 w-48 rounded-lg border border-slate-200 bg-white p-1 shadow-lg"
        >
          {filled.map((group, index) => (
            <Fragment key={index}>
              {index > 0 ? <div role="separator" className="my-1 border-t border-slate-100" /> : null}
              {group.map((action) => (
                <button
                  key={action.label}
                  type="button"
                  role="menuitem"
                  disabled={action.disabled}
                  className={`flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium outline-none transition disabled:cursor-not-allowed disabled:opacity-50 ${
                    action.danger ? "text-red-600 hover:bg-red-50 focus:bg-red-50" : "text-slate-700 hover:bg-slate-100 focus:bg-slate-100"
                  }`}
                  onClick={() => {
                    close();
                    action.onSelect();
                  }}
                >
                  {action.icon}
                  {action.label}
                </button>
              ))}
            </Fragment>
          ))}
        </div>
      ) : null}
    </div>
  );
}
