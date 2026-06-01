<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUnit;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class StockReceiptController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $receipts = StockReceipt::query()
            ->with(['lines.item'])
            ->latest()
            ->paginate(30);

        if ($request->expectsJson()) {
            return $this->successResponse([
                'receipts' => $receipts->items(),
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($receipts),
            ]);
        }

        return view('inventory::receipts.index', compact('receipts'));
    }

    public function create()
    {
        $items = Item::query()
            ->where('is_active', true)
            ->with('group')
            ->orderBy('name')
            ->get();

        if (request()->expectsJson()) {
            return $this->successResponse([
                'items' => $items,
            ]);
        }

        return view('inventory::receipts.create', compact('items'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reference'      => ['nullable', 'string', 'max:255'],
            'supplier'       => ['nullable', 'string', 'max:255'],
            'received_date'  => ['required', 'date'], //  required by your DB
            'notes'          => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id'      => ['required', 'integer', 'exists:inventory_items,id'],
            'lines.*.qty_received' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.serials'      => ['nullable', 'array'],
            'lines.*.serials.*'    => ['nullable', 'string', 'max:255'],
        ]);

        $user = auth('inventory')->user();

        InventoryDatabase::transaction(function () use ($data, $user) {

            // Build receipt payload, respecting your schema
            $receiptPayload = [
                'reference'      => $data['reference'] ?? null,
                'supplier'       => $data['supplier'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'received_date'  => $data['received_date'],   //  REQUIRED
                'received_by'    => $user?->id,
            ];

            // Optional: if your table also has received_at, set it.
            if (InventoryDatabase::schema()->hasColumn('inventory_stock_receipts', 'received_at')) {
                $receiptPayload['received_at'] = now();
            }

            $receipt = StockReceipt::create($receiptPayload);

            foreach ($data['lines'] as $idx => $line) {
                $item = Item::lockForUpdate()->findOrFail($line['item_id']);
                $qty  = (int) $line['qty_received'];

                // Normalize serials: trim + remove blanks
                $rawSerials = $line['serials'] ?? [];
                $serials = collect($rawSerials)
                    ->map(fn ($s) => trim((string) $s))
                    ->filter(fn ($s) => $s !== '')
                    ->values()
                    ->all();

                // If serialized: serials count MUST match qty
                if ($item->has_serial) {
                    if (count($serials) !== $qty) {
                        throw ValidationException::withMessages([
                            "lines.$idx.serials" =>
                                "Serials count must match qty received for '{$item->name}'. Qty = {$qty}, Serials provided = " . count($serials) . ".",
                        ]);
                    }

                    // Enforce unique serials within this line
                    $dupes = collect($serials)->duplicates()->values()->all();
                    if (!empty($dupes)) {
                        throw ValidationException::withMessages([
                            "lines.$idx.serials" =>
                                "Duplicate serial(s) in '{$item->name}': " . implode(', ', $dupes),
                        ]);
                    }

                    // Prevent receiving serials that already exist for this item
                    $existing = ItemUnit::query()
                        ->where('item_id', $item->id)
                        ->whereIn('serial_no', $serials)
                        ->pluck('serial_no')
                        ->all();

                    if (!empty($existing)) {
                        throw ValidationException::withMessages([
                            "lines.$idx.serials" =>
                                "These serial(s) already exist for '{$item->name}': " . implode(', ', $existing),
                        ]);
                    }
                }

                // Create line
                $receiptLinePayload = [
                    'stock_receipt_id' => $receipt->id,
                    'item_id'          => $item->id,
                    'qty_received'     => $qty,
                    'unit_cost'        => $line['unit_cost'] ?? null,
                ];

                // Optional: if your lines table also has received_date, set it.
                if (InventoryDatabase::schema()->hasColumn('inventory_stock_receipt_lines', 'received_date')) {
                    $receiptLinePayload['received_date'] = $data['received_date'];
                }

                StockReceiptLine::create($receiptLinePayload);

                // Update store qty
                $item->increment('qty_on_hand', $qty);

                // If serialized: create units + logs per unit
                if ($item->has_serial) {
                    foreach ($serials as $serial) {
                        $unit = ItemUnit::create([
                            'item_id'     => $item->id,
                            'serial_no'   => $serial,
                            'status'      => 'in_store',
                            'assigned_to' => null,
                            'assigned_at' => null,
                        ]);

                        InventoryLog::create([
                            'action'       => 'received',
                            'item_id'      => $item->id,
                            'item_unit_id' => $unit->id,
                            'qty'          => null,
                            'serial_no'    => $serial,
                            'from_user_id' => null,
                            'to_user_id'   => null,
                            'reference'    => $receipt->reference,
                            'notes'        => $data['notes'] ?? null,
                            'created_by'   => $user?->id,
                        ]);
                    }
                } else {
                    // Bulk receive log
                    InventoryLog::create([
                        'action'       => 'received',
                        'item_id'      => $item->id,
                        'item_unit_id' => null,
                        'qty'          => $qty,
                        'serial_no'    => null,
                        'from_user_id' => null,
                        'to_user_id'   => null,
                        'reference'    => $receipt->reference,
                        'notes'        => $data['notes'] ?? null,
                        'created_by'   => $user?->id,
                    ]);
                }
            }
        });

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Stock received successfully.', 201);
        }

        return redirect()->route('inventory.receipts.index')->with('success', 'Stock received successfully.');
    }

    public function show(StockReceipt $receipt)
    {
        $receipt->load(['lines.item.group']);

        if (request()->expectsJson()) {
            return $this->successResponse([
                'receipt' => $receipt,
            ]);
        }

        return view('inventory::receipts.show', compact('receipt'));
    }
}
