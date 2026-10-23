<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\send;

use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendableSourceInterface;
use skeeks\cms\models\User;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\services\ProposalItems;
use yii\db\ActiveRecord;

/**
 * Proposals (КП and presentations) in "Отправить клиенту" and "Прикрепить
 * из… → Предложение" of
 * skeeks/cms-communication (`communication.sendables['proposal']`). An
 * employee may send every proposal they see (CmsProposalQuery::forManager()).
 */
class ProposalSendableSource implements SendableSourceInterface
{
    public const TYPE = 'proposal';

    public function title(): string
    {
        return 'Предложение';
    }

    public function find(int $id, User $user): ?SendableInterface
    {
        $proposal = CmsProposal::find()->cmsSite()->forManager($user)
            ->andWhere([CmsProposal::tableName().'.id' => $id])
            ->with(['currentVersion', 'lead', 'company', 'deal'])
            ->one();

        return $proposal ? new ProposalSendable($proposal) : null;
    }

    public function suggest(User $user, string $q, ?ActiveRecord $record = null, int $limit = 20): array
    {
        $t = CmsProposal::tableName();
        $query = CmsProposal::find()->cmsSite()->forManager($user)->search($q)
            ->with(['currentVersion', 'lead', 'company', 'deal'])
            ->orderBy([$t.'.id' => SORT_DESC])
            ->limit($limit);
        $proposals = $query->all();
        if ($record) {
            // The proposals of the card the letter is written from go first.
            $own = CmsProposal::find()->cmsSite()->forManager($user)->forRecord($record)->search($q)
                ->select($t.'.id')->column();
            $own = array_flip(array_map('intval', $own));
            usort($proposals, static fn(CmsProposal $a, CmsProposal $b) => (isset($own[(int)$b->id]) <=> isset($own[(int)$a->id])) ?: ((int)$b->id <=> (int)$a->id));
        }

        return array_map(static function (CmsProposal $proposal) {
            $hint = [$proposal->clientTypeName.' «'.$proposal->clientName.'»'];
            if ($proposal->isPresentation) {
                $hint[] = (string)parse_url((string)$proposal->target_url, PHP_URL_PATH) ?: (string)$proposal->target_url;
            } elseif ($proposal->currentVersion) {
                $hint[] = ProposalItems::money($proposal->currentVersion->amount, (string)$proposal->currency_code);
            }
            $hint[] = $proposal->displayStatusName;

            return ['id' => (int)$proposal->id, 'title' => $proposal->asText, 'hint' => implode(' · ', $hint)];
        }, $proposals);
    }
}
