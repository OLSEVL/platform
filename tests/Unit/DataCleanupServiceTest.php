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

    /**
     * Окно авто-очистки в днях (120/168/…) должно проходить в purge без сброса на 24 ч.
     */
    public function test_normalize_retention_hours_accepts_auto_cleanup_day_windows(): void
    {
        $service = new DataCleanupService();

        $this->assertSame(72, $service->normalizeRetentionHours(72));   // 3 дня
        $this->assertSame(120, $service->normalizeRetentionHours(120)); // 5 дней
        $this->assertSame(168, $service->normalizeRetentionHours(168)); // 7 дней
        $this->assertSame(720, $service->normalizeRetentionHours(720)); // 30 дней
        $this->assertSame(1680, $service->normalizeRetentionHours(1680)); // 70 дней
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
        $this->assertSame(720, $service->normalizeAutoCleanupRetentionHours(720)); // 30 дней
        $this->assertSame(1680, $service->normalizeAutoCleanupRetentionHours(1680)); // 70 дней
        $this->assertSame(72, $service->normalizeAutoCleanupRetentionHours('72')); // 3 дня
        $this->assertNull($service->normalizeAutoCleanupRetentionHours(0));
        $this->assertNull($service->normalizeAutoCleanupRetentionHours(99));
    }

    public function test_auto_cleanup_retention_label(): void
    {
        $service = new DataCleanupService();

        $this->assertSame('Ничего не удалять', $service->autoCleanupRetentionLabel(null));
        $this->assertSame('1 день (24 часа)', $service->autoCleanupRetentionLabel(24));
        $this->assertSame('2 дня (48 часов)', $service->autoCleanupRetentionLabel(48));
        $this->assertSame('5 дней (120 часов)', $service->autoCleanupRetentionLabel(120));
        $this->assertSame('21 день (504 часа)', $service->autoCleanupRetentionLabel(504));
        $this->assertSame('30 дней (720 часов)', $service->autoCleanupRetentionLabel(720));
        $this->assertSame('70 дней (1680 часов)', $service->autoCleanupRetentionLabel(1680));
        $this->assertSame('1 час', $service->autoCleanupRetentionLabel(1));
    }

    public function test_allowed_auto_cleanup_includes_one_to_max_days(): void
    {
        $hours = DataCleanupService::allowedAutoCleanupRetentionHours();
        $maxHours = DataCleanupService::MAX_AUTO_CLEANUP_RETENTION_DAYS * 24;

        $this->assertContains(24, $hours);
        $this->assertContains(48, $hours);
        $this->assertContains(720, $hours);
        $this->assertContains($maxHours, $hours); // 70 дней
        $this->assertContains(1, $hours);
        // N суток + 12/6/3/1 ч.
        $this->assertCount(DataCleanupService::MAX_AUTO_CLEANUP_RETENTION_DAYS + 4, $hours);
        $this->assertSame($maxHours, $hours[0]); // сначала самый длинный период
    }

    /**
     * chartHistoryMaxSeconds без БД: при отсутствии настройки getAutoCleanup
     * вернёт DEFAULT 24ч → 86400 сек (поведение по умолчанию для графика).
     */
    public function test_chart_history_max_seconds_uses_default_when_unset(): void
    {
        $service = new class extends DataCleanupService
        {
            public function getAutoCleanupRetentionHours(): ?int
            {
                return self::DEFAULT_AUTO_CLEANUP_RETENTION_HOURS;
            }
        };

        $this->assertSame(24 * 3600, $service->chartHistoryMaxSeconds());
    }

    public function test_chart_history_max_seconds_follows_retention_and_disabled_cap(): void
    {
        $sevenDays = new class extends DataCleanupService
        {
            public function getAutoCleanupRetentionHours(): ?int
            {
                return 168;
            }
        };
        $this->assertSame(168 * 3600, $sevenDays->chartHistoryMaxSeconds());

        // «Ничего не удалять» → потолок MAX дней селекта, не бесконечность.
        $disabled = new class extends DataCleanupService
        {
            public function getAutoCleanupRetentionHours(): ?int
            {
                return null;
            }
        };
        $this->assertSame(
            DataCleanupService::MAX_AUTO_CLEANUP_RETENTION_DAYS * 24 * 3600,
            $disabled->chartHistoryMaxSeconds()
        );
    }

    public function test_candles_storage_depth_label_for_empty_stats(): void
    {
        $service = new DataCleanupService();

        $this->assertSame('нет данных', $service->candlesStorageDepthLabel(['count' => 0]));
    }

    public function test_candles_storage_depth_label_formats_hours_and_days(): void
    {
        $service = new DataCleanupService();

        $this->assertSame('~64 часа', $service->candlesStorageDepthLabel([
            'count' => 1000,
            'depth_hours' => 63.8,
        ]));
        $this->assertSame('7 дней (168 часов)', $service->candlesStorageDepthLabel([
            'count' => 5000,
            'depth_hours' => 168.0,
        ]));
        $this->assertSame('~12 часов', $service->candlesStorageDepthLabel([
            'count' => 100,
            'depth_hours' => 11.6,
        ]));
    }
}
