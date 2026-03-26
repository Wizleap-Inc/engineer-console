<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Utils\NotionDatabase;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class NotifyDevelopPoint extends Command
{
    protected $signature = 'slack:notifyDevelopPoint {--channel=notifyTest}';

    protected $description = '開発ポイントの進捗をSlack通知する';

    public function __construct(
        private Member $member
    ) {
        parent::__construct();
    }

    public function handle()
    {
        // Backlogから今週タスクについての情報を取得
        [
            'targetPoint' => $targetPoint,
            'totalPoint' => $totalPoint,
            'donePoint' => $donePoint,
            'memberTasks' => $memberTasks
        ] = $this->getBacklogRecordDetails();

        // 取得した情報からSlack通知用のデータを生成
        $mainPayloads = $this->generateMainPayload($targetPoint, $totalPoint, $donePoint);
        $memberPointPayloads = $this->generateMemberPointPayload($memberTasks);
        // メインブロックの中にメンバーごとのポイント情報を追加
        array_splice($mainPayloads['blocks'], 2, 0, $memberPointPayloads);

        // Slackに通知
        $headers = ['Content-type' => 'application/json'];
        $url = collect(config('slack.channels'))
            ->first(fn($channel) =>
            $channel['key'] === $this->option('channel'))['webhookUrl'];
        $response = Http::withHeaders($headers)->post($url, $mainPayloads);

        // 失敗時に再度通知
        if ($response->status() >= 300) {
            $failedContents = config('slack.template.developPoint.failed');
            Http::withHeaders($headers)->post($url, $failedContents);
        }
    }


    private function getBacklogRecordDetails()
    {
        $notionApiUrl = config('notion.api.url');
        $backlogDatabaseUrl = config('notion.api.backlogDatabaseUrl');
        $parentDatabaseUrl = config('notion.api.parentDatabaseUrl');

        $backlogEndpoint = "{$notionApiUrl}/databases/{$backlogDatabaseUrl}/query";
        $parentEndPoint = "{$notionApiUrl}/databases/{$parentDatabaseUrl}/query";

        $notionHttp = NotionDatabase::http();

        // Backlog数値管理から、当日を含めた次の火曜日のページIDを取得
        $nextTuesday = Carbon::now()->subHours(36)->next(Carbon::TUESDAY);
        $parentPayload = config('notion.payload.parent');
        $parentPayload['filter']['rich_text']['equals'] = $nextTuesday->format('Y/m/d');
        $parentResponse = $notionHttp->post($parentEndPoint, $parentPayload);
        $parentPageId = $parentResponse['results'][0]['id'];

        // Backlogから、次の火曜日のページIDの子ページを取得（ページネーション対応）
        $allResults = collect();
        $startCursor = null;
        $hasMore = true;

        while ($hasMore) {
            $backlogPayload = config('notion.payload.backlog.progress');
            $backlogPayload['filter']['and'][0]['relation']['contains'] = $parentPageId;

            // ページネーション用のstart_cursorを設定
            if ($startCursor) {
                $backlogPayload['start_cursor'] = $startCursor;
            }

            $backlogResponse = $notionHttp->post($backlogEndpoint, $backlogPayload);

            // 結果を追加
            $allResults = $allResults->merge($backlogResponse['results']);

            // 次のページがあるかチェック
            $hasMore = $backlogResponse['has_more'] ?? false;
            $startCursor = $backlogResponse['next_cursor'] ?? null;
        }

        $members = $this->member
            ->with('offDates')
            ->where('is_valid', 1)
            ->get();

        $tasks = $allResults
            ->map(fn($result) => [
                'title' => $result['properties']['Backlog']['title'][0]['plain_text'] ?? 'タイトルなし',
                'user' => $members->first(fn($member) => $member->notion_id === ($result['properties']['Manager']['people'][0]['id'] ?? null)),
                'point' => $result['properties']['Point']['number'],
                'isDone' => !!$result['properties']['InReview Date']['relation']
            ])
            ->filter(fn($task) => $task['user']);

        $targetPoint = $members
            ->map(function ($member) use ($nextTuesday) {
                $offDates = $member->offDates->pluck('date')->map(fn($date) => $date->format('Y-m-d'));
                $businessDayCount = collect([0, 1, 4, 5, 6])
                    ->reject(fn($day) => $offDates->contains(
                        $nextTuesday
                            ->copy()
                            ->subDays($day)
                            ->format('Y-m-d')
                    ))
                    ->count();

                return $member->target_point * $businessDayCount / 5;
            })
            ->sum();
        $totalPoint = $tasks->sum('point');
        $donePoint = $tasks->filter(fn($task) => $task['isDone'])->sum('point');

        $memberTasks = $tasks
            ->groupBy(fn($task) => $task['user']['notion_id'])
            ->map(function ($tasks) use ($nextTuesday) {
                $user = $tasks[0]['user'];
                $offDates = $user->offDates->pluck('date')->map(fn($date) => $date->format('Y-m-d'));
                $businessDayCount = collect([0, 1, 4, 5, 6])
                    ->reject(fn($day) => $offDates->contains(
                        $nextTuesday
                            ->copy()
                            ->subDays($day)
                            ->format('Y-m-d')
                    ))
                    ->count();
                $targetPoint = $user->target_point * $businessDayCount / 5;

                return [
                    'name' => $user->name,
                    'imageUrl' => $user->image_url,
                    'targetPoint' => $targetPoint,
                    'totalPoint' => $tasks->sum('point'),
                    'donePoint' => $tasks->filter(fn($task) => $task['isDone'])->sum('point')
                ];
            })
            ->sortByDesc(fn($member) => $member['targetPoint'] === 0 ? 0 : round($member['donePoint'] / $member['targetPoint'], 2))
            ->values();

        return [
            'targetPoint' => $targetPoint,
            'totalPoint' => $totalPoint,
            'donePoint' => $donePoint,
            'memberTasks' => $memberTasks
        ];
    }

    private function generateMainPayload(float $targetPoint, float $totalPoint, float $donePoint): array
    {
        $mainTemplate = json_encode(config('slack.template.developPoint.main'));

        $targetRate = (int)$targetPoint === 0 ? 0 : round($donePoint / $targetPoint, 2);
        $doneRate = (int)$totalPoint === 0 ? 0 : round($donePoint / $totalPoint, 2);
        $targetRateCount = floor($targetRate * 10) > 10 ? 10 : floor($targetRate * 10);
        $doneRateStars = str_repeat(':star:', $targetRateCount) . str_repeat(':black_star:', 10 - $targetRateCount);

        $replace = [
            '%targetPoint%' => $targetPoint,
            '%totalPoint%' => $totalPoint,
            '%donePoint%' => $donePoint,
            '%targetRate%' => $targetRate * 100 . "%",
            '%doneRate%' => $doneRate * 100 . "%",
            '%doneRateStars%' => $doneRateStars,
            '%eol%' => '\n',
        ];

        return json_decode(strtr($mainTemplate, $replace), true);
    }

    private function generateMemberPointPayload(Collection $memberTasks): array
    {
        return collect($memberTasks)
            ->flatMap(function ($member): array {
                $memberTemplate = json_encode(config('slack.template.developPoint.member'));

                [
                    $name,
                    $imageUrl,
                    $totalPoint,
                    $donePoint,
                    $targetPoint
                ] = [
                    $member['name'],
                    $member['imageUrl'],
                    $member['totalPoint'],
                    $member['donePoint'],
                    $member['targetPoint']
                ];
                $doneRate = $totalPoint === 0 ? 0 : round($donePoint / $totalPoint, 2);
                $targetRate = $targetPoint === 0 ? 0 : round($donePoint / $targetPoint, 2);
                $targetRateCount = floor($targetRate * 10) > 10 ? 10 : floor($targetRate * 10);
                $doneRateStars = str_repeat(':star:', $targetRateCount) . str_repeat(':black_star:', 10 - $targetRateCount);

                $replace = [
                    '%memberName%' => $name,
                    '%imageUrl%' => $imageUrl,
                    '%totalPoint%' => $totalPoint,
                    '%targetPoint%' => $targetPoint,
                    '%donePoint%' => $donePoint,
                    '%targetRate%' => $targetRate * 100 . "%",
                    '%doneRate%' => $doneRate * 100 . "%",
                    '%doneRateStars%' => $doneRateStars,
                    '%eol%' => '\n',
                ];

                return json_decode(strtr($memberTemplate, $replace), true);
            })
            ->toArray();
    }
}
