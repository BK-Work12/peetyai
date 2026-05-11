'use client';

export function ThemeToggle() {
  return (
    <div className="flex items-center gap-2 rounded-full border border-[var(--accent)]/30 bg-[var(--accent)]/10 px-3 py-1.5">
      <span className="size-2 rounded-full bg-[var(--accent)] shadow-[0_0_6px_var(--accent)]"></span>
      <span className="text-xs font-medium" style={{ color: 'var(--accent)' }}>Live</span>
    </div>
  );
}
