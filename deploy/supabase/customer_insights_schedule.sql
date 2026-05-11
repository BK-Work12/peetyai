-- Run in Supabase SQL editor.
-- Requires pg_cron extension enabled.

create table if not exists public.customer_insights (
  id bigserial primary key,
  retailer_id bigint not null,
  customer_id bigint not null,
  typical_order_frequency text,
  top_brands jsonb,
  avg_basket_value numeric(12,2) not null default 0,
  preferred_delivery_window text,
  last_order_at timestamptz,
  total_orders integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (retailer_id, customer_id)
);

create or replace function public.refresh_customer_insights_nightly()
returns void
language plpgsql
as $$
begin
  insert into public.customer_insights (
    retailer_id,
    customer_id,
    typical_order_frequency,
    top_brands,
    avg_basket_value,
    preferred_delivery_window,
    last_order_at,
    total_orders,
    updated_at
  )
  select
    o.retailer_id,
    o.customer_id,
    case
      when avg(g.day_gap) is null then 'insufficient_data'
      when avg(g.day_gap) <= 7 then 'weekly'
      when avg(g.day_gap) <= 20 then 'biweekly'
      when avg(g.day_gap) <= 45 then 'monthly'
      else 'occasional'
    end as typical_order_frequency,
    coalesce(b.top_brands, '[]'::jsonb) as top_brands,
    coalesce(avg(o.total), 0)::numeric(12,2) as avg_basket_value,
    mode() within group (
      order by case
        when extract(hour from coalesce(o.placed_at, o.created_at)) between 6 and 11 then 'morning'
        when extract(hour from coalesce(o.placed_at, o.created_at)) between 12 and 16 then 'afternoon'
        when extract(hour from coalesce(o.placed_at, o.created_at)) between 17 and 21 then 'evening'
        else 'night'
      end
    ) as preferred_delivery_window,
    max(coalesce(o.placed_at, o.created_at)) as last_order_at,
    count(*)::int as total_orders,
    now()
  from orders o
  left join lateral (
    select
      extract(epoch from (coalesce(o2.placed_at, o2.created_at) - lag(coalesce(o2.placed_at, o2.created_at)) over (
        partition by o2.retailer_id, o2.customer_id
        order by coalesce(o2.placed_at, o2.created_at)
      ))) / 86400.0 as day_gap
    from orders o2
    where o2.retailer_id = o.retailer_id
      and o2.customer_id = o.customer_id
  ) g on true
  left join lateral (
    select jsonb_agg(jsonb_build_object('brand', x.brand, 'orders', x.orders_count) order by x.orders_count desc) as top_brands
    from (
      select p.brand, count(*)::int as orders_count
      from order_items oi
      join orders o3 on o3.id = oi.order_id
      left join products p on p.id = oi.product_id
      where o3.retailer_id = o.retailer_id
        and o3.customer_id = o.customer_id
        and p.brand is not null
      group by p.brand
      order by count(*) desc
      limit 5
    ) x
  ) b on true
  where o.customer_id is not null
  group by o.retailer_id, o.customer_id, b.top_brands
  on conflict (retailer_id, customer_id)
  do update set
    typical_order_frequency = excluded.typical_order_frequency,
    top_brands = excluded.top_brands,
    avg_basket_value = excluded.avg_basket_value,
    preferred_delivery_window = excluded.preferred_delivery_window,
    last_order_at = excluded.last_order_at,
    total_orders = excluded.total_orders,
    updated_at = now();
end;
$$;

-- Every night at 02:00 UTC.
select cron.schedule(
  'refresh_customer_insights_nightly',
  '0 2 * * *',
  $$select public.refresh_customer_insights_nightly();$$
);
