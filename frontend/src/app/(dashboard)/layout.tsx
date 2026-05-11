'use client';

import { AuthGuard } from '@/components/dashboard/AuthGuard';
import { Sidebar } from '@/components/dashboard/Sidebar';
import { ThemeToggle } from '@/components/dashboard/ThemeToggle';
import { useAuthStore } from '@/store/auth-store';
import type { ReactNode } from 'react';

const roleLabel: Record<string, string> = {
  owner:    '👑 Super Admin',
  retailer: '🏪 Retailer Admin',
  staff:    '👤 Staff',
};

function DashboardHeader() {
  const user = useAuthStore((s) => s.user);
  const role = user?.role ?? 'staff';
  return (
    <header
      className="mb-6 flex items-center justify-between rounded-2xl px-5 py-3.5 transition-colors"
      style={{ background: 'var(--header-bg)', borderBottom: '1px solid var(--header-border)' }}
    >
      <div>
        <p className="text-xs uppercase tracking-[0.2em] text-white/60">AI Grocery Command Center</p>
        <h1 className="text-lg font-semibold text-white">
          {user?.name ?? 'Retail Operations'}
        </h1>
      </div>
      <div className="flex items-center gap-3">
        <span
          className="hidden rounded-full px-3 py-1 text-xs font-semibold sm:inline-block"
          style={{ background: 'rgba(37,211,102,0.18)', color: '#25d366' }}
        >
          {roleLabel[role] ?? role}
        </span>
        <ThemeToggle />
      </div>
    </header>
  );
}

export default function DashboardLayout({ children }: { children: ReactNode }) {
  return (
    <AuthGuard>
      <div className="flex min-h-screen">
        <Sidebar />
        <main className="flex-1 px-4 py-4 md:px-6 md:py-6">
          <DashboardHeader />
          {children}
        </main>
      </div>
    </AuthGuard>
  );
}

