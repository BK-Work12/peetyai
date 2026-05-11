'use client';

import { AnalyticsCharts } from '@/components/dashboard/AnalyticsCharts';
import { Card } from '@/components/ui/card';
import { fetchRetailerStats } from '@/lib/api';
import { useAuthStore } from '@/store/auth-store';
import { useQuery } from '@tanstack/react-query';

const statCards = [
  { key: 'total_orders', label: 'Total Orders' },
  { key: 'revenue', label: 'Revenue' },
  { key: 'live_orders', label: 'Live Orders' },
  { key: 'low_stock', label: 'Low Stock SKUs' },
] as const;

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user);
  const retailerId = user?.retailer_id ?? 1;

  const { data } = useQuery({
    queryKey: ['retailer-dashboard', retailerId],
    queryFn: () => fetchRetailerStats(retailerId),
  });

  return (
    <div className="space-y-5">
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {statCards.map((stat) => (
          <Card key={stat.key}>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            <p className="mt-2 text-3xl font-semibold" style={{ color: 'var(--accent)' }}>{data?.stats?.[stat.key] ?? '--'}</p>
          </Card>
        ))}
      </section>
      <AnalyticsCharts />
    </div>
  );
}
