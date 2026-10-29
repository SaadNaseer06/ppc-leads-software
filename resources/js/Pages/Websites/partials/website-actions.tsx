import { useState } from "react";
import { router } from "@inertiajs/react";
import { FlaskConical, RefreshCw } from "lucide-react";
import { apiHeaders, apiRequest, useToast } from "@/components/ui/toast";

export function RotateKeyButton({ websiteId }: { websiteId: number }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  async function rotate() {
    if (!window.confirm("Generate a new key? The current embed snippet and webhook URL will stop working.")) return;
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
