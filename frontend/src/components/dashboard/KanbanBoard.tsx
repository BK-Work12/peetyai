'use client';

import { OrderCard } from '@/components/dashboard/OrderCard';
import type { DashboardOrder, OrderStatus } from '@/lib/types';
import { useOrderStore } from '@/store/order-store';
import {
  DndContext,
  DragEndEvent,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { useEffect } from 'react';

const columns: { title: string; key: OrderStatus }[] = [
  { title: 'Placed', key: 'placed' },
  { title: 'Picking', key: 'picking' },
  { title: 'Packed', key: 'packed' },
  { title: 'Dispatched', key: 'dispatched' },
  { title: 'Delivered', key: 'delivered' },
];

function DraggableOrder({ order, onOrderClick }: { order: DashboardOrder; onOrderClick?: (id: number) => void }) {
  const { attributes, listeners, setNodeRef, transform } = useDraggable({
    id: String(order.id),
  });

  const style = {
    transform: CSS.Translate.toString(transform),
  };

  return (
    <div ref={setNodeRef} style={style} {...listeners} {...attributes}>
      <OrderCard order={order} onClick={() => onOrderClick?.(order.id)} />
    </div>
  );
}

function DroppableColumn({
  id,
  title,
  children,
}: {
  id: OrderStatus;
  title: string;
  children: React.ReactNode;
}) {
  const { isOver, setNodeRef } = useDroppable({ id });

  return (
    <section
      ref={setNodeRef}
      className={`rounded-2xl border p-3 transition ${
        isOver ? 'border-[var(--accent)] bg-[rgba(37,211,102,0.08)]' : 'border-[var(--card-border)] bg-[var(--card-bg)]'
      }`}
    >
      <h3 className="mb-3 text-sm font-semibold" style={{ color: 'var(--text-medium)' }}>{title}</h3>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

export function KanbanBoard({
  orders,
  onOrderClick,
  onMoveOrder,
}: {
  orders: DashboardOrder[];
  onOrderClick?: (id: number) => void;
  onMoveOrder?: (orderId: number, status: OrderStatus) => void;
}) {
  const { orders: stateOrders, setOrders, moveOrder } = useOrderStore();
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    })
  );

  useEffect(() => {
    setOrders(orders);
  }, [orders, setOrders]);

  function onDragEnd(event: DragEndEvent) {
    const activeId = Number(event.active.id);
    const overId = event.over?.id as OrderStatus | undefined;

    if (activeId && overId && columns.some((column) => column.key === overId)) {
      moveOrder(activeId, overId);
      onMoveOrder?.(activeId, overId);
    }
  }

  return (
    <DndContext onDragEnd={onDragEnd} sensors={sensors}>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        {columns.map((column) => (
          <DroppableColumn key={column.key} id={column.key} title={column.title}>
            {stateOrders
              .filter((order) => order.status === column.key)
              .map((order) => (
                <DraggableOrder key={order.id} order={order} onOrderClick={onOrderClick} />
              ))}
          </DroppableColumn>
        ))}
      </div>
    </DndContext>
  );
}
