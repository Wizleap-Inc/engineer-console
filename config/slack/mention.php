<?php

return [
    /*
    | メンション検知対象（このIDへのメンションが含まれるメッセージを通知の対象にする）
    | 例: @engineer_executive
    */
    'engineer_executive' => env('SLACK_MENTION_ENGINEER_EXECUTIVE', 'S033HEA941W'),

    /*
    | 通知の宛先（このIDにメンションして通知を送る）
    | 例: @engineer_question
    */
    'engineer_question' => env('SLACK_MENTION_ENGINEER_QUESTION', 'S0AAR1Q838X'),
];
