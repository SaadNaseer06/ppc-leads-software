import "@fontsource-variable/geist";
import "@fontsource-variable/geist-mono";
import { createRoot } from "react-dom/client";
import { createInertiaApp, type ResolvedComponent } from "@inertiajs/react";
import type { ReactNode } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { ConfirmProvider } from "@/components/ui/confirm";
import { ToastProvider } from "@/components/ui/toast";
import { setTimezone } from "@/lib/format";
import type { SharedProps } from "@/types";

type PageModule = { default: ResolvedComponent };
const pages = import.meta.glob<PageModule>("./Pages/**/*.tsx");

createInertiaApp({
  title: (title) => (title ? `${title} · PPC Leads` : "PPC Leads"),
  resolve: async (name) => {
    const page = await pages[`./Pages/${name}.tsx`]();
    // Every page except sign-in sits inside the persistent dashboard shell
    if (!name.startsWith("Auth/")) {
      page.default.layout ??= (child: ReactNode) => <DashboardLayout>{child}</DashboardLayout>;
    }
    return page.default;
  },
  setup({ el, App, props }) {
    setTimezone((props.initialPage.props as unknown as SharedProps).app.timezone);
    createRoot(el).render(
      <ToastProvider>
        <ConfirmProvider>
          <App {...props} />
        </ConfirmProvider>
      </ToastProvider>
    );
  },
  progress: { color: "#0d9488" },
});
