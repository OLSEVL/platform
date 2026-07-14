<?php

namespace App\Http\Controllers;

use App\Models\ProxyEndpoint;
use App\Models\UserTradingSetting;
use App\Support\MirrorProxyMeta;
use App\Support\PocketMirrorProxy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileMirrorProxiesController extends Controller
{
    public function edit(Request $request): View
    {
        $settings = $this->settingsForUser($request->user()->id);

        $selectedMirrorProxyIds = old('mirror_proxy_endpoint_ids', $settings->mirrorProxyEndpointIds());
        if (! is_array($selectedMirrorProxyIds)) {
            $selectedMirrorProxyIds = [];
        }

        $mirrorProxyAuto = (bool) old('mirror_proxy_auto', $settings->mirror_proxy_auto);
        // Кандидаты с min нагрузкой + история фактических auto-выборов для подсветки строк.
        $autoCandidateIds = $mirrorProxyAuto ? PocketMirrorProxy::leastLoadedActiveIds() : [];
        $autoPickCounts = $mirrorProxyAuto ? $settings->mirrorProxyAutoPickCounts() : [];
        $autoLastId = $mirrorProxyAuto
            ? (int) ($settings->mirror_proxy_auto_last_endpoint_id ?? 0)
            : 0;

        return view('profile.mirror-proxies', [
            'settings' => $settings,
            'mirrorProxies' => $this->activeMirrorProxyRows($autoCandidateIds, $autoPickCounts, $autoLastId),
            'selectedMirrorProxyIds' => array_map('intval', $selectedMirrorProxyIds),
            'mirrorProxyAuto' => $mirrorProxyAuto,
            'autoLastAt' => $settings->mirror_proxy_auto_last_at,
            'autoLastId' => $autoLastId,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mirror_proxy_auto' => ['sometimes', 'boolean'],
            'mirror_proxy_endpoint_ids' => ['nullable', 'array'],
            'mirror_proxy_endpoint_ids.*' => [
                'integer',
                Rule::exists('proxy_endpoints', 'id')->where('status', ProxyEndpoint::STATUS_ACTIVE),
            ],
        ]);

        $settings = $this->settingsForUser($request->user()->id);
        $auto = $request->boolean('mirror_proxy_auto');
        $settings->mirror_proxy_auto = $auto;

        // При авто disabled-чекбоксы не приходят в POST — ручной пул не затираем.
        if (! $auto) {
            $settings->mirror_proxy_endpoint_ids = array_values(array_map(
                'intval',
                $validated['mirror_proxy_endpoint_ids'] ?? []
            ));
        }

        $settings->save();

        return redirect()
            ->route('profile.mirror-proxies.edit')
            ->with('status', $auto
                ? 'Авто-выбор прокси включён (минимальная нагрузка среди active).'
                : 'Пул прокси для зеркала сохранён.');
    }

    /**
     * @param  list<int>  $autoCandidateIds
     * @param  array<int, int>  $autoPickCounts
     * @return list<array{
     *   id:int,
     *   label:string,
     *   port:?int,
     *   url_masked:string,
     *   instruments_count:int,
     *   failure_count:int,
     *   is_auto_candidate:bool,
     *   is_auto_last:bool,
     *   auto_pick_count:int
     * }>
     */
    private function activeMirrorProxyRows(
        array $autoCandidateIds = [],
        array $autoPickCounts = [],
        int $autoLastId = 0,
    ): array {
        $candidateSet = array_fill_keys($autoCandidateIds, true);

        return ProxyEndpoint::query()
            ->readyForAssignment()
            ->withCount('instruments')
            ->orderBy('instruments_count')
            ->orderBy('id')
            ->get()
            ->map(function (ProxyEndpoint $proxy) use ($candidateSet, $autoPickCounts, $autoLastId): array {
                $meta = MirrorProxyMeta::forTradeMeta($proxy->url);
                $id = (int) $proxy->id;

                return [
                    'id' => $id,
                    'label' => (string) ($meta['mirror_proxy_host'] ?? ('#'.$proxy->id)),
                    'port' => $meta['mirror_proxy_port'] ?? null,
                    'url_masked' => (string) ($meta['mirror_proxy_url'] ?? ''),
                    'instruments_count' => (int) $proxy->instruments_count,
                    'failure_count' => (int) ($proxy->failure_count ?? 0),
                    'is_auto_candidate' => isset($candidateSet[$id]),
                    'is_auto_last' => $autoLastId > 0 && $id === $autoLastId,
                    'auto_pick_count' => (int) ($autoPickCounts[$id] ?? 0),
                ];
            })
            ->all();
    }

    private function settingsForUser(int $userId): UserTradingSetting
    {
        return UserTradingSetting::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'is_demo' => true,
                'order_amount' => 1,
                'order_amounts' => [1.0],
                'martingale_step' => 0,
                'mirror_trades_enabled' => false,
                'mirror_wait_previous_settled' => false,
                'mirror_proxy_auto' => false,
            ]
        );
    }
}
