<?php
/**
 * Shared configuration of commercial proposals.
 */

use skeeks\cms\proposal\log\ProposalLogRenderer;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\send\ProposalSendableSource;
use skeeks\cms\proposal\source\ProposalClientSource;

return [
    'components' => [
        'skeeks' => [
            // Proposals in the activity feeds of leads, companies and deals.
            'logTypes' => [CmsProposal::LOG_TYPE => 'Предложения'],
            'logRenderers' => [CmsProposal::LOG_TYPE => ProposalLogRenderer::class],
            'activityLogTypes' => [CmsProposal::LOG_TYPE],
            // "Создать КП" from a lead, company or deal: ?source=proposal-client:lead.15 in the standard create window.
            'recordSources' => [ProposalClientSource::TYPE => ProposalClientSource::class],
        ],
        // "Отправить клиенту" and "Прикрепить из… → Предложение" of skeeks/cms-communication.
        'communication' => [
            'sendables' => [ProposalSendableSource::TYPE => ProposalSendableSource::class],
        ],
    ],

    // Read tools of proposals for skeeks/cms-mcp (inert without it): mcp\ProposalReadToolProvider.
    'params' => ['cmsMcp' => [
        'toolProviders' => [
            'proposal' => \skeeks\cms\proposal\mcp\ProposalReadToolProvider::class,
            // Presentations created and edited by API clients (scope cms.proposal.write).
            'proposal-actions' => \skeeks\cms\proposal\mcp\ProposalActionToolProvider::class,
        ],
        'historyLogDescribers' => [CmsProposal::LOG_TYPE => \skeeks\cms\proposal\mcp\ProposalHistoryDescriber::class],
        'overviewContributors' => ['proposals' => \skeeks\cms\proposal\mcp\ProposalOverviewContributor::class],
        // The page of one sending is never shown to API clients: opening it would count as the client's opening.
        'personalLinkPatterns' => ['proposal' => '~/\~kp/~'],
    ]],
];
