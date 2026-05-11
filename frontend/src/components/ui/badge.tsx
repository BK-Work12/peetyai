import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'react';

export function Badge({ className, ...props }: HTMLAttributes<HTMLSpanElement>) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium border-[var(--accent)]/25 bg-[rgba(37,211,102,0.12)]',
        className
      )}
      style={{ color: 'var(--accent)' }}
      {...props}
    />
  );
}
