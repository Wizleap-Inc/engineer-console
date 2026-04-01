<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Utils\NotionDatabase;
use App\Utils\SlackNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyRoadmap extends Command
{
    protected $signature = 'slack:notifyRoadmap {--channel=notifyTest}';

    protected $description = '開発ロードマップの進捗をSlack通知する';

    public function __construct(
        private Member $member
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $members = Member::select(['notion_id', 'name', 'slack_id'])->get();

        $roadmapDatabase = new NotionDatabase(config('notion.api.roadmapDatabaseUrl'));

        $roadmap = $roadmapDatabase
            ->setPayload($this->getRoadmapPayload())
            ->get();

        $formattedReleaseSchedules = $roadmap
            ->map(function ($roadmap) use ($members) {
                $userId = $roadmap['properties']['責任者']['people'][0]['id'] ?? null;

                $title = $roadmap['properties']['Name']['title'][0]['plain_text'];
                // 20文字以上は切って3点
                $shortTitle = (mb_strlen($title) > 40
                    ? mb_substr($title, 0, 40) . "…"
                    : $title) ?? "タイトルなし";

                $status = $roadmap['properties']['Status']['select']['name'] ?? "不明";

                $releaseDate = Carbon::parse($roadmap['properties']['リリース日']['date']['start']);

                $isDelayed = $releaseDate->isPast() && $status !== "リリース済";

                return [
                    'url' => $roadmap['url'],
                    'releaseDate' => $releaseDate->isoFormat('YYYY/MM/DD (ddd)'),
                    'slackId' =>  $members->firstWhere('notion_id', $userId)->slack_id ?? "",
                    'name' => $members->firstWhere('notion_id', $userId)->name ?? "",
                    'title' => $shortTitle,
                    'status' => $status,
                    'isDelayed' => $isDelayed,
                    'delayMark' => $isDelayed ? "⚠️" : "",
                ];
            })
            ->sortBy('releaseDate')
            ->groupBy('releaseDate')
            ->map(function ($schedules, $releaseDate) {
                $scheduleRows = $schedules
                    ->sortBy(fn($s) => $this->getStatusPriority($s['status']))
                    ->map(function ($s) {
                        $prefixIcon = $this->getStatusIcon($s['status']);
                        $name = $s['isDelayed'] ? "<@{$s['slackId']}>" : $s['name'];
                        return "【{$prefixIcon} *{$s['status']}* 】 {$name} - {$s['delayMark']}<{$s['url']}|*{$s['title']}*>";
                    });

                return collect([
                    ":spiral_calendar_pad: *{$releaseDate}*",
                    "",
                    ...$scheduleRows
                ])->join(PHP_EOL);
            })
            ->join(PHP_EOL . PHP_EOL . PHP_EOL);

        $slackMessage = collect([
            "直近のリリース予定です。",
            "担当のロードマップを確認し、ステータス更新や準備を行なってください。",
            "⚠️マークがついている場合はスレッドに理由を記載の上、リリース予定日を更新してください。",
            PHP_EOL,
            "*<https://www.notion.so/wizleap/" . config('notion.api.roadmapDatabaseUrl') . "|🥳開発ロードマップ>*",
            PHP_EOL,
            $formattedReleaseSchedules,
        ])->join(PHP_EOL);

        $url = collect(config('slack.channels'))
            ->first(fn($channel) =>
            $channel['key'] === $this->option('channel'))['webhookUrl'];
        $slackNotifier = new SlackNotifier($url);
        $slackNotifier
            ->setMessage($slackMessage)
            ->setAppName('開発ロードマップ')
            ->setIconEmoji(':rocket:')
            ->send();
    }

    private function getRoadmapPayload()
    {
        $nextBusinessDay = (
            Carbon::today()->isFriday()
            ? Carbon::today()->addDays(3)
            : Carbon::today()->addDays(1)
        )->format('Y-m-d');

        return [
            "filter" => [
                "and" => [
                    [
                        "property" => "リリース日",
                        "date" => [
                            "on_or_before" => $nextBusinessDay
                        ]
                    ],
                    [
                        "property" => "Status",
                        "select" => [
                            "does_not_equal" => "リリース済"
                        ]
                    ],
                    [
                        "property" => "Status",
                        "select" => [
                            "does_not_equal" => "保留"
                        ]
                    ],
                    [
                        "property" => "Product",
                        "select" => [
                            "does_not_equal" => "セキュリティ"
                        ]
                    ]
                ],
            ],
        ];
    }

    private function getStatusIcon($status)
    {
        return match ($status) {
            'リリース済' => ":white_check_mark:",
            'QA対応中' => ":rocket:",
            '開発中' => ":construction:",
            '開発スタンバイ' => ":construction:",
            default => ":question:",
        };
    }

    private function getStatusPriority($status)
    {
        return match ($status) {
            'リリース済' => 1,
            'QA対応中' => 2,
            '開発中' => 3,
            '開発スタンバイ' => 4,
            default => 5,
        };
    }
}
