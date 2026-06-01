<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExportService
{
    /**
     * Export items to CSV
     */
    public static function exportItems(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename*=UTF-8\'\'items_' . now()->format('Y-m-d_His') . '.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'SKU',
                'Name',
                'Description',
                'Item Group',
                'Unit Cost',
                'Quantity',
                'Reorder Level',
                'Status',
            ]);

            Item::query()->chunk(100, function (Collection $items) use ($file) {
                foreach ($items as $item) {
                    fputcsv($file, [
                        $item->sku,
                        $item->name,
                        $item->description,
                        optional($item->group)->name,
                        $item->unit_cost,
                        $item->quantity,
                        $item->reorder_level,
                        $item->is_active ? 'Active' : 'Inactive',
                    ]);
                }
            });

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * Import items from CSV file
     */
    public static function importItems(UploadedFile $file)
    {
        $results = [
            'imported' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
            $header = fgetcsv($handle);
            $rowNum = 2;

            while (($row = fgetcsv($handle)) !== false) {
                try {
                    if (empty(trim($row[0] ?? ''))) {
                        continue; // Skip empty rows
                    }

                    $groupName = trim($row[3] ?? '');
                    $group = null;

                    if ($groupName) {
                        $group = ItemGroup::firstOrCreate(['name' => $groupName]);
                    }

                    Item::create([
                        'sku' => trim($row[0] ?? ''),
                        'name' => trim($row[1] ?? 'Unknown'),
                        'description' => trim($row[2] ?? ''),
                        'item_group_id' => $group?->id,
                        'unit_cost' => (float) (trim($row[4] ?? 0)),
                        'quantity' => (int) (trim($row[5] ?? 0)),
                        'reorder_level' => (int) (trim($row[6] ?? 0)),
                        'is_active' => strtolower(trim($row[7] ?? 'active')) === 'active',
                    ]);

                    $results['imported']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $rowNum,
                        'error' => $e->getMessage(),
                    ];
                }

                $rowNum++;
            }

            fclose($handle);
        }

        return $results;
    }

    /**
     * Export stock receipts to CSV
     */
    public static function exportReceipts(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename*=UTF-8\'\'receipts_' . now()->format('Y-m-d_His') . '.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Receipt ID',
                'Receipt Date',
                'Item SKU',
                'Item Name',
                'Quantity',
                'Unit Cost',
                'Total Cost',
                'Received By',
            ]);

            StockReceipt::query()->with('lines', 'user')->chunk(50, function (Collection $receipts) use ($file) {
                foreach ($receipts as $receipt) {
                    foreach ($receipt->lines as $line) {
                        fputcsv($file, [
                            $receipt->id,
                            $receipt->created_at->format('Y-m-d'),
                            optional($line->item)->sku,
                            optional($line->item)->name,
                            $line->quantity,
                            $line->unit_cost,
                            $line->quantity * $line->unit_cost,
                            optional($receipt->user)->name,
                        ]);
                    }
                }
            });

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * Export item groups to CSV
     */
    public static function exportGroups(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename*=UTF-8\'\'groups_' . now()->format('Y-m-d_His') . '.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Group Code',
                'Group Name',
                'Description',
                'Status',
            ]);

            ItemGroup::query()->chunk(100, function (Collection $groups) use ($file) {
                foreach ($groups as $group) {
                    fputcsv($file, [
                        $group->code,
                        $group->name,
                        $group->description,
                        $group->is_active ? 'Active' : 'Inactive',
                    ]);
                }
            });

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
}
