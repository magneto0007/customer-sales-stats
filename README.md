# Magneto_CustomerSalesStats — Design Note

## What it does

Adds a **Customer Lifetime Revenue** column to the Magento Admin Sales Order Grid.
The value is the sum of all `complete` orders for that customer (matched by email,
across all websites), expressed in the global base currency.

---

## Improvements
1) Use a Queue-based system to track orders to process instead of using DB.
2) Use custom logger
3) Use custom cron group
4) Handle refunds
5) Add Enable/ Disable config
6) Implement Model / Collection / Repository

## Drawbacks
In ability to regenerate.

---

## Requirement / Assumptions for multi website multi currency setup

1) Multi website setup
2) Catalog price scope set to website
3) Base currency configured
4) Required currency rates configured


## Data strategy

### Two-table approach

| Table | Purpose |
|---|---|
| `clr_order_complete_queue` | Lightweight write queue. Populated synchronously when an order transitions to `complete`. |
| `clr_customer_lifetime_revenue` | Pre-aggregated totals, keyed on `customer_email`. Read by the grid via LEFT JOIN. |

### Why email as the key?

A customer can place orders as a guest or as a registered account. Both paths
always carry `customer_email` on the order. Using email as the natural key means
guest orders and registered orders for the same person are unified automatically,
with no extra lookup required.

### Currency normalisation

Each queued row stores `base_grand_total` and `base_to_global_rate` captured at
the moment the order completes. The cron multiplies these via `RevenueCalculator`
to produce a global-currency value before writing to the aggregate. This mirrors
how Magento itself handles multi-currency reporting and avoids any dependency on
live exchange rates at read time.

---

## How the transition is detected

An **observer on `sales_order_save_after`** (`OrderCompleteObserver`) fires on
every order save — regardless of whether the save originates from the admin panel
(invoice/shipment creation), the frontend checkout, REST/GraphQL API, or cron.

A plugin on `OrderRepositoryInterface::save()` was considered but rejected because
internal Magento code usage makes it unliable.

The observer delegates to two service classes:

- **`OrderStateDetector::isNewlyCompleted()`** — compares `getOrigData('state')`
  (the last-loaded value, never overwritten during save) against the current state
  to confirm this is a genuine new transition into `complete`.
- **`QueueWriter::enqueue()`** — writes a single `INSERT ON DUPLICATE KEY` to the
  queue table. The unique constraint on `order_id` makes this fully idempotent.
  Exceptions are caught and logged — queue writes never interrupt the order save.

---

## Cron processing

The cron job (`*/30 * * * *`) is orchestrated by `ProcessQueue`, which delegates to:

- **`QueueReader`** — claims batches using `SELECT ... FOR UPDATE SKIP LOCKED`
  (raw SQL, since Magento's `Select::forUpdate()` does not support `SKIP LOCKED`).
  Also handles deleting processed rows, marking invalid rows as exhausted, and
  incrementing retry counts on failure.
- **`RevenueCalculator`** — converts `base_grand_total × base_to_global_rate` into
  a global-currency figure, with a fallback rate of 1.0 and rounding to 4dp
  (matching `sales_order.base_grand_total` precision).
- **`RevenueAggregator`** — issues a single multi-row `insertOnDuplicate()` with a
  `\Zend_Db_Expr` increment expression. One query per batch regardless of how many
  unique emails are in it.

The entire claim-process-delete cycle runs inside a single transaction so the
`FOR UPDATE` lock is held for the full duration. If the batch fails, the
transaction is rolled back and `retry_count` is incremented. Rows that reach
`MAX_RETRIES` (3) are excluded from future fetches and logged at `critical` level
for manual review.

---

## Grid integration

A **plugin on `Order\Grid\Collection::beforeLoad()`** (`OrderGridCollectionPlugin`)
adds a `LEFT JOIN` to the aggregate table on `customer_email`. It is scoped to
`etc/adminhtml/di.xml` so it never loads on the frontend.

Guards:
- `isLoaded()` check prevents the join being applied after the collection has
  already loaded.
- `getPart('from')` check prevents the join being added twice in the same request
  (e.g. during grid export).

The join condition does NOT use `LOWER()` because values are normalised to
lowercase at write time. This keeps the unique index on `customer_email` sargable.

The **`LifetimeRevenue` column class** loads the global base currency from
`currency/options/base` config (default scope), caches the `Currency` model for
the request, and formats the value with `Currency::format($value, [], false)` —
`false` for `$includeContainer` so the output is plain text (e.g. `£105.30`)
rather than HTML-wrapped.

---

## Schema

Defined via `db_schema.xml` (declarative schema) with a matching
`db_schema_whitelist.json`. Both tables use `resource="sales"` for split-database
compatibility.

### `clr_order_complete_queue`
- Primary key: `queue_id` (auto-increment)
- Unique constraint on `order_id` (idempotent inserts)
- Foreign key to `sales_order.entity_id` with `CASCADE` delete
- Composite index on `(retry_count, queue_id)` for the `claimBatch()` query
- `retry_count` column for retries

### `clr_customer_lifetime_revenue`
- Primary key: `clr_id` (auto-increment)
- Unique constraint on `customer_email`
- No FK to `customer_entity` (intentional — guest orders have no customer row) : This can be optionally implemented but for the sake of this exercise we omit this.

---

## Service layer

| Class | Responsibility |
|---|---|
| `Service\OrderStateDetector` | Detects whether an order has just transitioned to `complete` |
| `Service\QueueWriter` | Writes to the queue table with idempotent insert |
| `Service\QueueReader` | Claims, deletes, marks exhausted, increments retries on queue rows |
| `Service\RevenueCalculator` | Converts base currency to global currency |
| `Service\RevenueAggregator` | Multi-row upsert into the aggregate table |

Each class has a single responsibility and is independently testable.

---

## Scalability

- The aggregate table has at most one row per unique customer email — it does not
  grow with order volume.
- The queue table is kept small by the cron; at steady state it holds at most
  ~30 minutes of completed orders.
- The grid join is a single indexed lookup per row
- No runtime aggregation queries are executed when the grid loads.
- Concurrent cron workers are safe via `FOR UPDATE SKIP LOCKED`.

---

## Extensibility

Adding further metrics (Average Order Value, Total Orders, Last Order Date)
requires only:

1. New columns on `clr_customer_lifetime_revenue` (via `db_schema.xml`).
2. Updated `RevenueAggregator` logic to populate those columns.
3. New UI component column definitions.

No structural changes to the queue, observer, or reader are needed. The queue
already captures everything required to compute any order-level aggregate.
