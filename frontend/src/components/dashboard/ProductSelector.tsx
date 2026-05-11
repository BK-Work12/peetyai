'use client';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { ProductOption } from '@/lib/types';
import { useState } from 'react';

type Props = {
  options: ProductOption[];
  onSelect: (productId: number) => void;
};

export function ProductSelector({ options, onSelect }: Props) {
  const [selectedId, setSelectedId] = useState<number | null>(null);

  return (
    <Card>
      <p className="mb-3 text-sm font-medium" style={{ color: 'var(--text-medium)' }}>Smart Options</p>
      <div className="space-y-2">
        {options.map((option, index) => {
          const selected = selectedId === option.id;
          return (
            <button
              key={option.id}
              className={`w-full rounded-xl border px-3 py-2 text-left text-sm transition ${
                selected
                  ? 'border-[var(--accent)] bg-[rgba(37,211,102,0.15)]'
                  : 'border-[var(--card-border)] bg-[var(--card-bg)]'
              }`}
              style={{ color: selected ? 'var(--text-primary)' : 'var(--text-muted)' }}
              onClick={() => setSelectedId(option.id)}
            >
              <span className="mr-2" style={{ color: 'var(--accent)' }}>{index + 1}.</span>
              {option.brand ? `${option.brand} ` : ''}
              {option.name}
            </button>
          );
        })}
      </div>
      <Button className="mt-4 w-full" onClick={() => selectedId && onSelect(selectedId)}>
        Confirm Selection
      </Button>
    </Card>
  );
}
