import { useState } from "react";
import { Download } from "lucide-react";
import { apiHeaders, useToast } from "@/components/ui/toast";

export function BulkDownloadButton({ invoiceIds }: { invoiceIds: number[] }) {
  const toast = useToast();
  const [loading, setLoading] = useState(false);

  async function download() {
    setLoading(true);
    try {
      const res = await fetch("/api/finance/bulk-pdf", {
        method: "POST",
        headers: apiHeaders(true),
        credentials: "same-origin",
        body: JSON.stringify({ invoice_ids: invoiceIds }),
      });
      if (!res.ok) {
        toast.error("Bulk download failed. Please try again.");
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "invoices-bulk.zip";
      a.click();
      URL.revokeObjectURL(url);
    } finally {
      setLoading(false);
    }
  }

  return (
    <button type="button" className="btn-primary" onClick={download} disabled={loading || invoiceIds.length === 0}>
      <Download className="h-4 w-4" />
      {loading ? "Preparing zip…" : "Download PDFs"}
    </button>
  );
}
