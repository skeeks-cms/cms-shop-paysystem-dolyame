<?php
return [
    'components' => [
        'shop' => [
            'paysystemHandlers' => [
                'dolyame' => [
                    'class' => \skeeks\cms\shop\dolyame\DolyamePaysystemHandler::class
                ]
            ],
        ],

        'log' => [
            'targets' => [
                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['info', 'warning', 'error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\dolyame\DolyamePaysystemHandler::class, \skeeks\cms\shop\dolyame\controllers\DolyameController::class],
                    'logFile'    => '@runtime/logs/dolyame-info.log',
                ],

                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\dolyame\DolyamePaysystemHandler::class, \skeeks\cms\shop\dolyame\controllers\DolyameController::class],
                    'logFile'    => '@runtime/logs/dolyame-errors.log',
                ],
            ],
        ],
    ],
];