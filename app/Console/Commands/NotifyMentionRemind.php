<?php

namespace App\Console\Commands;

use App\Utils\SlackMessageReader;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class NotifyMentionRemind extends Command
{
    private const ENDPOINT_GET_PERMALINK = 'https://slack.com/api/chat.getPermalink';

    protected $signature = 'slack:notifyMentionRemind {--channel= : チャンネルキー（例: engineerGeneral）}';

    protected $description = '過去5分で @engineer_executive へメンションされたメッセージを @engineer_question にSlackリンク付きで通知する';

    public function handle()
    {
        $channelKey = $this->option('channel');
        if (empty($channelKey)) {
            $this->error('--channel を指定してください。');
            return 1;
        }

        $channelConfig = collect(config('slack.channels'))->first(fn($c) => $c['key'] === $channelKey);
        if (!$channelConfig) {
            $this->error("チャンネル '{$channelKey}' が見つかりません。");
            return 1;
        }

        $channelId = $channelConfig['channelId'];
        $webhookUrl = $channelConfig['webhookUrl'];

        if (empty($channelId) || empty($webhookUrl)) {
            $this->error('チャンネルの channelId または webhookUrl が設定されていません。');
            return 1;
        }

        $end = CarbonImmutable::now();
        $start = $end->minusMinutes(5);

        $reader = new SlackMessageReader($channelId);
        $reader->setRange($start, $end);

        $messages = $reader->getMessages();

        $mentionTargetId = config('slack.mention.engineer_executive');
        $mentionMessages = $messages->filter(function (array $msg) use ($mentionTargetId) {
            $text = $msg['text'] ?? '';
            return str_contains($text, $mentionTargetId);
        });

        if ($mentionMessages->isEmpty()) {
            $this->info('該当するメンションはありませんでした。');
            return 0;
        }

        $client = new Client();
        $token = env('SLACK_TOKEN');
        $permalinks = [];

        foreach ($mentionMessages as $msg) {
            $ts = $msg['ts'] ?? null;
            if (!$ts) {
                continue;
            }
            $response = $client->get(self::ENDPOINT_GET_PERMALINK, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['channel' => $channelId, 'message_ts' => $ts],
            ]);
            $body = json_decode($response->getBody(), true);
            if (!empty($body['ok']) && !empty($body['permalink'])) {
                $permalinks[] = $body['permalink'];
            }
        }

        if (empty($permalinks)) {
            $this->warn('permalink の取得に失敗しました。');
            return 1;
        }

        $notifyMentionId = config('slack.mention.engineer_question');
        $links = implode("\n", $permalinks);
        $text = sprintf(
            "<@%s> 過去5分間に @engineer_executive へのメンションが含まれるメッセージがありました:\n%s",
            $notifyMentionId,
            $links
        );

        Http::withHeaders(['Content-type' => 'application/json'])
            ->post($webhookUrl, ['text' => $text]);

        $this->info(count($permalinks) . ' 件のメッセージを @engineer_question に通知しました。');
        return 0;
    }
}
