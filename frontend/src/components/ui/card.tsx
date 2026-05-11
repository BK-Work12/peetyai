import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'react';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'rounded-2xl border p-5 backdrop-blur-xl transition-colors',
        'border-[var(--card-border)] bg-[var(--card-bg)] shadow-[var(--card-shadow)]',
        className
      )}
      {...props}
    />
  );
}
