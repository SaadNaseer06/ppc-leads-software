import { ChangeEvent, DragEvent, FormEvent, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { EditorContent, useEditor, useEditorState, type Editor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import { Placeholder } from "@tiptap/extensions";
import { Bold, FileText, Image as ImageIcon, Italic, Link2, List, ListOrdered, Paperclip, Send, Underline, Upload, X } from "lucide-react";
import type { LeadEmailRow } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { apiHeaders, useToast } from "@/components/ui/toast";
import { BLOCKED_FILES, fileSize, replySubject } from "@/lib/email";

const MAX_FILES = 10;

type Props = {
  leadId: number;
  leadName: string;
  mailbox: string;
  replyTo: LeadEmailRow | null;
  onSent: () => void;
  onCancel: () => void;
  compact?: boolean;
};

type Picked = { key: string; file: File };

/**
 * Writing to a lead from the team's mailbox, with formatting and files. A reply keeps the conversation's
 * subject and threads under the email it answers, so it lands in the same conversation in the client's
 * mail app. Loaded only when someone starts writing, so the editor never slows the pages down.
 */
export default function EmailComposer({ leadId, leadName, mailbox, replyTo, onSent, onCancel, compact = false }: Props) {
  const toast = useToast();
  const attachMax = usePage<SharedProps>().props.app.attach_max;
  const [subject, setSubject] = useState(replyTo ? replySubject(replyTo.subject) : "");
  const [files, setFiles] = useState<Picked[]>([]);
  const [sending, setSending] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [dragging, setDragging] = useState(false);
  const [linkOpen, setLinkOpen] = useState(false);
  const dragDepth = useRef(0);
  const picker = useRef<HTMLInputElement>(null);

  // The editor's own handlers are set once, so they reach the latest state through refs
  const sendRef = useRef<() => void>(() => {});
  const addRef = useRef<(list: FileList | File[]) => void>(() => {});
  const cancelRef = useRef<() => void>(() => {});

  const editor = useEditor({
    // A reply starts in the message; a new email starts in the subject
    autofocus: replyTo ? "end" : false,
    extensions: [
      StarterKit.configure({
        heading: false,
        codeBlock: false,
        code: false,
        blockquote: false,
        horizontalRule: false,
        strike: false,
        link: { openOnClick: false, autolink: true, defaultProtocol: "https", protocols: ["mailto"] },
      }),
      Placeholder.configure({ placeholder: replyTo ? `Reply to ${leadName}…` : `Write to ${leadName}…` }),
    ],
    editorProps: {
      attributes: {
        class: `email-editor overflow-y-auto px-3.5 py-3 text-sm leading-relaxed text-slate-800 focus:outline-none ${compact ? "min-h-[120px] max-h-[320px]" : "min-h-[150px] max-h-[420px]"}`,
        role: "textbox",
        "aria-multiline": "true",
        "aria-label": "Message",
      },
      // A pasted screenshot or file becomes an attachment
      handlePaste: (_view, event) => {
        const pasted = event.clipboardData?.files;
        if (pasted && pasted.length) {
          addRef.current(pasted);
          return true;
        }
        return false;
      },
      handleKeyDown: (_view, event) => {
        if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          sendRef.current();
          return true;
        }
        if (event.key === "Escape") {
          cancelRef.current();
          return true;
        }
        return false;
      },
    },
  });

  const state = useEditorState({
    editor,
    selector: ({ editor: e }) =>
      e
        ? {
            bold: e.isActive("bold"),
            italic: e.isActive("italic"),
            underline: e.isActive("underline"),
            bullets: e.isActive("bulletList"),
            numbers: e.isActive("orderedList"),
            link: e.isActive("link"),
            empty: e.isEmpty,
          }
        : null,
  });

  const total = files.reduce((sum, f) => sum + f.file.size, 0);

  function addFiles(list: FileList | File[]) {
    const incoming = Array.from(list);
    const blocked = incoming.find((f) => BLOCKED_FILES.includes(f.name.split(".").pop()?.toLowerCase() ?? ""));
    if (blocked) {
      setError(`${blocked.name} can't be sent: mail providers block this kind of file. Zip it first, or share a link.`);
      return;
    }
    if (files.length + incoming.length > MAX_FILES) {
      setError(`Attach up to ${MAX_FILES} files`);
      return;
    }
    const size = incoming.reduce((sum, f) => sum + f.size, total);
    if (size > attachMax) {
      setError(`Attachments can be up to ${fileSize(attachMax)} in total. Share bigger files as a link.`);
      return;
    }
    setError("");
    setFiles((prev) => [...prev, ...incoming.map((file) => ({ key: `${file.name}-${file.size}-${Math.random()}`, file }))]);
  }

  function cancel() {
    if (!editor || (editor.isEmpty && files.length === 0)) onCancel();
  }

  async function send(e?: FormEvent) {
    e?.preventDefault();
    if (sending || !editor) return;
    if (!subject.trim()) {
      setError("Add a subject");
      return;
    }
    if (editor.isEmpty && files.length === 0) {
      setError("Write a message");
      editor.commands.focus();
      return;
    }
    const form = new FormData();
    form.append("subject", subject.trim());
    if (!editor.isEmpty) form.append("html", editor.getHTML());
    if (replyTo) form.append("reply_to_id", String(replyTo.id));
    for (const f of files) form.append("files[]", f.file);

    setSending(true);
    setError("");
    setProgress(files.length ? 0 : null);
    const res = await upload(`/api/leads/${leadId}/emails`, form, files.length ? setProgress : undefined);
    setSending(false);
    setProgress(null);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`Email sent to ${leadName}`);
    editor.commands.clearContent();
    setFiles([]);
    onSent();
  }

  sendRef.current = () => void send();
  addRef.current = addFiles;
  cancelRef.current = cancel;

  // Files dropped anywhere on the composer are attached, not opened by the browser
  function hasFiles(e: DragEvent) {
    return Array.from(e.dataTransfer.types).includes("Files");
  }
  function onDragEnter(e: DragEvent) {
    if (!hasFiles(e)) return;
    dragDepth.current += 1;
    setDragging(true);
  }
  function onDragLeave(e: DragEvent) {
    if (!hasFiles(e)) return;
    dragDepth.current = Math.max(0, dragDepth.current - 1);
    if (dragDepth.current === 0) setDragging(false);
  }
  function onDragOver(e: DragEvent) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    e.stopPropagation();
  }
  function onDrop(e: DragEvent) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    e.stopPropagation();
    dragDepth.current = 0;
    setDragging(false);
    addFiles(e.dataTransfer.files);
  }

  function onPick(e: ChangeEvent<HTMLInputElement>) {
    if (e.target.files?.length) addFiles(e.target.files);
    e.target.value = "";
  }

  return (
    <form onSubmit={send} className={compact ? "p-4" : "px-5 py-4"}>
      <div
        className="relative overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-500/15"
        onDragEnterCapture={onDragEnter}
        onDragLeaveCapture={onDragLeave}
        onDragOverCapture={onDragOver}
        onDropCapture={onDrop}
      >
        <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 border-b border-slate-100 px-3.5 py-2 text-xs text-slate-500">
          <span>
            From <span className="font-medium text-slate-700">{mailbox}</span>
          </span>
          <span>
            To <span className="font-medium text-slate-700">{leadName}</span>
          </span>
        </div>
        {replyTo ? (
          <p className="truncate border-b border-slate-100 px-3.5 py-2 text-xs text-slate-500" title={subject}>
            {subject}
          </p>
        ) : (
          <input
            className="block w-full border-0 border-b border-slate-100 px-3.5 py-2.5 text-sm font-medium text-slate-900 placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:ring-0"
            placeholder="Subject"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                editor?.commands.focus();
              }
              if (e.key === "Escape") cancel();
            }}
            maxLength={200}
            aria-label="Subject"
            autoFocus
          />
        )}

        {/* The editor is mounted straight away; until it's ready the box keeps its height */}
        {editor ? <EditorContent editor={editor} /> : <div className={compact ? "min-h-[120px]" : "min-h-[150px]"} />}

        {files.length ? (
          <ul className="flex flex-wrap gap-2 px-3.5 pb-3" aria-label="Attachments">
            {files.map(({ key, file }) => (
              <li key={key} className="flex max-w-full items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-2.5 pr-1.5 text-xs">
                {file.type.startsWith("image/") ? <ImageIcon className="h-3.5 w-3.5 shrink-0 text-slate-400" /> : <FileText className="h-3.5 w-3.5 shrink-0 text-slate-400" />}
                <span className="min-w-0 max-w-[14rem] truncate font-medium text-slate-700" title={file.name}>
                  {file.name}
                </span>
                <span className="shrink-0 text-slate-400">{fileSize(file.size)}</span>
                <button
                  type="button"
                  className="rounded p-0.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-700 disabled:opacity-50"
                  onClick={() => setFiles((prev) => prev.filter((f) => f.key !== key))}
                  disabled={sending}
                  aria-label={`Remove ${file.name}`}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </li>
            ))}
          </ul>
        ) : null}

        {linkOpen && editor ? <LinkBar editor={editor} onClose={() => setLinkOpen(false)} /> : null}

        <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-slate-50/60 px-2 py-1.5">
          <div className="flex items-center" role="toolbar" aria-label="Formatting">
            <Tool label="Bold (Ctrl+B)" active={state?.bold} onClick={() => editor?.chain().focus().toggleBold().run()}>
              <Bold className="h-4 w-4" />
            </Tool>
            <Tool label="Italic (Ctrl+I)" active={state?.italic} onClick={() => editor?.chain().focus().toggleItalic().run()}>
              <Italic className="h-4 w-4" />
            </Tool>
            <Tool label="Underline (Ctrl+U)" active={state?.underline} onClick={() => editor?.chain().focus().toggleUnderline().run()}>
              <Underline className="h-4 w-4" />
            </Tool>
            <span className="mx-1 h-4 w-px bg-slate-200" aria-hidden />
            <Tool label="Bulleted list" active={state?.bullets} onClick={() => editor?.chain().focus().toggleBulletList().run()}>
              <List className="h-4 w-4" />
            </Tool>
            <Tool label="Numbered list" active={state?.numbers} onClick={() => editor?.chain().focus().toggleOrderedList().run()}>
              <ListOrdered className="h-4 w-4" />
            </Tool>
            <Tool label="Link" active={state?.link || linkOpen} onClick={() => setLinkOpen((v) => !v)}>
              <Link2 className="h-4 w-4" />
            </Tool>
            <span className="mx-1 h-4 w-px bg-slate-200" aria-hidden />
            <Tool label={`Attach files (up to ${fileSize(attachMax)})`} onClick={() => picker.current?.click()} disabled={sending}>
              <Paperclip className="h-4 w-4" />
            </Tool>
            <input ref={picker} type="file" multiple className="hidden" onChange={onPick} tabIndex={-1} aria-hidden />
          </div>
          <div className="ml-auto flex items-center gap-2">
            <button type="button" className="btn-ghost btn-sm" onClick={onCancel} disabled={sending}>
              Discard
            </button>
            <button type="submit" className="btn-primary btn-sm" disabled={sending} title="Send (Ctrl + Enter)">
              <Send className="h-3.5 w-3.5" />
              {sending ? (progress !== null && progress < 100 ? `Uploading ${progress}%` : "Sending…") : "Send"}
            </button>
          </div>
        </div>

        {dragging ? (
          <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-teal-500 bg-teal-50/90 text-sm font-medium text-teal-800">
            <Upload className="mr-2 h-4 w-4" />
            Drop files to attach
          </div>
        ) : null}
      </div>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
    </form>
  );
}

