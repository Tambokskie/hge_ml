<?php

interface ExtractorInterface
{
    /**
     * @return array{status: 'ok'|'failed', rows: array<array{graduate_name: string, latin_honor: string, graduation_date: ?string}>}
     */
    public function extract(string $filePath): array;
}
