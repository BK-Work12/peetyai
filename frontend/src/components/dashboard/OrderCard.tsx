import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import type { DashboardOrder } from '@/lib/types';

export function OrderCard({ order, onClick }: { order: DashboardOrder; onClick?: () => void }) {
  return (
    <Card
      className="space-y-3 bg-[radial-gradient(circle_at_top_right,rgba(37,211,102,0.10),transparent_45%)] hover:border-[var(--accent)]/40 transition-colors cursor-pointer"
      onClick={onClick}
    >
      <div className="flex items-center justify-between">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Order #{order.id}</p>
        <Badge>{order.status}</Badge>
      </div>
      <div>
        <p className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>{order.customer}</p>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{order.items_count} items</p>
      </div>
      <p className="text-xl font-semibold" style={{ color: 'var(--accent)' }}>AED {order.total.toFixed(2)}</p>
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          onClick?.();
        }}
        className="w-full rounded-lg border px-3 py-2 text-sm font-medium transition hover:bg-white/5"
        style={{ borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
      >
        View details
      </button>
    </Card>
  );
}