function Tool({ label, active, onClick, disabled, children }: { label: string; active?: boolean; onClick: () => void; disabled?: boolean; children: React.ReactNode }) {
  return (
    <button
      type="button"
      // Keeps the text selected while a button is clicked
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      aria-pressed={active ?? undefined}
      title={label}
      className={`rounded-md p-1.5 transition disabled:opacity-50 ${active ? "bg-teal-50 text-teal-700" : "text-slate-500 hover:bg-slate-200/70 hover:text-slate-800"}`}
    >
      {children}
    </button>
  );
}

/** Adds, changes or removes the link on the selected text; with nothing selected the address itself is linked */
function LinkBar({ editor, onClose }: { editor: Editor; onClose: () => void }) {
  const current = (editor.getAttributes("link").href as string | undefined) ?? "";
  const [url, setUrl] = useState(current);

  function apply(e?: FormEvent) {
    e?.preventDefault();
    const raw = url.trim();
    if (!raw) {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      onClose();
      return;
    }
    const href = /^(https?:|mailto:)/i.test(raw) ? raw : raw.includes("@") && !raw.includes("/") ? `mailto:${raw}` : `https://${raw}`;
    if (editor.state.selection.empty && !editor.isActive("link")) {
      // Linked as typed: "example.com" reads better than "https://example.com"
      const label = raw.replace(/^mailto:/i, "");
      editor.chain().focus().insertContent({ type: "text", text: label, marks: [{ type: "link", attrs: { href } }] }).insertContent(" ").run();
    } else {
      editor.chain().focus().extendMarkRange("link").setLink({ href }).run();
    }
    onClose();
  }

  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 px-3 py-2">
      <Link2 className="h-4 w-4 shrink-0 text-slate-400" />
      <input
        className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
        placeholder="Paste a link, like example.com"
        value={url}
        onChange={(e) => setUrl(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") apply(e);
          if (e.key === "Escape") {
            e.preventDefault();
            e.stopPropagation();
            onClose();
            editor.commands.focus();
          }
        }}
        aria-label="Link address"
        autoFocus
      />
      {current ? (
        <button type="button" className="btn-ghost btn-sm" onClick={() => { setUrl(""); editor.chain().focus().extendMarkRange("link").unsetLink().run(); onClose(); }}>
          Remove link
        </button>
      ) : null}
      <button type="button" className="btn-secondary btn-sm" onClick={() => apply()}>
        Apply
      </button>
    </div>
  );
}

