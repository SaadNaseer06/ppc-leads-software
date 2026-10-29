import type { FormEvent, ReactNode } from "react";
import { Head, useForm } from "@inertiajs/react";
import { BarChart3, Loader2, MessageSquare, Users, Zap } from "lucide-react";

const DEMO_ACCOUNTS = [
  ["superadmin@ppc.local", "Super Admin"],
  ["admin@ppc.local", "Admin"],
  ["front1@ppc.local", "Front Sales"],
  ["upsales@ppc.local", "Up Sales"],
  ["finance@ppc.local", "Finance"],
] as const;

export default function Login({ showDemo }: { showDemo: boolean }) {
  const form = useForm({ email: "", password: "" });
  const error = form.errors.email ?? form.errors.password;

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    form.post("/login", { onFinish: () => form.reset("password") });
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-[1fr_minmax(480px,560px)]">
      <Head title="Sign in" />
      <div className="relative hidden overflow-hidden bg-sidebar lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div className="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-teal-500/10 blur-3xl" />
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-600 text-white">
            <Zap className="h-5 w-5" />
          </span>
          <span className="text-lg font-semibold text-white">PPC Leads</span>
        </div>
        <div className="max-w-md">
          <h2 className="text-3xl font-semibold leading-tight tracking-tight text-white">
            Every PPC lead, from every website, in one place.
          </h2>
          <ul className="mt-8 space-y-5 text-slate-300">
            <Feature icon={<Users className="h-4 w-4" />} title="Capture">
              Form submissions from all your websites, with the Google Ads click and campaign attached.
            </Feature>
            <Feature icon={<MessageSquare className="h-4 w-4" />} title="Chat">
              Tawk.to chats and offline messages become leads the moment a visitor asks a question.
            </Feature>
            <Feature icon={<BarChart3 className="h-4 w-4" />} title="Assign & track">
              Route leads to your team, follow every touchpoint and see which campaigns convert.
            </Feature>
          </ul>
        </div>
        <p className="text-xs text-slate-500">Internal use only. Access is logged.</p>
      </div>

      <div className="flex items-center justify-center bg-white px-6 py-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-2.5 lg:hidden">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-600 text-white">
              <Zap className="h-5 w-5" />
            </span>
            <span className="text-lg font-semibold text-slate-900">PPC Leads</span>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Sign in</h1>
          <p className="mt-1 text-sm text-slate-500">Use the email and password your admin gave you.</p>

          <form onSubmit={onSubmit} className="mt-8 space-y-4">
            <div>
              <label className="label" htmlFor="email">Email</label>
              <input
                id="email"
                className="input"
                type="email"
                autoComplete="email"
                value={form.data.email}
                onChange={(e) => form.setData("email", e.target.value)}
                required
                autoFocus
              />
            </div>
            <div>
              <label className="label" htmlFor="password">Password</label>
              <input
                id="password"
                className="input"
                type="password"
                autoComplete="current-password"
                value={form.data.password}
                onChange={(e) => form.setData("password", e.target.value)}
                required
              />
            </div>
            {error ? (
              <p className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {error}
              </p>
            ) : null}
            <button type="submit" className="btn-primary w-full py-2.5" disabled={form.processing}>
              {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
              {form.processing ? "Signing in…" : "Sign in"}
            </button>
          </form>

          {showDemo ? (
            <div className="mt-8 rounded-xl border border-dashed border-slate-300 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Demo accounts · password123</p>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {DEMO_ACCOUNTS.map(([email, label]) => (
                  <button
                    key={email}
                    type="button"
                    className="badge cursor-pointer hover:bg-slate-200"
                    onClick={() => form.setData({ email, password: "password123" })}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function Feature({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/10 text-teal-300">
        {icon}
      </span>
      <div>
        <p className="font-medium text-white">{title}</p>
        <p className="text-sm leading-6">{children}</p>
      </div>
    </li>
  );
}
