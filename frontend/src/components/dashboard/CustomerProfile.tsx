import { Card } from '@/components/ui/card';
import type { CustomerSnapshot } from '@/lib/types';

export function CustomerProfile({ customer }: { customer: CustomerSnapshot }) {
  return (
    <Card className="space-y-2">
      <h3 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>{customer.name}</h3>
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{customer.phone}</p>
      <div className="pt-3 text-sm" style={{ color: 'var(--text-medium)' }}>
        <p>Lifetime value: ${customer.lifetimeValue.toFixed(2)}</p>
        <p>Last order: {customer.lastOrderAt}</p>
      </div>
    </Card>
  );
}