/** Posts the form with upload progress, which fetch can't report */
function upload(url: string, form: FormData, onProgress?: (percent: number) => void): Promise<{ ok: true } | { ok: false; error: string }> {
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", url);
    xhr.withCredentials = true;
    for (const [name, value] of Object.entries(apiHeaders(false))) xhr.setRequestHeader(name, value);
    if (onProgress) xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(Math.round((e.loaded / e.total) * 100));
    xhr.onload = () => {
      let data: { error?: string; message?: string; errors?: Record<string, string[]> } = {};
      try {
        data = JSON.parse(xhr.responseText);
      } catch {
        // not JSON: the status says enough
      }
      if (xhr.status === 401) {
        window.location.assign("/login");
        return resolve({ ok: false, error: "Your session has expired. Please sign in again." });
      }
      if (xhr.status === 413) return resolve({ ok: false, error: "The attachments are too big for the server. Share bigger files as a link." });
      if (xhr.status >= 200 && xhr.status < 300) return resolve({ ok: true });
      const first = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;
      resolve({ ok: false, error: data.error ?? first ?? data.message ?? `Request failed (${xhr.status})` });
    };
    xhr.onerror = () => resolve({ ok: false, error: "Network error. Check your connection and try again." });
    xhr.send(form);
  });
}
