<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\ImportExportService;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;

class ImportExportController extends Controller
{
    use ApiResponder;

    /**
     * Export items as CSV
     */
    public function exportItems(Request $request)
    {
        try {
            return ImportExportService::exportItems();
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to export items: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Import items from CSV
     */
    public function importItems(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // 5MB max
        ]);

        try {
            $file = $data['file'];
            $results = ImportExportService::importItems($file);

            return $this->successResponse([
                'imported' => $results['imported'],
                'failed' => $results['failed'],
                'errors' => $results['errors'],
            ], 'Items imported.', 200, [
                'total_processed' => $results['imported'] + $results['failed'],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to import items: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Export receipts as CSV
     */
    public function exportReceipts(Request $request)
    {
        try {
            return ImportExportService::exportReceipts();
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to export receipts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Export item groups as CSV
     */
    public function exportGroups(Request $request)
    {
        try {
            return ImportExportService::exportGroups();
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to export groups: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get import template
     */
    public function getImportTemplate(Request $request)
    {
        $type = $request->get('type', 'items'); // items, receipts, groups

        $templates = [
            'items' => [
                'columns' => [
                    'SKU' => 'string, (required, unique)',
                    'Name' => 'string, (required)',
                    'Description' => 'string',
                    'Item Group' => 'string (will be created if not exists)',
                    'Unit Cost' => 'float',
                    'Quantity' => 'integer',
                    'Reorder Level' => 'integer',
                    'Status' => 'Active|Inactive',
                ],
                'example' => [
                    ['ROUTER001', 'TR-001 Router', 'Entry level router', 'Routers', '1500.00', '50', '10', 'Active'],
                    ['SWITCH001', 'TP-Link Switch', 'Managed switch', 'Switches', '2500.00', '20', '5', 'Active'],
                ],
            ],
            'receipts' => [
                'columns' => [
                    'Item SKU' => 'string',
                    'Item Name' => 'string',
                    'Quantity' => 'integer',
                    'Unit Cost' => 'float',
                    'Supplier' => 'string (optional)',
                ],
                'example' => [
                    ['ROUTER001', 'TR-001 Router', '10', '1500.00', 'Supplier A'],
                ],
            ],
            'groups' => [
                'columns' => [
                    'Code' => 'string',
                    'Name' => 'string (required)',
                    'Description' => 'string',
                    'Status' => 'Active|Inactive',
                ],
                'example' => [
                    ['RT', 'Routers', 'Network routers', 'Active'],
                ],
            ],
        ];

        $template = $templates[$type] ?? $templates['items'];

        return $this->successResponse([
            'template' => $template,
            'type' => $type,
            'encoding' => 'UTF-8',
            'delimiter' => ',',
            'quote_char' => '"',
        ]);
    }
}
