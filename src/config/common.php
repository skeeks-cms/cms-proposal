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
];
