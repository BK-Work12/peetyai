'use client';

import { cn } from '@/lib/utils';
import { useAuthStore } from '@/store/auth-store';
import { BarChart3, Boxes, Cog, LayoutDashboard, LogOut, MessageSquare, Package, ShoppingBag, Users } from 'lucide-react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';

type Role = 'owner' | 'retailer' | 'staff';

const links: Array<{ href: string; label: string; icon: typeof LayoutDashboard; roles: Role[] }> = [
  { href: '/dashboard',  label: 'Dashboard',   icon: LayoutDashboard, roles: ['owner', 'retailer', 'staff'] },
  { href: '/orders',     label: 'Live Orders',  icon: ShoppingBag,     roles: ['owner', 'retailer', 'staff'] },
  { href: '/chats',      label: 'Chats',        icon: MessageSquare,   roles: ['owner', 'retailer', 'staff'] },
  { href: '/customers',  label: 'Customers',    icon: Users,           roles: ['owner', 'retailer', 'staff'] },
  { href: '/inventory',  label: 'Inventory',    icon: Package,         roles: ['owner', 'retailer', 'staff'] },
  { href: '/analytics',  label: 'Analytics',    icon: BarChart3,       roles: ['owner', 'retailer', 'staff'] },
  { href: '/settings',   label: 'Settings',     icon: Cog,             roles: ['owner', 'retailer'] },
  { href: '/owner',      label: 'Owner Panel',  icon: Boxes,           roles: ['owner'] },
];

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const clearAuth = useAuthStore((s) => s.clearAuth);
  const role: Role = (user?.role as Role | undefined) ?? 'staff';

  function handleLogout() {
    clearAuth();
    router.push('/login');
  }

  const visibleLinks = links.filter((l) => l.roles.includes(role));

  return (
    <aside className="sticky top-0 hidden h-screen w-64 shrink-0 border-r p-6 lg:flex flex-col backdrop-blur-xl transition-colors border-[var(--sidebar-border)] bg-[var(--sidebar-bg)]">
      {/* Logo */}
      <div className="mb-8 flex items-center gap-2">
        <span className="flex size-8 items-center justify-center rounded-xl bg-[var(--accent)] text-[var(--accent-foreground)] font-bold text-sm">P</span>
        <p className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>PeetyAI</p>
      </div>

      {/* Nav */}
      <nav className="flex-1 space-y-1">
        {visibleLinks.map((link) => {
          const active = pathname === link.href;
          const Icon = link.icon;
          return (
            <Link
              key={link.href}
              href={link.href}
              className={cn(
                'flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition',
                active
                  ? 'bg-[var(--accent)] text-[var(--accent-foreground)] font-semibold'
                  : 'hover:bg-[rgba(37,211,102,0.10)]'
              )}
              style={!active ? { color: 'var(--text-medium)' } : undefined}
            >
              <Icon className="size-4" />
              {link.label}
            </Link>
          );
        })}
      </nav>

      {/* User + logout */}
      <div className="mt-4 space-y-3 border-t pt-4" style={{ borderColor: 'var(--sidebar-border)' }}>
        <div className="flex items-center gap-3 rounded-xl px-3 py-2" style={{ background: 'rgba(37,211,102,0.07)' }}>
          <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[var(--accent)]/20 text-xs font-bold" style={{ color: 'var(--accent)' }}>
            {user?.name?.[0] ?? '?'}
          </div>
          <div className="min-w-0">
            <p className="truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{user?.name ?? 'User'}</p>
            <p className="truncate text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{role}</p>
          </div>
        </div>
        <button
          onClick={handleLogout}
          className="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm transition hover:bg-red-500/10"
          style={{ color: '#f87171' }}
        >
          <LogOut className="size-4" />
          Sign Out
        </button>
      </div>
    </aside>
  );
}

