<?php

return [
    'bootstrap' => [\skeeks\cms\proposal\ProposalBootstrap::class],

    'components' => [
        // The client page of a proposal by the personal link of a sending (controllers\ProposalController).
        'urlManager' => [
            'rules' => [
                '~kp/<code:[A-Za-z0-9_-]{16,64}>' => 'cmsProposal/proposal/view',
                '~kp/<code:[A-Za-z0-9_-]{16,64}>/<action:(seen|accept|ask|pdf)>' => 'cmsProposal/proposal/<action>',
            ],
        ],
        'backendAdmin' => [
            'menu' => [
                'data' => [
                    'crm' => [
                        'items' => [
                            'proposals' => [
                                'label' => 'Предложения',
                                'priority' => 170,
                                'img' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/receipt.svg'],
                                'url' => ['cmsProposal/admin-cms-proposal'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'modules' => [
        'cmsProposal' => [
            'class' => \skeeks\cms\proposal\ProposalModule::class,
        ],
    ],
];
