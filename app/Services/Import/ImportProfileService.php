<?php

namespace App\Services\Import;

use App\Models\ImportProfile;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ImportProfileService
{
    public function sourceSignature(?string $selectedSheetName, array $headers, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): string
    {
        $signatureParts = [
            'sheet' => $selectedSheetName,
            'header_row_number' => $headerRowNumber,
            'data_start_row_number' => $dataStartRowNumber ?? ($headerEndRowNumber ?? $headerRowNumber) + 1,
            'headers' => array_values($headers),
        ];
        if (($headerEndRowNumber ?? $headerRowNumber) !== $headerRowNumber) {
            $signatureParts['header_end_row_number'] = $headerEndRowNumber;
        }

        return hash('sha256', json_encode($signatureParts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return Collection<int, ImportProfile>
     */
    public function matchingProfiles(Organization $organization, string $sourceSignature): Collection
    {
        return ImportProfile::query()
            ->where('organization_id', $organization->id)
            ->where('source_signature', $sourceSignature)
            ->orderBy('name')
            ->orderBy('target_type')
            ->orderByDesc('version')
            ->get();
    }

    /**
     * @param  list<string>  $targetTypes
     * @return list<string>
     */
    public function targetTypesWithDifferentLayoutProfiles(Organization $organization, ?string $selectedSheetName, string $sourceSignature, array $targetTypes): array
    {
        $query = ImportProfile::query()
            ->where('organization_id', $organization->id)
            ->whereIn('target_type', $targetTypes)
            ->where('source_signature', '!=', $sourceSignature);

        if ($selectedSheetName === null) {
            $query->whereNull('selected_sheet_name');
        } else {
            $query->where('selected_sheet_name', $selectedSheetName);
        }

        return $query->distinct()->orderBy('target_type')->pluck('target_type')->all();
    }

    /**
     * @param  array<string, string|null>  $columnMap
     * @param  array<string, mixed>  $normalizationRules
     * @param  array<string, list<array{source: string, target: string}>>  $valueMappings
     * @param  list<string>  $headers
     */
    public function saveVersion(
        Organization $organization,
        ?int $profileId,
        string $sourceSignature,
        ?string $selectedSheetName,
        int $headerRowNumber,
        int $dataStartRowNumber,
        int $headerEndRowNumber,
        array $headers,
        string $targetType,
        string $name,
        array $columnMap,
        array $normalizationRules,
        array $valueMappings,
        int $userId,
    ): ImportProfile {
        $existingProfile = $profileId === null
            ? null
            : ImportProfile::query()
                ->where('organization_id', $organization->id)
                ->where('source_signature', $sourceSignature)
                ->where('target_type', $targetType)
                ->findOrFail($profileId);

        if ($existingProfile === null) {
            $profileKey = (string) Str::uuid();
            $version = 1;
        } else {
            $latestProfile = ImportProfile::query()
                ->where('organization_id', $organization->id)
                ->where('profile_key', $existingProfile->profile_key)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->firstOrFail();
            $profileKey = $latestProfile->profile_key;
            $version = $latestProfile->version + 1;
            $name = $latestProfile->name;
        }

        return ImportProfile::query()->create([
            'organization_id' => $organization->id,
            'profile_key' => $profileKey,
            'version' => $version,
            'name' => $name,
            'target_type' => $targetType,
            'source_signature' => $sourceSignature,
            'header_row_number' => $headerRowNumber,
            'header_end_row_number' => $headerEndRowNumber,
            'data_start_row_number' => $dataStartRowNumber,
            'selected_sheet_name' => $selectedSheetName,
            'source_headers' => array_values($headers),
            'column_map' => $columnMap,
            'normalization_rules' => $normalizationRules,
            'value_mappings' => $valueMappings,
            'created_by' => $userId,
        ]);
    }
}
