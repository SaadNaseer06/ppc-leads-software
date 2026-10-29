import { Head, Link } from "@inertiajs/react";
import { SearchX } from "lucide-react";
import { EmptyState } from "@/components/ui/page-header";

export default function NotFound() {
  return (
    <div className="card">
      <Head title="Not found" />
      <EmptyState
        icon={<SearchX className="h-5 w-5" />}
        title="Not found"
        description="It may have been reassigned to someone else, or you don't have access to its brand."
        action={
          <Link href="/dashboard" className="btn-secondary">
            Back to the dashboard
          </Link>
        }
      />
    </div>
  );
}
