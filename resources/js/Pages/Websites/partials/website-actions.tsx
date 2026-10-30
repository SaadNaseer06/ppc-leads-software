import { useState } from "react";
import { router } from "@inertiajs/react";
import { FlaskConical, RefreshCw, Trash2 } from "lucide-react";
import { useConfirm } from "@/components/ui/confirm";
import { apiHeaders, apiRequest, useToast } from "@/components/ui/toast";

export function DeleteWebsiteButton({
  websiteId,
  websiteName,
}: {
  websiteId: number;
  websiteName: string;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busy, setBusy] = useState(false);

  async function remove() {
    const ok = await confirm({
      title: `Remove “${websiteName}”?`,
      description:
        "Existing leads stay in the system but are unlinked from this website. Embed snippets and webhooks for this site stop working immediately.",
      confirmLabel: "Remove website",
      tone: "danger",
    });
    if (!ok) return;
    setBusy(true);
    const res = await apiRequest(`/api/websites/${websiteId}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success("Website removed");
    router.visit("/websites");
  }

  return (
    <button type="button" className="btn-danger btn-sm" onClick={remove} disabled={busy}>
      <Trash2 className={`h-3.5 w-3.5 ${busy ? "animate-pulse" : ""}`} />
      {busy ? "Removing…" : "Remove website"}
    </button>
  );
}

export function RotateKeyButton({ websiteId }: { websiteId: number }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busy, setBusy] = useState(false);

  async function rotate() {
    const ok = await confirm({
      title: "Generate a new website key?",
      description: "The current embed snippet and webhook URL will stop working until you update them on the site.",
      confirmLabel: "Generate new key",
      tone: "danger",
    });
    if (!ok) return;
    setBusy(true);
    const res = await apiRequest(`/api/websites/${websiteId}`, { method: "PATCH", body: { rotate_key: true } });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success("New key generated. Update the snippet on your website.");
    router.reload();
  }

  return (
    <button type="button" className="btn-danger btn-sm" onClick={rotate} disabled={busy}>
      <RefreshCw className={`h-3.5 w-3.5 ${busy ? "animate-spin" : ""}`} />
      Generate new key
    </button>
  );
}

/** Posts a sample submission through the real public endpoint, exactly like a website would */
export function SendTestLeadButton({ endpoint, disabled }: { endpoint: string; disabled: boolean }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  async function send() {
    setBusy(true);
    try {
      const res = await fetch(endpoint, {
        method: "POST",
        // Same-site page: send the CSRF token, as the endpoint sees our session
        headers: { ...apiHeaders(false), "Content-Type": "text/plain;charset=UTF-8" },
        credentials: "same-origin",
        body: JSON.stringify({
          fields: {
            name: "Test Lead",
            email: `test+${Date.now()}@example.com`,
            message: "This is a test submission sent from the website settings page.",
          },
          attribution: {
            utm_source: "google",
            utm_medium: "cpc",
            utm_campaign: "setup-test",
            landing_page: `${window.location.origin}/?utm_source=google&utm_medium=cpc&utm_campaign=setup-test`,
          },
          form: { name: "Setup test" },
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        toast.error(data.error || `Test failed (${res.status})`);
        return;
      }
      toast.success("Test lead received");
      router.visit(`/leads/${data.lead_id}`);
    } catch {
      toast.error("Could not reach the intake endpoint. Check APP_URL in your environment.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" className="btn-secondary" onClick={send} disabled={busy || disabled}>
      <FlaskConical className="h-4 w-4" />
      {busy ? "Sending…" : "Send test lead"}
    </button>
  );
}
