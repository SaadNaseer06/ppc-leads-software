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
  const [spot, setSpot] = useState<{ top: number; left: number } | null>(null);
  const root = useRef<HTMLDivElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const filled = groups.filter((group) => group.length > 0);

  const items = () => Array.from(root.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not(:disabled)') ?? []);

  // Fixed to the screen so tables can't clip it. Lines up with the button, staying on screen.
  useLayoutEffect(() => {
    if (!open || !trigger.current || !menu.current) {
      setSpot(null);
      return;
    }
    const button = trigger.current.getBoundingClientRect();
    const { offsetWidth: width, offsetHeight: height } = menu.current;
    const below = button.bottom + 6;
    setSpot({
      left: Math.min(Math.max(8, button.right - width), window.innerWidth - width - 8),
      top: below + height > window.innerHeight - 8 ? Math.max(8, button.top - height - 6) : below,
    });
  }, [open]);

  // Only once it's placed: a hidden menu can't take focus
  useEffect(() => {
    if (spot) items()[0]?.focus();
  }, [spot]);

  useEffect(() => {
    if (!open) return;
    const closeOutside = (e: MouseEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    const closeOnMove = () => setOpen(false);
    document.addEventListener("mousedown", closeOutside);
    window.addEventListener("scroll", closeOnMove, true);
    window.addEventListener("resize", closeOnMove);
    return () => {
      document.removeEventListener("mousedown", closeOutside);
      window.removeEventListener("scroll", closeOnMove, true);
      window.removeEventListener("resize", closeOnMove);
    };
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
          style={{ position: "fixed", top: spot?.top ?? 0, left: spot?.left ?? 0, visibility: spot ? "visible" : "hidden" }}
          className="z-30 w-48 rounded-lg border border-slate-200 bg-white p-1 text-left shadow-lg"
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
