/** Product mark: geometric monogram (not a lightning/AI motif). */
export function BrandMark({ className = "h-4 w-4" }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden>
      {/* Abstract lead funnel: intake → route → convert */}
      <path
        d="M5 4.75h14a1 1 0 0 1 .8 1.6L14.2 14.5V18a1 1 0 0 1-.55.89l-3 1.5A1 1 0 0 1 9 19.5v-5L4.2 6.35A1 1 0 0 1 5 4.75Z"
        fill="currentColor"
        opacity="0.95"
      />
      <path d="M8.25 8.25h7.5" stroke="white" strokeWidth="1.5" strokeLinecap="round" opacity="0.55" />
      <path d="M9.75 11.25h4.5" stroke="white" strokeWidth="1.5" strokeLinecap="round" opacity="0.4" />
    </svg>
  );
}
