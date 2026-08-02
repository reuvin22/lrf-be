<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Normalization + fuzzy matching + alias-learning for invoice vendor/site
 * names (spec §3). Aliases are only ever written from confirmSubcontractorMapping()/
 * confirmSiteMapping() — i.e. a user finalizing a mapping — never during
 * extraction, so a raw OCR guess can never silently become a permanent alias.
 */
class InvoiceNameMatchingService
{
    public const PRESELECT_THRESHOLD = 0.8;

    public const CANDIDATE_THRESHOLD = 0.5;

    private const SUBCONTRACTOR_ALIAS_SHEET = 'SubcontractorAliases';

    private const SUBCONTRACTOR_MASTER_SHEET = 'SubContractors';

    private const SITE_ALIAS_SHEET = 'SiteAliases';

    private const SITE_MASTER_SHEET = 'ConstructionSites';

    // Corporate suffixes stripped before comparison — full/half-width variants included.
    private const CORPORATE_SUFFIXES = '/(株式会社|合同会社|合資会社|合名会社|有限会社|一般社団法人|公益社団法人|一般財団法人|特定非営利活動法人|\(株\)|（株）|㈱|\(有\)|（有）)/u';

    private ?array $subcontractorAliases = null;

    private ?array $subcontractors = null;

    private ?array $siteAliases = null;

    private ?array $sites = null;

    public function __construct(private GoogleSheetService $sheet) {}

    private function spreadsheetId(): string
    {
        return config('services.google_sheets.spreadsheet_id');
    }

