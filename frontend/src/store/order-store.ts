import type { DashboardOrder, OrderStatus } from '@/lib/types';
import { create } from 'zustand';

type OrderStore = {
  orders: DashboardOrder[];
  setOrders: (orders: DashboardOrder[]) => void;
  moveOrder: (id: number, status: OrderStatus) => void;
};

export const useOrderStore = create<OrderStore>((set) => ({
  orders: [],
  setOrders: (orders) =>
    set((state) => {
      if (state.orders.length === orders.length) {
        const unchanged = state.orders.every((existing, index) => {
          const incoming = orders[index];

          return incoming
            && existing.id === incoming.id
            && existing.status === incoming.status
            && existing.total === incoming.total
            && existing.items_count === incoming.items_count
            && existing.customer === incoming.customer;
        });

        if (unchanged) {
          return state;
        }
      }

      return { orders };
    }),
  moveOrder: (id, status) =>
    set((state) => ({
      orders: state.orders.map((order) => (order.id === id ? { ...order, status } : order)),
    })),
}));
