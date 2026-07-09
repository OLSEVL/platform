<?php

namespace Tests\Unit;

use App\Services\DataCleanupService;
use PHPUnit\Framework\TestCase;

class DataCleanupServiceTest extends TestCase
{
    public function test_normalize_retention_hours_accepts_allowed_values(): void
    {
        $service = new DataCleanupService();

        $this->assertSame(0, $service->normalizeRetentionHours(0));
        $this->assertSame(1, $service->normalizeRetentionHours(1));
        $this->assertSame(3, $service->normalizeRetentionHours(3));
        $this->assertSame(6, $service->normalizeRetentionHours(6));
        $this->assertSame(12, $service->normalizeRetentionHours(12));
        $this->assertSame(24, $service->normalizeRetentionHours(24));
        $this->assertSame(48, $service->normalizeRetentionHours(48));
    }

    public function test_normalize_retention_hours_falls_back_to_default(): void
    {
        $service = new DataCleanupService();

        $this->assertSame(DataCleanupService::DEFAULT_RETENTION_HOURS, $service->normalizeRetentionHours(99));
        $this->assertSame(DataCleanupService::DEFAULT_RETENTION_HOURS, $service->normalizeRetentionHours(-1));
    }

    public function test_format_result_message_includes_all_sections_for_platform_cleanup(): void
    {
        $service = new DataCleanupService();

        $message = $service->formatResultMessage([
            'candles' => 10,
            'logs' => 2,
            'analysis_runs' => 3,
            'trades' => 4,
            'signals' => 5,
            'bot_runs' => 1,
            'retention_hours' => 24,
        ]);

        $this->assertStringContainsString('24 ч.', $message);
        $this->assertStringContainsString('свечи=10', $message);
        $this->assertStringContainsString('LLM/анализ=5', $message);
    }

    public function test_format_result_message_for_full_purge(): void
    {
        $service = new DataCleanupService();

        $message = $service->formatResultMessage([
            'candles' => 100,
            'logs' => 1,
            'analysis_runs' => 2,
            'trades' => 3,
            'signals' => 4,
            'bot_runs' => 5,
            'retention_hours' => 0,
        ]);

        $this->assertStringContainsString('Удалены все данные', $message);
        $this->assertStringContainsString('свечи=100', $message);
    }

    public function test_normalize_auto_cleanup_retention_hours(): void
    {
        $service = new DataCleanupService();

        $this->assertNull($service->normalizeAutoCleanupRetentionHours(null));
        $this->assertNull($service->normalizeAutoCleanupRetentionHours(''));
        $this->assertSame(24, $service->normalizeAutoCleanupRetentionHours(24));
        $this->assertSame(1, $service->normalizeAutoCleanupRetentionHours('1'));
        $this->assertNull($service->normalizeAutoCleanupRetentionHours(0));
        $this->assertNull($service->normalizeAutoCleanupRetentionHours(99));
    }

    public function test_auto_cleanup_retention_label(): void
    {
        $service = new DataCleanupService();

        $this->assertSame('Ничего не удалять', $service->autoCleanupRetentionLabel(null));
        $this->assertSame('24 часа', $service->autoCleanupRetentionLabel(24));
        $this->assertSame('1 час', $service->autoCleanupRetentionLabel(1));
    }
}