    /**
     * Normalize a raw vendor/site name for comparison: unify full/half-width
     * characters and hiragana/katakana, strip corporate suffixes, drop all
     * whitespace, lowercase.
     */
    public function normalize(string $name): string
    {
        // K: half-width katakana -> full-width, V: collapse voiced-mark combos,
        // C: full-width hiragana -> full-width katakana, A: full-width alnum -> half-width.
        $name = mb_convert_kana($name, 'KVCA');
        $name = preg_replace(self::CORPORATE_SUFFIXES, '', $name) ?? $name;
        $name = preg_replace('/\s+/u', '', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    /**
     * @return array<int, array{id: ?string, name: string, score: float, source: string, preselect: bool}>
     */
    public function candidatesForSubcontractor(?string $rawName): array
    {
        return $this->candidates(
            $rawName,
            $this->loadSubcontractorAliases(),
            'subcontractor_id',
            'subcontractor_name',
            $this->loadSubcontractors(),
            'subcontractor_id',
            'company_name'
        );
    }

    /**
     * @return array<int, array{id: ?string, name: string, score: float, source: string, preselect: bool}>
     */
    public function candidatesForSite(?string $rawName): array
    {
        return $this->candidates(
            $rawName,
            $this->loadSiteAliases(),
            'site_id',
            'site_name',
            $this->loadSites(),
            'site_id',
            'site_name'
        );
    }

    public function confirmSubcontractorMapping(string $rawName, string $subcontractorId, ?string $documentId): void
    {
        $this->confirmMapping(
            $rawName,
            $subcontractorId,
            $documentId,
            self::SUBCONTRACTOR_ALIAS_SHEET,
            $this->loadSubcontractorAliases(),
            'subcontractor_id',
            $this->loadSubcontractors(),
            'subcontractor_id',
            'company_name'
        );
        $this->subcontractorAliases = null;
    }

    public function confirmSiteMapping(string $rawName, string $siteId, ?string $documentId): void
    {
        $this->confirmMapping(
            $rawName,
            $siteId,
            $documentId,
            self::SITE_ALIAS_SHEET,
            $this->loadSiteAliases(),
            'site_id',
            $this->loadSites(),
            'site_id',
            'site_name'
        );
        $this->siteAliases = null;
    }

    /**
     * Insert a new vendor into the SubContractors master ("grow while using").
     */
    public function createSubcontractor(string $companyName): string
    {
        $id = (string) Str::uuid();

        $this->sheet->appendRow($this->spreadsheetId(), self::SUBCONTRACTOR_MASTER_SHEET, [
            $id, $companyName, '', '', 'ACTIVE',
        ]);

        $this->subcontractors = null;

        return $id;
    }

    /**
     * Insert a new site into the ConstructionSites master ("grow while using").
     */
    public function createSite(string $siteName): string
    {
        $id = (string) Str::uuid();

        $this->sheet->appendRow($this->spreadsheetId(), self::SITE_MASTER_SHEET, [
            $id, '', $siteName, '', '', '', 'PREPARING', '', '', '', '',
        ]);

        $this->sites = null;

        return $id;
    }

    public function subcontractorName(string $id): ?string
    {
        return collect($this->loadSubcontractors())->firstWhere('subcontractor_id', $id)['company_name'] ?? null;
    }

    public function siteName(string $id): ?string
    {
        return collect($this->loadSites())->firstWhere('site_id', $id)['site_name'] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $aliasRows
     * @param  array<int, array<string, mixed>>  $masterRows
     * @return array<int, array{id: ?string, name: string, score: float, source: string, preselect: bool}>
     */
    private function candidates(
        ?string $rawName,
        array $aliasRows,
        string $aliasIdColumn,
        string $aliasNameColumn,
        array $masterRows,
        string $masterIdColumn,
        string $masterNameColumn
    ): array {
        if ($rawName === null || trim($rawName) === '') {
            return [];
        }

        $normalized = $this->normalize($rawName);

        foreach ($aliasRows as $row) {
            if (($row['raw_name_normalized'] ?? '') === $normalized && ! empty($row[$aliasIdColumn])) {
                $id = $row[$aliasIdColumn];
                $master = collect($masterRows)->firstWhere($masterIdColumn, $id);

                return [[
                    'id' => $id,
                    'name' => $master[$masterNameColumn] ?? $row[$aliasNameColumn] ?? '',
                    'score' => 1.0,
                    'source' => 'alias',
                    'preselect' => true,
                ]];
            }
        }

        $scored = [];

        foreach ($masterRows as $row) {
            $name = $row[$masterNameColumn] ?? '';
            if ($name === '') {
                continue;
            }

            $score = $this->similarity($normalized, $this->normalize($name));
            if ($score >= self::CANDIDATE_THRESHOLD) {
                $scored[] = [
                    'id' => $row[$masterIdColumn] ?? null,
                    'name' => $name,
                    'score' => round($score, 3),
                    'source' => 'fuzzy',
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach ($scored as $i => $candidate) {
            $scored[$i]['preselect'] = $i === 0 && $candidate['score'] >= self::PRESELECT_THRESHOLD;
        }

        return $scored;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existingAliasRows
     * @param  array<int, array<string, mixed>>  $masterRows
     */
    private function confirmMapping(
        string $rawName,
        string $id,
        ?string $documentId,
        string $aliasSheet,
        array $existingAliasRows,
        string $idColumn,
        array $masterRows,
        string $masterIdColumn,
        string $masterNameColumn
    ): void {
        $normalized = $this->normalize($rawName);

        foreach ($existingAliasRows as $row) {
            if (($row['raw_name_normalized'] ?? '') === $normalized && ($row[$idColumn] ?? null) === $id) {
                return; // identical alias already exists — never write a duplicate.
            }
        }

        $name = collect($masterRows)->firstWhere($masterIdColumn, $id)[$masterNameColumn] ?? '';

        $this->sheet->appendRow($this->spreadsheetId(), $aliasSheet, [
            (string) Str::uuid(),
            $rawName,
            $normalized,
            $id,
            $name,
            $documentId ?? '',
            Carbon::now('Asia/Manila')->toDateTimeString(),
        ]);
    }

    private function loadSubcontractorAliases(): array
    {
        return $this->subcontractorAliases ??= $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::SUBCONTRACTOR_ALIAS_SHEET);
    }

    private function loadSubcontractors(): array
    {
        return $this->subcontractors ??= $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::SUBCONTRACTOR_MASTER_SHEET);
    }

    private function loadSiteAliases(): array
    {
        return $this->siteAliases ??= $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::SITE_ALIAS_SHEET);
    }

    private function loadSites(): array
    {
        return $this->sites ??= $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::SITE_MASTER_SHEET);
    }

    /**
     * UTF-8-safe Levenshtein distance. PHP's built-in levenshtein() operates
     * on bytes, which silently corrupts multibyte Japanese comparisons.
     */
    private function utf8Levenshtein(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $la = count($a);
        $lb = count($b);

        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }

        $prev = range(0, $lb);

        for ($i = 1; $i <= $la; $i++) {
            $curr = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $curr[$j] = min(
                    $prev[$j] + 1,
                    $curr[$j - 1] + 1,
                    $prev[$j - 1] + $cost
                );
            }
            $prev = $curr;
        }

        return $prev[$lb];
    }

    private function similarity(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }

        return 1 - ($this->utf8Levenshtein($a, $b) / $maxLen);
    }
}
