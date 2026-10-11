<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DemoController extends Controller
{
    public function __invoke(): View
    {
        $sample = config('opsdeck.import_samples.employees');
        $aliases = config('opsdeck.import_column_aliases.employees', []);
        $sampleColumns = [];
        foreach ($sample['headers'] as $index => $header) {
            $targetField = $aliases[$header] ?? null;
            $sampleColumns[] = [
                'header' => $header,
                'field' => is_string($targetField) ? config('opsdeck.import_field_labels.'.$targetField, $targetField) : '自動候補なし',
                'value' => $sample['row'][$index] ?? '',
            ];
        }

        $previewCategories = [];
        foreach (config('opsdeck.demo_dashboard.preview_categories', []) as $category) {
            $category['label'] = config('opsdeck.import_action_labels.'.$category['action'], $category['action']);
            $previewCategories[] = $category;
        }

        return view('demo.index', [
            'demo' => config('opsdeck.demo_dashboard'),
            'sampleColumns' => $sampleColumns,
            'previewCategories' => $previewCategories,
        ]);
    }
}
